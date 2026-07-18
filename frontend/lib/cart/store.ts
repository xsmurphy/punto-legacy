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
  /**
   * Metadata de EMISIÓN de gift card (F2 giftcard-issue-flow) — presente solo
   * en líneas de un item de catálogo kind="giftcard" agregado vía
   * `GiftcardIssueDialog` (ver lib/cart/giftcard-issue-store.ts). El backend
   * (SaleService::issueGiftCard) usa esto para crear la fila en la tabla
   * `giftcard`; su sola presencia también le dice a `pay-dialog.tsx` que el
   * documento fiscal de la venta debe ser Recibo (adelanto), no Factura.
   * NO confundir con el CANJE de una gift card existente como método de pago
   * (`giftcard-validation-dialog.tsx` / payment.type==="giftcard") — eso
   * sigue emitiendo Factura normalmente.
   */
  giftcard?: {
    code: string
    beneficiaryContactId?: string | null
    beneficiaryName?: string | null
    expiresAt?: string | null
    note?: string | null
  }
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
 * - ivaRemoved = false → qty * unitPrice * (1 - discount/100).
 * - ivaRemoved = true  → round(raw / 1.10) — precio sin IVA.
 *   Ej: 25.000 → 22.727, 10.000 → 9.091, 32.000 → 29.091. Suma = 60.909
 *   (coincide con selectCartTotal).
 * - discount (0–100): porcentaje de descuento por línea. Aplica antes del IVA.
 */
export function lineSubtotal(line: CartLine, ivaRemoved: boolean): number {
  const discountFactor = 1 - (line.discount ?? 0) / 100
  const raw = line.qty * line.unitPrice * discountFactor
  return ivaRemoved ? Math.round(raw / (1 + TAX_RATE)) : raw
}

/**
 * Subtotal de las líneas (post-descuentos de línea, pre-descuento de venta).
 * Es la base sobre la que se aplica saleDiscount.
 */
export const selectLinesSubtotal = (s: CartState): number =>
  s.lines.reduce((sum, line) => sum + lineSubtotal(line, s.ivaRemoved), 0)

/**
 * Monto en plata del descuento de venta. Calcula sobre selectLinesSubtotal:
 * - mode "percent": porcentaje del subtotal de líneas.
 * - mode "money": monto directo (capeado al subtotal de líneas).
 * Devuelve 0 si no hay saleDiscount activo.
 */
export const selectSaleDiscountAmount = (s: CartState): number => {
  if (!s.saleDiscount) return 0
  const base = selectLinesSubtotal(s)
  if (base === 0) return 0
  if (s.saleDiscount.mode === "money") {
    return Math.min(s.saleDiscount.value, base)
  }
  // percent: 0-100
  const pct = Math.min(100, Math.max(0, s.saleDiscount.value))
  return Math.round(base * pct / 100)
}

/**
 * Total del carrito. Suma de los subtotales por línea (idéntico cálculo que
 * `lineSubtotal`), así la suma del listado coincide con el total.
 * Resta el descuento de venta al final. Total mínimo = 0.
 *
 * Al desactivar `ivaRemoved`, vuelve al precio original porque `unitPrice` no
 * se muta — el cálculo es derivado.
 */
export const selectCartTotal = (s: CartState): number => {
  const linesTotal = selectLinesSubtotal(s)
  const saleDisc = selectSaleDiscountAmount(s)
  return Math.max(0, linesTotal - saleDisc)
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

  /** Nota libre a nivel carrito (ej. "pedido especial"). */
  note: string | null

  /**
   * ID de la lista de precios activa. Solo persiste el ID —
   * la lógica de resolución de precios es responsabilidad de fases posteriores.
   */
  priceListId: string | null

  /** Etiquetas de texto libre asociadas a la venta. */
  tags: string[]

  /**
   * Descuento a nivel venta (transactionDiscount). Se resuelve en plata en
   * selectSaleDiscountAmount y se resta al total en selectCartTotal. NO se
   * bakea en las líneas — siempre removible con clearSaleDiscount().
   */
  saleDiscount: { value: number; mode: "percent" | "money" } | null

  /**
   * ID de cotización padre. Cuando el cajero elige "Facturar" desde una
   * cotización (type=9), se setea acá para que el payload de la venta
   * incluya parentTransactionId y el backend pueda vincularlas.
   * Se resetea en clear().
   */
  quoteParentId: string | null

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
   * Cache local sincronizada por `PosConfigSync` desde `register.data.posConfig`
   * (server-state = fuente de verdad). Las mutaciones del AjustesPanel pasan
   * por `useUpdatePosConfig` → el bridge re-hidrata este flag.
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
   *
   * Caso especial `kind === "descuento"` (item POS que representa un
   * descuento del ticket, ver ITEM_KIND_CONFIG.descuento en
   * lib/types/item.ts): NO entra como línea de carrito — aplica su
   * `discountPercent` (itemDiscount del catálogo, %) como descuento de
   * venta (saleDiscount), el mismo mecanismo que ya usa
   * sale-options-drawer.tsx. Devuelve un discriminador para que el caller
   * decida el toast:
   * - "added": item normal, se agregó una línea.
   * - "discount-applied": item descuento con % configurado, se aplicó.
   * - "discount-missing": item descuento SIN % configurado en catálogo —
   *   el caller debe avisar al cajero (no hay línea ni descuento aplicado).
   */
  addItem: (item: {
    id: string
    name: string
    price: number
    kind?: string
    discountPercent?: number | null
  }) => "added" | "discount-applied" | "discount-missing"

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

  /** Modifica el precio unitario de una línea (sin mutar el precio base del catálogo). */
  setLinePrice: (lineId: string, price: number) => void

  /**
   * Aplica un descuento porcentual a una línea (0–100).
   * 0 elimina el descuento. El subtotal se recalcula via lineSubtotal.
   */
  setLineDiscount: (lineId: string, discountPercent: number) => void

  /** Asigna o quita un vendedor de una línea. null = quitar asignación. */
  setLineSeller: (lineId: string, sellerId: string | null) => void

  /** Setea la nota a nivel carrito. null = limpiar. */
  setNote: (note: string | null) => void

  /** Setea el ID de la lista de precios activa. null = sin lista. */
  setPriceListId: (id: string | null) => void

  /** Setea las etiquetas de la venta. */
  setTags: (tags: string[]) => void

  /** Limpia las etiquetas de la venta. */
  clearTags: () => void

  /** Setea el ID de cotización padre. null = limpiar. */
  setQuoteParent: (id: string | null) => void

  /**
   * Setea el descuento de venta (transactionDiscount). No toca las líneas.
   * El monto en plata se resuelve via selectSaleDiscountAmount y se resta
   * en selectCartTotal. Siempre removible con clearSaleDiscount().
   */
  setSaleDiscount: (value: number, mode: "percent" | "money") => void

  /** Elimina el descuento de venta. */
  clearSaleDiscount: () => void

  /**
   * @deprecated Usar setSaleDiscount. Mantenido como alias para compatibilidad
   * mientras no queden call-sites directos.
   */
  applyGlobalDiscount: (value: number, mode: "percent" | "money") => void

  /**
   * Pushea líneas al carrito sin clear. Si la última línea del array tiene
   * el mismo itemId que la nueva, incrementa qty en vez de duplicar.
   * Útil para "agregar desde historial de transacciones".
   */
  addLines: (lines: Omit<CartLine, "lineId">[]) => void
}

// ── Store ─────────────────────────────────────────────────────────────────────

const initialState = {
  lines: [] as CartLine[],
  selectedLineId: null as string | null,
  customer: null as PosCustomer | null,
  credito: false,
  interno: false,
  ivaRemoved: false,
  note: null as string | null,
  priceListId: null as string | null,
  mergeRepeated: true,
  tags: [] as string[],
  quoteParentId: null as string | null,
  saleDiscount: null as { value: number; mode: "percent" | "money" } | null,
}

export const useCartStore = create<CartState>()((set, _get) => ({
  ...initialState,

  addItem: (item) => {
    if (item.kind === "descuento") {
      // Item "descuento": no es una línea vendible — aplica su % de catálogo
      // como descuento de venta. Sin % configurado no hay nada que aplicar;
      // el caller (product-area/product-search/cart-panel) avisa al cajero.
      // Defense-in-depth: un discountPercent no-finito (NaN por dato corrupto
      // en catálogo, aunque el BFF ya lo filtra a null) nunca debe llegar a
      // saleDiscount — contaminaría selectSaleDiscountAmount/selectCartTotal.
      if (
        item.discountPercent == null ||
        !Number.isFinite(item.discountPercent) ||
        item.discountPercent <= 0
      ) {
        return "discount-missing"
      }
      set({
        saleDiscount: { value: Math.min(100, item.discountPercent), mode: "percent" },
      })
      return "discount-applied"
    }

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
    return "added"
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

  setLinePrice: (lineId, price) => {
    set((state) => ({
      lines: state.lines.map((l) =>
        l.lineId === lineId ? { ...l, unitPrice: price } : l,
      ),
    }))
  },

  setLineDiscount: (lineId, discountPercent) => {
    const clamped = Math.min(100, Math.max(0, discountPercent))
    set((state) => ({
      lines: state.lines.map((l) =>
        l.lineId === lineId
          ? { ...l, discount: clamped === 0 ? undefined : clamped }
          : l,
      ),
    }))
  },

  setLineSeller: (lineId, sellerId) => {
    set((state) => ({
      lines: state.lines.map((l) =>
        l.lineId === lineId ? { ...l, sellerId: sellerId ?? undefined } : l,
      ),
    }))
  },

  setNote: (note) => {
    set({ note })
  },

  setPriceListId: (id) => {
    set({ priceListId: id })
  },

  setTags: (tags) => {
    set({ tags })
  },

  clearTags: () => {
    set({ tags: [] })
  },

  setQuoteParent: (id) => {
    set({ quoteParentId: id })
  },

  setSaleDiscount: (value, mode) => {
    set({ saleDiscount: { value, mode } })
  },

  clearSaleDiscount: () => {
    set({ saleDiscount: null })
  },

  // @deprecated alias — redirige a setSaleDiscount
  applyGlobalDiscount: (value, mode) => {
    set({ saleDiscount: { value, mode } })
  },

  addLines: (lines) => {
    set((state) => {
      let current = [...state.lines]
      for (const line of lines) {
        const last = current.at(-1)
        // Líneas de emisión de gift card NUNCA se mergean: cada una tiene un
        // código/beneficiario/monto propios — sumar qty perdería esa
        // distinción (dos gift cards con códigos distintos colapsarían en
        // una sola línea con qty=2 y un solo código).
        const canMerge = last && last.itemId === line.itemId && !last.giftcard && !line.giftcard
        if (canMerge) {
          current = current.map((l) =>
            l === last ? { ...l, qty: l.qty + line.qty } : l,
          )
        } else {
          current = [...current, { ...line, lineId: crypto.randomUUID() }]
        }
      }
      return { lines: current }
    })
  },
}))

