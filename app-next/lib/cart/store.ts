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
}

/** Selector helper para el total — úsalo en componentes:
 *  const total = useCartStore(selectCartTotal)
 */
export const selectCartTotal = (s: CartState): number =>
  s.lines.reduce((sum, line) => sum + line.qty * line.unitPrice, 0)

/**
 * Selector del IVA contenido (Paraguay 10%: precios incluyen IVA).
 * Fórmula: iva = round(total / 11).
 * Si ivaRemoved=true, devuelve 0 (IVA informativo eliminado por el cajero).
 */
export const selectCartIva = (s: CartState): number => {
  if (s.ivaRemoved) return 0
  const total = s.lines.reduce((sum, line) => sum + line.qty * line.unitPrice, 0)
  return Math.round(total / 11)
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

  // ── Acciones ──────────────────────────────────────────────────────────────

  /**
   * Agrega un item al carrito.
   * Si ya existe una línea con el mismo itemId, incrementa la cantidad.
   * Si no existe, crea una línea nueva y la selecciona.
   */
  addItem: (item: { id: string; name: string; price: number }) => void

  /** Elimina una línea del carrito. */
  removeLine: (lineId: string) => void

  /** Incrementa la cantidad de una línea. */
  incQty: (lineId: string) => void

  /** Decrementa la cantidad. Si llega a 0, elimina la línea. */
  decQty: (lineId: string) => void

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
}

// ── Store ─────────────────────────────────────────────────────────────────────

const initialState = {
  lines: [] as CartLine[],
  selectedLineId: null as string | null,
  customer: null as PosCustomer | null,
  credito: false,
  interno: false,
  ivaRemoved: false,
}

export const useCartStore = create<CartState>()((set, _get) => ({
  ...initialState,

  addItem: (item) => {
    // Agregar NO selecciona la línea: por defecto la lista se ve compacta (solo
    // info del producto). Los controles/tools aparecen solo al click en la línea
    // (selectLine) y se ocultan al click afuera. Ver CartPanel.
    set((state) => {
      const existing = state.lines.find((l) => l.itemId === item.id)
      if (existing) {
        return {
          lines: state.lines.map((l) =>
            l.itemId === item.id ? { ...l, qty: l.qty + 1 } : l,
          ),
        }
      }
      const newLine: CartLine = {
        lineId: crypto.randomUUID(),
        itemId: item.id,
        name: item.name,
        qty: 1,
        unitPrice: item.price,
      }
      return {
        lines: [...state.lines, newLine],
      }
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
}))

