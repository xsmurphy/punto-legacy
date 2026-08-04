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
import { useCatalogStore } from "@/lib/catalog/store"
import type { Order, Fulfillment } from "@/hooks/use-orders"
import type { CustomerAddress } from "@/lib/types/contact"
import { lineGross, TAX_RATE as ALLOC_TAX_RATE } from "@/lib/cart/allocate-discounts"

// ── Tipos ─────────────────────────────────────────────────────────────────────

export interface CartLine {
  /** UUID generado client-side. Necesario para idempotencia offline. */
  lineId: string
  itemId: string
  name: string
  qty: number
  unitPrice: number
  /**
   * Precio de catálogo al momento del add (antes de resolución de lista de
   * precios). Base para volver a recalcular cuando cambia el contexto de
   * precio (cliente/lista) — ver `usePriceContext` en
   * `hooks/use-price-context.ts`. Líneas viejas (guardadas/parked antes de
   * este campo) no lo traen: se usa `unitPrice` como fallback de base.
   */
  basePrice?: number
  /**
   * true si el cajero editó el precio a mano (LinePriceDialog/setLinePrice).
   * `usePriceContext` nunca pisa el precio de una línea con este flag — un
   * override manual del cajero gana siempre sobre la resolución automática.
   */
  priceOverridden?: boolean
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
 * Cobro PARCIAL de una sesión de espacio — split de cuenta (context/15 §F3).
 *
 * Es el discriminador que le dice a `pay-dialog.tsx` que la venta que está
 * por confirmar NO es "la mesa entera" sino una parte: en vez de la rama
 * `sessionParentId` (markPaid de cada orden + close de la sesión), registra
 * el pago en el ledger vía `registerSessionPayment` y **el backend decide**
 * si el saldo llegó a 0 y corresponde liquidar (`settleIfCovered`). La UI no
 * cierra órdenes ni sesiones en un parcial.
 *
 * Mutuamente excluyente con `sessionParentId` y `orderParentId`. Se resetea
 * en `clear()` vía `initialState` — crítico: un intent que sobreviva al clear
 * haría que la SIGUIENTE venta normal se registre como parcial de una mesa
 * vieja, imputando plata a la cuenta equivocada.
 *
 * El monto real lo recalcula el backend en los tres casos (kind='items' desde
 * los precios persistidos, 'share' desde el total de la sesión); lo que viaja
 * acá es la ELECCIÓN del operador, no una cifra de autoridad.
 */
export type SettlementIntent =
  | { sessionId: string; kind: "items"; orderItemIds: string[] }
  | { sessionId: string; kind: "amount"; amount: number }
  | { sessionId: string; kind: "share"; shareCount: number; shareIndex: number }

/**
 * Tasa del IVA — reexportada desde `lib/cart/allocate-discounts.ts`, que es
 * donde vive la fórmula compartida con el payload de la venta. TODO: cuando el
 * catálogo exponga `taxRate` por item (y el config del tenant el modo
 * "incluido / no incluido"), derivarlo de ahí — soporta multi-tax y otros países.
 */
const TAX_RATE = ALLOC_TAX_RATE

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
  // Misma función que usa el payload (allocate-discounts.lineGross) — si los
  // dos lados redondearan por separado, lo cobrado y lo registrado divergirían.
  return lineGross(line.qty * line.unitPrice * discountFactor, ivaRemoved)
}

/**
 * Subtotal de TODAS las líneas (post-descuentos de línea, pre-descuento de
 * venta). Es lo que se muestra como subtotal del carrito.
 */
export const selectLinesSubtotal = (s: CartState): number =>
  s.lines.reduce((sum, line) => sum + lineSubtotal(line, s.ivaRemoved), 0)

/**
 * Líneas ELEGIBLES para un descuento de venta: las que no tienen descuento
 * propio. Un producto lleva un solo descuento (regla del owner).
 */
export function eligibleForSaleDiscount(lines: CartLine[]): CartLine[] {
  return lines.filter((l) => !l.discount)
}

/**
 * Líneas que el descuento de venta activo alcanza HOY: las que estaban en su
 * alcance al aplicarlo y siguen en el carrito sin descuento propio.
 */
export function linesCoveredBySaleDiscount(s: CartState): CartLine[] {
  if (!s.saleDiscount) return []
  const ids = new Set(s.saleDiscount.lineIds)
  return s.lines.filter((l) => ids.has(l.lineId) && !l.discount)
}

/**
 * Base del descuento de venta: subtotal de las líneas que alcanza, NO del
 * carrito entero. Lo agregado después de aplicarlo no entra.
 */
export const selectSaleDiscountBase = (s: CartState): number =>
  linesCoveredBySaleDiscount(s).reduce((sum, line) => sum + lineSubtotal(line, s.ivaRemoved), 0)

/**
 * Monto en plata del descuento de venta, sobre su base congelada:
 * - mode "percent": porcentaje de esa base.
 * - mode "money": monto directo (capeado a esa base).
 * Devuelve 0 si no hay saleDiscount activo o si ya no alcanza ninguna línea.
 */
export const selectSaleDiscountAmount = (s: CartState): number => {
  if (!s.saleDiscount) return 0
  const base = selectSaleDiscountBase(s)
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
   * ID de la lista de precios activa (elegida a mano desde
   * sale-options-drawer.tsx → PriceListDialog). `usePriceContext` la observa
   * junto con `customer` para resolver precios server-side vía
   * `/v1/price_resolve` — prioridad override de línea > lista manual > lista
   * del contacto > lista del outlet (resuelta en el backend).
   */
  priceListId: string | null

  /**
   * Nombre de la lista efectivamente aplicada por la última resolución
   * (puede ser la del contacto, no `priceListId` manual — el backend decide
   * la prioridad). Solo para mostrar en UI (CustomerChip/PriceListChip).
   * null cuando no hay contexto de precio o la resolución no encontró lista.
   */
  priceListName: string | null

  /** Etiquetas de texto libre asociadas a la venta. */
  tags: string[]

  /**
   * Descuento a nivel venta. Se resuelve en plata en selectSaleDiscountAmount y
   * se resta al total en selectCartTotal. NO se bakea en las líneas — siempre
   * removible con clearSaleDiscount().
   *
   * `lineIds` congela SU ALCANCE al momento de aplicarlo (reglas del owner,
   * 2026-07-30):
   *   1. Cubre las líneas que estaban en el carrito cuando se aplicó. Un
   *      producto agregado DESPUÉS no queda alcanzado — antes el porcentaje se
   *      recalculaba sobre el carrito entero en cada render, así que todo lo que
   *      entraba después se descontaba solo.
   *   2. Nunca cubre una línea que ya tiene descuento propio: un producto lleva
   *      UN descuento, no dos. Si a una línea alcanzada se le pone después un
   *      descuento individual, sale del alcance (ver setLineDiscount).
   */
  saleDiscount: { value: number; mode: "percent" | "money"; lineIds: string[] } | null

  /**
   * ID de cotización padre. Cuando el cajero elige "Facturar" desde una
   * cotización (type=9), se setea acá para que el payload de la venta
   * incluya parentTransactionId y el backend pueda vincularlas.
   * Se resetea en clear().
   */
  quoteParentId: string | null

  /**
   * Modo del carrito (context/24-orders-module-plan.md). "venta" (default) es
   * el flujo actual de cobro directo. "orden" arma una orden operativa (envía
   * a cocina, NO cobra) — el botón principal cambia de "Pagar" a "Ordenar".
   * La cotización sigue siendo una acción de guardado inmediato aparte, NO
   * un modo — no se toca su mecanismo acá.
   */
  posMode: "venta" | "orden" | "cotizacion"

  /**
   * ID de la orden padre (`pos_order`). Se setea vía `loadFromOrder()` cuando
   * el cajero elige "Cobrar" desde `/pos/ordenes` — el carrito se llena en
   * modo venta con el contenido de la orden y, al confirmar el cobro,
   * `pay-dialog.tsx` llama `OrderCoreService::markPaid()` con este id y el
   * transactionId resultante. Se resetea en clear() (mismo mecanismo que
   * quoteParentId).
   */
  orderParentId: string | null

  /**
   * Espacio seleccionado para tomar una orden (context/15-espacios-module-plan.md
   * F2). Se setea desde `/pos/espacios` al abrir un espacio libre o elegir
   * "Agregar orden" en un espacio ocupado — el carrito entra en modo "orden"
   * con esta sesión. Al Ordenar, `handleOrderClick` incluye
   * `spaceSessionId` en el payload (el backend fuerza `source='table'`).
   * `spaceName` es solo para el chip visual (evita un round-trip extra).
   * Se resetea en clear() — tras ordenar con éxito, la selección se limpia
   * y el cajero vuelve al mapa (decisión del owner).
   */
  spaceSessionId: string | null
  spaceName: string | null

  /**
   * Cómo llega la orden al cliente (context/27-delivery-sla-plan.md §B.1,
   * §D.4). Default "dine_in". Solo aplica a modo "orden" (una venta directa
   * no tiene fulfillment) — `handleOrderClick` la incluye en el payload de
   * `createOrder`. Invariantes de reset viven en las acciones (`setFulfillment`,
   * `setSelectedSpace`/`loadFromSession`, `setPosMode`) — no en la UI.
   */
  fulfillment: Fulfillment
  /**
   * Dirección de delivery elegida para ESTA orden (context/27 PARTE D). Se
   * setea junto con `fulfillment="delivery"` desde `DeliveryAddressDialog`.
   * null en cualquier otro fulfillment — `setFulfillment` lo garantiza.
   */
  deliveryAddress: CustomerAddress | null

  /**
   * Cobro de un espacio completo (context/15 F2): análogo a `orderParentId`
   * pero para VARIAS órdenes a la vez. Se setea vía `loadFromSession()`
   * cuando el cajero toca "Cobrar" en el sheet de un espacio ocupado — el
   * carrito se llena en modo venta con el merge de todas las órdenes no
   * cerradas/canceladas de la sesión. Al confirmar el cobro, `pay-dialog.tsx`
   * llama `markPaid()` por cada orderId de `sessionOrderIds` y luego
   * `SpaceSessionService::close()` con el transactionId resultante.
   * Mutuamente excluyente con `orderParentId` (una venta viene de una orden
   * sola o de un espacio completo, nunca ambas). Se resetea en clear().
   */
  sessionParentId: string | null
  sessionOrderIds: string[]

  /**
   * Cobro PARCIAL de una sesión (split de cuenta, context/15 §F3). Set vía
   * `loadForSettlement()`. Ver el docblock de `SettlementIntent` arriba: es
   * lo que hace que `pay-dialog.tsx` registre el pago en el ledger en vez de
   * cerrar la mesa. Mutuamente excluyente con `sessionParentId` /
   * `orderParentId`. Se resetea en clear().
   */
  settlementIntent: SettlementIntent | null

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

  /**
   * Modifica el precio unitario de una línea a mano (sin mutar el precio
   * base del catálogo). Marca `priceOverridden: true` — `usePriceContext`
   * nunca vuelve a pisar esta línea con una resolución automática hasta que
   * se agregue de nuevo (línea nueva).
   */
  setLinePrice: (lineId: string, price: number) => void

  /**
   * Aplica precios resueltos server-side (`/v1/price_resolve`, ver
   * `usePriceContext`) a las líneas no-overridden cuyo `itemId` está en el
   * mapa. Actualiza `unitPrice` y persiste `priceListName` para la UI.
   * Líneas con `priceOverridden: true` o `itemId` ausente del mapa quedan
   * intactas.
   */
  applyResolvedPrices: (
    resolved: Map<string, { price: number; priceListName: string | null }>,
  ) => void

  /**
   * Restaura `unitPrice = basePrice` en todas las líneas no-overridden y
   * limpia `priceListName`. Se llama cuando el contexto de precio desaparece
   * (cliente deseleccionado y sin lista manual) — ver `usePriceContext`.
   */
  restoreBasePrices: () => void

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
   * Cambia el modo del carrito. El store es config-agnóstico: NO sabe de
   * `modoSoloOrdenes` — el runtime (cart-panel / página del POS) decide si
   * debe re-lockear a "orden" después de un clear() cuando ese flag está
   * activo (ver context/24-orders-module-plan.md, decisión O1 punto 1).
   */
  setPosMode: (mode: "venta" | "orden" | "cotizacion") => void

  /**
   * Vuelca el contenido de una orden (`pos_order` + `pos_order_item`) al
   * carrito en modo venta — "cobrar una orden" es copiar su contenido al
   * carrito y facturar con el flujo normal (context/24, "UX — decisión clave
   * del owner"). Resuelve el cliente completo desde el catálogo (la orden
   * solo trae `customerId`). Ítems cancelados de la orden se excluyen.
   * Reemplaza el carrito entero (no hace merge con líneas existentes).
   */
  loadFromOrder: (order: Order) => void

  /**
   * Selecciona un espacio para tomar una orden (context/15 F2). Fuerza
   * posMode="orden". El id del espacio no se persiste en el store (solo se
   * necesita `sessionId` para el payload de create) — `spaceName` es para
   * el chip.
   */
  setSelectedSpace: (sessionId: string, spaceName: string) => void

  /** Quita el espacio seleccionado sin tocar el resto del carrito (deshacer selección). */
  clearSelectedSpace: () => void

  /**
   * Cambia el fulfillment de la orden en curso (context/27 §B.1). Cualquier
   * valor distinto de "delivery" limpia `deliveryAddress` — no puede quedar
   * una dirección elegida "colgada" si el cajero vuelve a "Mostrador"/"Retiro".
   */
  setFulfillment: (f: Fulfillment) => void

  /** Setea la dirección de delivery elegida para esta orden. null = quitarla. */
  setDeliveryAddress: (a: CustomerAddress | null) => void

  /**
   * Vuelca TODAS las órdenes no cerradas/canceladas de una sesión de espacio
   * al carrito en modo venta — "cobrar el espacio" (context/15 F2). Merge de
   * líneas de todas las órdenes (mismo criterio de merge que `addLines`:
   * mergea con la última línea si coincide itemId, preservando notas
   * distintas como líneas separadas). Setea `sessionParentId` +
   * `sessionOrderIds` (los orderId a marcar `markPaid` al confirmar el
   * cobro) — mutuamente excluyente con `orderParentId`. Reemplaza el
   * carrito entero.
   */
  loadFromSession: (sessionId: string, spaceName: string, orders: Order[]) => void

  /**
   * Vuelca al carrito UN COBRO PARCIAL de una sesión de espacio (split de
   * cuenta, context/15 §F3). Reemplaza el carrito entero, igual que
   * `loadFromSession`, pero setea `settlementIntent` en vez de
   * `sessionParentId`/`sessionOrderIds` — la diferencia es qué hace
   * `pay-dialog.tsx` DESPUÉS de la venta (registrar el pago en el ledger vs.
   * cerrar la mesa). El cobro de la mesa completa sigue usando
   * `loadFromSession`, sin cambios.
   *
   * Las líneas las arma el caller (`lib/spaces/settlement-lines.ts`) porque
   * dependen del modo: los ítems elegidos en kind='items', el reparto
   * proporcional del monto en 'amount'/'share'.
   */
  loadForSettlement: (
    spaceName: string,
    lines: Omit<CartLine, "lineId">[],
    intent: SettlementIntent,
  ) => void

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
  priceListName: null as string | null,
  mergeRepeated: true,
  tags: [] as string[],
  quoteParentId: null as string | null,
  saleDiscount: null as { value: number; mode: "percent" | "money"; lineIds: string[] } | null,
  posMode: "venta" as "venta" | "orden" | "cotizacion",
  orderParentId: null as string | null,
  spaceSessionId: null as string | null,
  spaceName: null as string | null,
  fulfillment: "dine_in" as Fulfillment,
  deliveryAddress: null as CustomerAddress | null,
  sessionParentId: null as string | null,
  sessionOrderIds: [] as string[],
  settlementIntent: null as SettlementIntent | null,
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
      set((state) => ({
        saleDiscount: {
          value: Math.min(100, item.discountPercent as number),
          mode: "percent",
          // Mismo congelamiento que setSaleDiscount: alcanza lo que hay ahora.
          lineIds: eligibleForSaleDiscount(state.lines).map((l) => l.lineId),
        },
      }))
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
        basePrice: item.price,
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
    // La dirección de envío pertenece al cliente que estaba cargado: si lo
    // quitan (X del chip) o lo cambian por otro, esa dirección deja de ser
    // válida — el backend la rechaza ('deliveryAddressId inválido para este
    // cliente') y el cajero vería un "no se pudo enviar la orden" opaco. Peor
    // sería que pasara: el pedido saldría a la casa del cliente anterior. Se
    // vuelve a "Mostrador" y el cajero re-elige el destino.
    set((state) =>
      state.deliveryAddress !== null && state.customer?.id !== customer?.id
        ? { customer, fulfillment: "dine_in" as Fulfillment, deliveryAddress: null }
        : { customer },
    )
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
        l.lineId === lineId ? { ...l, unitPrice: price, priceOverridden: true } : l,
      ),
    }))
  },

  applyResolvedPrices: (resolved) => {
    set((state) => {
      // Nombre de lista activa: sale SOLO de esta resolución. Si el backend no
      // aplicó ninguna lista (cliente sin lista, sin default de outlet), el
      // nombre queda null — arrastrar el anterior mostraba una lista que ya
      // no estaba aplicada.
      let activeName: string | null = null
      const lines = state.lines.map((l) => {
        if (l.priceOverridden) return l
        const r = resolved.get(l.itemId)
        if (!r) return l
        if (r.priceListName) activeName = r.priceListName
        // `basePrice` se fija ACÁ si la línea llegó sin él, ANTES de pisar
        // `unitPrice`. Es la barrera que hace estructuralmente imposible el
        // bucle de realimentación: sin esto, una línea sin base tomaba el
        // precio recién resuelto como su nueva base y cada ciclo de
        // `usePriceContext` le aplicaba el ajuste otra vez — una venta de
        // 60.000 caía a ~168 en unos segundos (reporte del owner 2026-08-04,
        // cobro en partes de un espacio). Los creadores de línea ya setean
        // basePrice; esto cubre cualquier camino nuevo que se olvide.
        const base = l.basePrice ?? l.unitPrice
        if (r.price === l.unitPrice && l.basePrice !== undefined) return l
        return { ...l, basePrice: base, unitPrice: r.price }
      })
      return { lines, priceListName: activeName }
    })
  },

  restoreBasePrices: () => {
    set((state) => ({
      lines: state.lines.map((l) =>
        l.priceOverridden || l.unitPrice === (l.basePrice ?? l.unitPrice)
          ? l
          : { ...l, unitPrice: l.basePrice ?? l.unitPrice },
      ),
      priceListName: null,
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
      // Un producto lleva UN descuento: al ponerle uno individual, la línea sale
      // del alcance del descuento de venta (y vuelve a entrar si se lo quitan,
      // solo si ya estaba en el alcance original).
      saleDiscount: state.saleDiscount,
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

  setPosMode: (mode) => {
    // Los flags fiscales/de cobro son de la VENTA, no de la orden: una orden
    // no se emite a crédito, no es "interna" (venta sin valor fiscal) y no
    // tiene IVA que quitar — se define todo recién al cobrarla. Además
    // `ivaRemoved` participa del total (lineSubtotal), así que si sobrevive
    // al cambio de modo el CTA "Ordenar" muestra un monto sin IVA que nadie
    // pidió. Se resetean acá (raíz) y no se renderizan en modo orden.
    //
    // Al volver a "venta": el fulfillment/dirección son atributos de la
    // ORDEN (context/27 §B.1) — una venta directa de mostrador no tiene
    // fulfillment, así que se resetean igual que credito/interno/ivaRemoved
    // arriba (mismo criterio, atributo que no aplica al modo destino).
    // "cotizacion" (sticky desde 2026-07-30, mismo mecanismo que orden): una
    // cotización tampoco se emite a crédito ni es interna ni quita IVA — esos
    // atributos se definen recién si la cotización se convierte en venta.
    set(
      mode === "orden" || mode === "cotizacion"
        ? { posMode: mode, credito: false, interno: false, ivaRemoved: false }
        : { posMode: mode, fulfillment: "dine_in", deliveryAddress: null },
    )
  },

  loadFromOrder: (order) => {
    const { customers } = useCatalogStore.getState()
    const customer = order.customerId
      ? (customers.find((c) => c.id === order.customerId) ?? null)
      : null

    const newLines: CartLine[] = (order.items ?? [])
      .filter((oi) => oi.status !== "cancelled")
      .map((oi) => ({
        lineId: crypto.randomUUID(),
        itemId: oi.itemId ?? "",
        name: oi.name,
        qty: oi.qty,
        unitPrice: oi.price ?? 0,
        // INVARIANTE: toda línea nace con `basePrice`. Sin él, `usePriceContext`
        // cae a `unitPrice` como base y el precio ya resuelto se realimenta:
        // resolver → unitPrice baja → cambia el lineKey → vuelve a resolver
        // sobre el precio YA descontado. Ver applyResolvedPrices.
        basePrice: oi.price ?? 0,
        note: oi.note ?? undefined,
      }))

    set({
      ...initialState,
      lines: newLines,
      customer,
      note: order.note ?? null,
      posMode: "venta",
      orderParentId: order.id,
    })
  },

  setSelectedSpace: (sessionId, spaceName) => {
    // Un espacio es dine_in por construcción (context/27 §B.1, mismo criterio
    // que el backend forzando source='table') — una mesa no pide delivery.
    set({
      spaceSessionId: sessionId,
      spaceName,
      posMode: "orden",
      fulfillment: "dine_in",
      deliveryAddress: null,
    })
  },

  clearSelectedSpace: () => {
    set({ spaceSessionId: null, spaceName: null })
  },

  setFulfillment: (f) => {
    // Invariante acá (no en la UI, context/27): cualquier valor distinto de
    // "delivery" limpia la dirección — no puede quedar una dirección elegida
    // colgada si el cajero vuelve a "Mostrador"/"Retiro".
    set({ fulfillment: f, deliveryAddress: f === "delivery" ? _get().deliveryAddress : null })
  },

  setDeliveryAddress: (a) => {
    set({ deliveryAddress: a })
  },

  loadFromSession: (sessionId, spaceName, orders) => {
    const { customers } = useCatalogStore.getState()

    const billable = orders.filter(
      (o) => o.status !== "closed" && o.status !== "cancelled",
    )

    // Cliente: si todas las órdenes billable comparten el mismo customerId,
    // se hereda; si difiere entre rondas, queda sin cliente (el cajero lo
    // asigna a mano — no hay "cliente del espacio" en el modelo de datos).
    const customerIds = new Set(billable.map((o) => o.customerId).filter(Boolean))
    const customerId = customerIds.size === 1 ? [...customerIds][0] : null
    const customer = customerId
      ? (customers.find((c) => c.id === customerId) ?? null)
      : null

    let newLines: CartLine[] = []
    for (const order of billable) {
      const orderLines: Omit<CartLine, "lineId">[] = (order.items ?? [])
        .filter((oi) => oi.status !== "cancelled")
        .map((oi) => ({
          itemId: oi.itemId ?? "",
          name: oi.name,
          qty: oi.qty,
          unitPrice: oi.price ?? 0,
          // Mismo invariante que loadFromOrder: sin `basePrice` el precio
          // resuelto se realimenta y se descuenta una vez por ciclo.
          basePrice: oi.price ?? 0,
          note: oi.note ?? undefined,
        }))
      for (const line of orderLines) {
        const last = newLines.at(-1)
        // Mismo criterio de merge que addLines: solo mergea con la ÚLTIMA
        // línea si coincide itemId Y nota (notas distintas = personas/rondas
        // distintas, no se deben mezclar en una sola línea).
        if (last && last.itemId === line.itemId && last.note === line.note) {
          newLines = newLines.map((l) =>
            l === last ? { ...l, qty: l.qty + line.qty } : l,
          )
        } else {
          newLines = [...newLines, { ...line, lineId: crypto.randomUUID() }]
        }
      }
    }

    set({
      ...initialState,
      lines: newLines,
      customer,
      posMode: "venta",
      sessionParentId: sessionId,
      sessionOrderIds: billable.map((o) => o.id),
      spaceName,
    })
  },

  loadForSettlement: (spaceName, lines, intent) => {
    set({
      ...initialState,
      lines: lines.map((l) => ({ ...l, lineId: crypto.randomUUID() })),
      posMode: "venta",
      spaceName,
      settlementIntent: intent,
    })
  },

  setSaleDiscount: (value, mode) => {
    // El alcance se congela ACÁ: las líneas presentes y sin descuento propio.
    set((state) => ({
      saleDiscount: {
        value,
        mode,
        lineIds: eligibleForSaleDiscount(state.lines).map((l) => l.lineId),
      },
    }))
  },

  clearSaleDiscount: () => {
    set({ saleDiscount: null })
  },

  // @deprecated alias — redirige a setSaleDiscount
  applyGlobalDiscount: (value, mode) => {
    set((state) => ({
      saleDiscount: {
        value,
        mode,
        lineIds: eligibleForSaleDiscount(state.lines).map((l) => l.lineId),
      },
    }))
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

