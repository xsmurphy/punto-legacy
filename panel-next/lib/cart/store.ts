/**
 * Store del carrito de venta (Zustand).
 *
 * Maneja el estado local del carrito — líneas, selección, flags de modo
 * y cliente. Toda la lógica de mutación es síncrona (sin side-effects):
 * el commit real al backend se hace desde `lib/commands/create-sale.ts`.
 *
 * Ciclo de vida:
 *   1. El cajero agrega items desde el catálogo → `addItem`.
 *   2. Selecciona una línea → `selectLine` (muestra controles +/−).
 *   3. Ajusta cantidades / agrega notas.
 *   4. Cobra → `lib/commands/createSale` → `clear`.
 *
 * Para el total, computarlo en el componente desde `lines`:
 *   const lines = useCartStore(s => s.lines)
 *   const total = lines.reduce((s,l) => s + l.qty * l.unitPrice, 0)
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A.
 */

import { create } from "zustand"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"

// ── Tipos ─────────────────────────────────────────────────────────────────────

export interface CartLine {
  /** UUID generado client-side. Necesario para idempotencia offline. */
  lineId: string
  itemId: string
  name: string
  qty: number
  unitPrice: number
  note?: string
  /** ID del vendedor asignado a esta línea (stub — sin UI aún). */
  sellerId?: string
  /**
   * Descuento aplicado a la línea (porcentaje). El borde izquierdo del row
   * se pone amarillo cuando es > 0 (espejo del b-l b-3x b-warning del legacy).
   * UI de modificación: TODO Slice posterior (modal numpad con %).
   */
  discount?: number
  /**
   * Tags / etiquetas asignadas a la línea (ids de taxonomy). Se renderizan
   * como un icono <Tag /> debajo del nombre cuando hay al menos 1.
   * UI de modificación: TODO Slice posterior (drawer con autocomplete).
   */
  tags?: string[]
}

/**
 * Tasa del IVA. Por ahora hardcodeada al modelo paraguayo (10% incluido en
 * precio final del item). TODO: cuando el catálogo exponga `taxRate` por item
 * (y el config del tenant exponga el modo "incluido / no incluido"), derivarlo
 * de ahí — soporta multi-tax y otros países.
 */
const TAX_RATE = 0.10

/**
 * Subtotal de una línea ajustado por el flag `ivaRemoved`. El cálculo vive
 * acá (no en el componente) para que el listado de líneas y el total siempre
 * usen la misma regla — si están desincronizados, la suma de líneas no
 * coincide con el total.
 *
 * - ivaRemoved = false → qty * unitPrice (precio con IVA incluido).
 * - ivaRemoved = true  → round(qty * unitPrice / 1.10) — precio sin IVA.
 *   Ej: 25.000 → 22.727, 10.000 → 9.091, 32.000 → 29.091. Suma = 60.909
 *   (coincide con selectCartTotal).
 */
export function lineSubtotal(line: CartLine, ivaRemoved: boolean): number {
  const raw = line.qty * line.unitPrice
  return ivaRemoved ? Math.round(raw / (1 + TAX_RATE)) : raw
}

/**
 * Total del carrito. Suma de los subtotales por línea (idéntico cálculo que
 * `lineSubtotal`), así la suma del listado coincide con el total.
 *
 * Al desactivar `ivaRemoved`, vuelve al precio original porque `unitPrice` no
 * se muta — el cálculo es derivado.
 */
export const selectCartTotal = (s: CartState): number => {
  return s.lines.reduce((sum, line) => sum + lineSubtotal(line, s.ivaRemoved), 0)
}

/**
 * IVA contenido en la venta (informativo del chip "Gs <iva>").
 * Si ivaRemoved=true, devuelve 0 (el cajero acaba de removerlo).
 * Si no, IVA = total * rate / (1+rate) — para 10%: total/11.
 */
export const selectCartIva = (s: CartState): number => {
  if (s.ivaRemoved) return 0
  const totalWithTax = s.lines.reduce(
    (sum, line) => sum + line.qty * line.unitPrice,
    0,
  )
  return Math.round((totalWithTax * TAX_RATE) / (1 + TAX_RATE))
}

interface CartState {
  lines: CartLine[]
  selectedLineId: string | null
  customer: PosCustomer | null

  /** Venta a crédito (type 3). Si false → contado (type 0). */
  credito: boolean
  /** Venta interna (consumo propio, sin factura fiscal). */
  interno: boolean
  /**
   * IVA eliminado por el cajero (informativo).
   * Cuando es true, selectCartIva devuelve 0. El total NO cambia.
   */
  ivaRemoved: boolean

  /**
   * Controla cómo se agrupan los ítems repetidos al agregar al carrito.
   *
   * - true (default): suma cantidad solo si el ítem nuevo coincide con el
   *   ÚLTIMO ítem agregado (la última línea del array). Si entre medio se
   *   agregó otro ítem, se crea una línea nueva — útil para ventas normales
   *   donde el cajero va sumando el mismo producto varias veces seguidas.
   * - false: siempre crea una línea nueva, independientemente de si ya hay
   *   otras líneas del mismo ítem — útil para promos con descuento por línea
   *   (ej. 2x1 donde cada línea lleva un descuento diferente).
   *
   * TODO (F2): persistir mergeRepeated en register.data del backend para que
   * sobreviva recargas y se sincronice entre dispositivos de la misma caja.
   */
  mergeRepeated: boolean

  // ── Acciones ──────────────────────────────────────────────────────────────

  /**
   * Agrega un item al carrito.
   *
   * El comportamiento depende del flag `mergeRepeated`:
   * - true: suma cantidad si el ítem coincide con el ÚLTIMO del array; si no,
   *   crea línea nueva.
   * - false: siempre crea una línea nueva.
   */
  addItem: (item: { id: string; name: string; price: number }) => void

  /** Elimina una línea del carrito. */
  removeLine: (lineId: string) => void

  /** Incrementa la cantidad de una línea. */
  incQty: (lineId: string) => void

  /** Decrementa la cantidad. Si llega a 0, elimina la línea. */
  decQty: (lineId: string) => void

  /** Fija la cantidad absoluta de una línea (numpad). 0 o negativo → elimina. */
  setQty: (lineId: string, qty: number) => void

  /** Selecciona una línea (muestra controles +/−). Null = deseleccionar. */
  selectLine: (lineId: string | null) => void

  /** Vacía el carrito completo. */
  clear: () => void

  /** Asigna el cliente de la venta. */
  setCustomer: (customer: PosCustomer | null) => void

  /** Alterna el flag de venta a crédito. */
  toggleCredito: () => void

  /** Alterna el flag de venta interna. */
  toggleInterno: () => void

  /** Alterna el flag informativo de IVA removido. */
  toggleIva: () => void

  /** Actualiza la nota de una línea. */
  setLineNote: (lineId: string, note: string) => void

  /** Fija el flag de agrupado de ítems repetidos. */
  setMergeRepeated: (v: boolean) => void
}

// ── Store ─────────────────────────────────────────────────────────────────────

const initialState = {
  lines: [] as CartLine[],
  selectedLineId: null as string | null,
  customer: null as PosCustomer | null,
  credito: false,
  interno: false,
  ivaRemoved: false,
  mergeRepeated: true,
}

export const useCartStore = create<CartState>()((set, _get) => ({
  ...initialState,

  addItem: (item) => {
    // Agregar NO selecciona la línea: por defecto la lista se ve compacta (solo
    // info del producto). Los controles/tools aparecen solo al click en la línea
    // (selectLine) y se ocultan al click afuera. Ver CartPanel.
    set((state) => {
      const newLine = (): CartLine => ({
        lineId: crypto.randomUUID(),
        itemId: item.id,
        name: item.name,
        qty: 1,
        unitPrice: item.price,
      })

      if (!state.mergeRepeated) {
        // Siempre crear línea nueva — útil para promos con descuento por línea.
        return { lines: [...state.lines, newLine()] }
      }

      // mergeRepeated=true: sumar solo si el ítem coincide con el ÚLTIMO del array.
      // Si B rompe la cadena A-A, el próximo A crea una línea nueva.
      const lastLine = state.lines.at(-1)
      if (lastLine && lastLine.itemId === item.id) {
        return {
          lines: state.lines.map((l) =>
            l.lineId === lastLine.lineId ? { ...l, qty: l.qty + 1 } : l,
          ),
        }
      }

      return { lines: [...state.lines, newLine()] }
    })
  },

  removeLine: (lineId) => {
    set((state) => {
      const remaining = state.lines.filter((l) => l.lineId !== lineId)
      // Si se elimina la línea activa, volver al estado default (sin selección),
      // no saltar a otra línea.
      const nextSelected =
        state.selectedLineId === lineId ? null : state.selectedLineId
      return { lines: remaining, selectedLineId: nextSelected }
    })
  },

  incQty: (lineId) => {
    set((state) => ({
      lines: state.lines.map((l) =>
        l.lineId === lineId ? { ...l, qty: l.qty + 1 } : l,
      ),
    }))
  },

  decQty: (lineId) => {
    set((state) => {
      const line = state.lines.find((l) => l.lineId === lineId)
      if (!line) return state
      if (line.qty <= 1) {
        const remaining = state.lines.filter((l) => l.lineId !== lineId)
        const nextSelected =
          state.selectedLineId === lineId ? null : state.selectedLineId
        return { lines: remaining, selectedLineId: nextSelected }
      }
      return {
        lines: state.lines.map((l) =>
          l.lineId === lineId ? { ...l, qty: l.qty - 1 } : l,
        ),
      }
    })
  },

  setQty: (lineId, qty) => {
    set((state) => {
      // qty ≤ 0 → eliminar la línea (consistente con decQty).
      if (qty <= 0) {
        const remaining = state.lines.filter((l) => l.lineId !== lineId)
        const nextSelected =
          state.selectedLineId === lineId ? null : state.selectedLineId
        return { lines: remaining, selectedLineId: nextSelected }
      }
      return {
        lines: state.lines.map((l) =>
          l.lineId === lineId ? { ...l, qty } : l,
        ),
      }
    })
  },

  selectLine: (lineId) => {
    set({ selectedLineId: lineId })
  },

  clear: () => {
    set({ ...initialState })
  },

  setCustomer: (customer) => {
    set({ customer })
  },

  toggleCredito: () => {
    set((state) => ({ credito: !state.credito }))
  },

  toggleInterno: () => {
    set((state) => ({ interno: !state.interno }))
  },

  toggleIva: () => {
    set((state) => ({ ivaRemoved: !state.ivaRemoved }))
  },

  setLineNote: (lineId, note) => {
    set((state) => ({
      lines: state.lines.map((l) =>
        l.lineId === lineId ? { ...l, note } : l,
      ),
    }))
  },

  setMergeRepeated: (v) => {
    set({ mergeRepeated: v })
  },
}))

