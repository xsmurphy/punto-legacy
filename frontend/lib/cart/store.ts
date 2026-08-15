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
import { allocateLineDiscounts, lineGross, TAX_RATE as ALLOC_TAX_RATE } from "@/lib/cart/allocate-discounts"
import { computeTaxes, type TaxKind } from "@/lib/tax/engine"

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
   * Etiquetas de línea — texto libre (nombres, no IDs), mismo catálogo de
   * sugerencias que las etiquetas de VENTA (`useTags()`). Uso interno: salen
   * en comandas, nunca en facturas (pedido owner 2026-08-14). Se renderizan
   * como un icono <Tag /> debajo del nombre cuando hay al menos 1. UI de
   * modificación: setLineTags + LineTagsDialog (tile "Etiquetas" del
   * actionsheet de línea, cart-panel.tsx).
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
  /**
   * Metadata de CANJE de voucher (F2, context/36-vouchers-plan.md) — presente
   * en las líneas que trajo `applyVoucher()` al validar un código. Mismo
   * patrón que `giftcard` arriba, pero es el REVERSO: acá la línea representa
   * un ítem que YA fue pagado al emitirse el vale, no un pago nuevo.
   *
   * `lineSubtotal` devuelve 0 para toda línea con `voucher` — explícito, NO vía
   * `discount: 100` (decisión del plan: la cobertura tiene que quedar tipada
   * como "vale", nunca como descuento, o los reportes de descuento mostrarían
   * promociones que no existieron). Estas líneas tampoco entran en
   * `eligibleForSaleDiscount` — descontar algo que ya aporta 0 no tiene sentido
   * y descuadra el prorrateo del descuento de venta.
   *
   * `voucherId` identifica el vale para `removeVoucher()` (quita TODAS las
   * líneas de un mismo vale de una sola vez — es la única forma de deshacer,
   * no hay borrado de línea individual).
   */
  voucher?: {
    voucherId: string
    code: string
  }
  /**
   * Impuesto del ítem al momento de agregarse (F2b, context/38) — se busca
   * en `useCatalogStore.taxes` por este id dentro de `selectCartIva`. `null`
   * = sin taxId (ítem sin impuesto en catálogo, o línea armada por un flujo
   * que todavía no lo propaga — ver `loadFromOrder`/`applyVoucher`/
   * `GiftcardIssueDialog`). El motor trata esa línea como exenta, NUNCA
   * inventa una tasa.
   */
  taxId?: string | null
  /**
   * Override de "precio incluye impuesto" del ítem al momento de agregarse.
   * `null` = sin override → `selectCartIva` cae al default de la sucursal
   * (`useCatalogStore.outletTaxIncluded`). Mismo criterio que
   * `PosItem.taxIncluded` / `SaleService::enrichWithTaxes`.
   */
  taxIncluded?: boolean | null
  /**
   * Add-ons elegidos para esta línea (F4, context/41). Ausente/vacío = línea
   * sin add-ons, el 100% del tráfico de un comercio que no usa la feature.
   *
   * DOS invariantes que no se pueden romper:
   *
   * 1. `unitPrice` YA incluye la suma de `priceDelta * qty` de estas
   *    selecciones (ver `addonsDelta`). No es una optimización: el server NO
   *    deriva el total de la venta del detalle —lo toma del `subtotal` que
   *    informa el cliente— así que si el delta no está en el precio de la
   *    línea, el add-on se vende gratis (ver el docblock de
   *    `SaleService::expandAddonSelections`, F3).
   * 2. `name`/`priceDelta` son COPIA para RENDER (la fila del carrito, la
   *    venta aparcada que se retoma sin red). El server revalida optionId,
   *    mínimos/máximos y recalcula el delta contra la BD al vender — nunca
   *    confía en estos valores, y el payload solo le manda `optionId` + `qty`.
   */
  selections?: CartLineAddon[]
}

/** Una opción de add-on elegida en una línea del carrito (F4, context/41). */
export interface CartLineAddon {
  /** `addon_group_option.optionId` — lo ÚNICO, con `qty`, que viaja al server. */
  optionId: string
  /** Cuántas veces se eligió la opción (entero ≥ 1, tope `maxQty` del grupo). */
  qty: number
  /** Producto real detrás de la opción. Informativo en el front. */
  itemId: string
  /** Copia del nombre para render offline. */
  name: string
  /** Copia del recargo unitario para render offline. 0 = no suma. */
  priceDelta: number
}

/**
 * Recargo total de los add-ons de una línea (por unidad del producto padre).
 * Es lo que `unitPrice` lleva sumado sobre el precio base — ver
 * `CartLine.selections`.
 */
export function addonsDelta(selections: CartLineAddon[] | undefined): number {
  if (!selections || selections.length === 0) return 0
  return selections.reduce((sum, s) => sum + s.priceDelta * s.qty, 0)
}

/**
 * Firma estable de las selecciones de una línea — clave de IDENTIDAD del
 * producto en el carrito junto con `itemId`.
 *
 * Dos líneas del mismo ítem con selecciones DISTINTAS son líneas distintas
 * (una hamburguesa con queso extra no es la misma que una sin). Con la misma
 * firma sí se colapsan sumando cantidad, que es el comportamiento que el
 * cajero ya espera de `mergeRepeated`.
 *
 * Ordenada por optionId: el orden en que el cajero tocó las opciones no puede
 * cambiar la identidad de la línea.
 */
export function selectionsKey(selections: CartLineAddon[] | undefined): string {
  if (!selections || selections.length === 0) return ""
  return selections
    .map((s) => `${s.optionId}:${s.qty}`)
    .sort()
    .join(",")
}

/** Un ítem del vale, tal como lo devuelve `POST /v1/vouchers?resource=validate`. */
export interface VoucherRedeemItem {
  itemId: string
  name: string
  qty: number
  unitPriceAtIssue: number
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
 * Tasa de IVA hardcodeada (10%, modelo paraguayo) — reexportada desde
 * `lib/cart/allocate-discounts.ts`. F2b (context/38) migró el IVA
 * INFORMATIVO del carrito (`selectCartIva`) al motor real
 * (`lib/tax/engine.ts`, por ítem/tasa del catálogo) — esta constante ya NO
 * se usa para eso.
 *
 * Sigue viva para dos usos que NO tocamos en este slice porque afectan lo
 * que se COBRA, no lo que se muestra (ver `lineGross` arriba y
 * `create-sale.ts:299`): el neteo cuando el cajero activa "quitar IVA"
 * (`ivaRemoved`). Ambos asumen precio de LISTA con impuesto incluido al
 * 10% — mueren recién cuando el modo "impuesto añadido"/multi-tasa llegue al
 * PRECIO del carrito, no solo a su display (F3+, fuera de alcance acá).
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
 * - voucher: SIEMPRE 0, sin mirar precio/descuento — el vale ya se cobró al
 *   emitirse (context/36, decisión 5). Explícito y primero en la función para
 *   que quede imposible que un discount accidental en una línea de vale
 *   termine sumando algo al total.
 */
export function lineSubtotal(line: CartLine, ivaRemoved: boolean): number {
  if (line.voucher) return 0
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
 *
 * Las líneas de vale quedan afuera: ya aportan 0 al subtotal (lineSubtotal),
 * así que "descontarlas" no tiene sentido y solo descuadraría el prorrateo
 * (peso de la línea en `allocateLineDiscounts` en base a un importe que la
 * UI ya muestra en 0).
 */
export function eligibleForSaleDiscount(lines: CartLine[]): CartLine[] {
  return lines.filter((l) => !l.discount && !l.voucher)
}

/**
 * Líneas que el descuento de venta activo alcanza HOY: las que estaban en su
 * alcance al aplicarlo y siguen en el carrito sin descuento propio.
 *
 * `!l.voucher` es defensa en profundidad: `lineIds` se congela desde
 * `eligibleForSaleDiscount` (que ya excluye vale), así que una línea de vale
 * no debería poder entrar acá — pero si algún caller futuro arma `lineIds` a
 * mano, esta función igual no la deja pasar.
 */
export function linesCoveredBySaleDiscount(s: CartState): CartLine[] {
  if (!s.saleDiscount) return []
  const ids = new Set(s.saleDiscount.lineIds)
  return s.lines.filter((l) => ids.has(l.lineId) && !l.discount && !l.voucher)
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
 * IVA contenido en la venta (informativo del chip "Gs <iva>"). F2b
 * (context/38 §Arquitectura→D): muere el TAX_RATE hardcodeado — cada línea
 * se calcula con SU tasa real (`useCatalogStore.taxes`, buscada por
 * `line.taxId`) y SU modo incluido/añadido (`line.taxIncluded` o el default
 * de la sucursal), vía el mismo motor (`lib/tax/engine.ts`) que el backend
 * usa para congelar el impuesto al confirmar la venta — el chip deja de
 * divergir del monto que realmente persiste.
 *
 * Lee el catálogo con `getState()` (no un hook): mismo patrón síncrono que
 * `loadFromOrder` más abajo. NO es un fetch — es memoria ya hidratada por
 * el bootstrap; el carrito es offline-first, nunca golpea red acá.
 *
 * ivaRemoved=true → 0 directo: equivale a correr el motor con cada línea en
 * `taxKind: "exempt"` (tax siempre 0 ahí), pero sin el trabajo — mismo
 * criterio que `SaleService::enrichWithTaxes` cuando ivaRemoved.
 *
 * Línea sin `taxId`, o con un `taxId` que no matchea ninguna tasa del
 * catálogo (línea armada por un flujo que todavía no la propaga, o tasa
 * borrada del catálogo después de agregada) → exenta, monto 0. Nunca se
 * inventa una tasa (mismo fallback fiscal que `deriveTaxRateKindFromName`
 * en el backend).
 */
export const selectCartIva = (s: CartState): number => {
  if (s.ivaRemoved) return 0

  const { taxes, outletTaxIncluded, config } = useCatalogStore.getState()
  const taxesById = new Map(taxes.map((t) => [t.id, t]))
  // PY: 0 decimales: MX y demás LATAM con centavos: 2 — mismo criterio que
  // SaleService::currencyDecimals() (D1, context/38).
  const decimals = config?.decimal === "yes" ? 2 : 0

  // Reusa la MISMA función que arma el payload de venta (create-sale.ts) para
  // el descuento por línea (propio + prorrateo del descuento de venta) — así
  // el `discount` que ve el motor acá es EXACTAMENTE el `totalDiscount` que
  // el backend va a recibir y congelar, no una aproximación aparte que
  // pudiera desviarse del monto real cobrado.
  const allocations = allocateLineDiscounts(s.lines, s.saleDiscount, false)

  const engineLines = s.lines.map((line, i) => {
    const tax = line.taxId ? taxesById.get(line.taxId) : undefined
    // Canje de voucher: exenta, igual que SaleService::enrichWithTaxes — la
    // línea no aporta al total (lineSubtotal=0) y su IVA ya se devengó en la
    // venta que emitió el vale; mostrarlo acá duplicaría el impuesto del chip.
    const exempt = Boolean(line.voucher)
    return {
      qty: line.qty,
      unitPrice: line.unitPrice,
      discount: allocations[i].totalDiscount,
      taxRate: exempt ? 0 : (tax?.rate ?? 0),
      taxKind: (exempt ? "exempt" : (tax?.kind ?? "exempt")) as TaxKind,
      taxIncluded: line.taxIncluded ?? outletTaxIncluded,
    }
  })

  return computeTaxes(engineLines, { decimals }).totals.tax
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
    /** F2b (context/38): impuesto del ítem — ver `CartLine.taxId/taxIncluded`. */
    taxId?: string | null
    taxIncluded?: boolean | null
    /**
     * F4 (context/41): add-ons elegidos en `<AddonPickerDialog>`. `price` sigue
     * siendo el precio BASE del catálogo — el store le suma los deltas para
     * armar `unitPrice` (ver `CartLine.selections`), así ningún caller puede
     * mandar un precio ya sumado y duplicar el recargo.
     */
    selections?: CartLineAddon[]
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

  /** Actualiza las etiquetas de una línea (uso interno — comandas, no facturas). */
  setLineTags: (lineId: string, tags: string[]) => void

  /**
   * Reemplaza los add-ons de una línea (F4, context/41) — lo que confirma
   * `<AddonPickerDialog>` cuando se reabre desde el carrito.
   *
   * Ajusta `unitPrice` por DIFERENCIA (saca el delta viejo, suma el nuevo) en
   * vez de recomponerlo desde `basePrice`: así funciona igual sobre una línea
   * con precio editado a mano (`priceOverridden`) o re-cotizada por lista de
   * precios, sin pisar ninguna de esas resoluciones.
   */
  setLineSelections: (lineId: string, selections: CartLineAddon[]) => void

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

  /**
   * Aplica un vale validado (F2, context/36-vouchers-plan.md): agrega UNA
   * línea por cada ítem del vale, con `qty` y precio CONGELADOS
   * (`unitPriceAtIssue`) y `voucher` seteado. NUNCA se fusiona con líneas
   * existentes del mismo itemId — si el cajero ya había cargado 2 cafés a
   * mano, después de aplicar el vale se ven 4 líneas (deliberado: el cajero
   * borra las suyas si se pasó, ver `removeVoucher`).
   *
   * No-op si el vale ya está aplicado (mismo `voucherId`) — evita canjear el
   * mismo código dos veces en el mismo carrito.
   */
  applyVoucher: (voucher: {
    voucherId: string
    code: string
    items: VoucherRedeemItem[]
  }) => void

  /**
   * Quita TODAS las líneas de un vale de una sola vez. Es la ÚNICA forma de
   * deshacer un vale aplicado — las líneas de vale no se editan ni se borran
   * de a una (qty/precio bloqueados, sin botón de quitar individual).
   */
  removeVoucher: (voucherId: string) => void
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
      // F4 (context/41): el precio de la línea es base + recargos. `basePrice`
      // queda en el precio PELADO de catálogo — es la referencia que
      // `usePriceContext` re-cotiza contra la lista de precios, y sobre esa
      // resolución se vuelven a sumar los deltas (ver applyResolvedPrices).
      const selections = item.selections && item.selections.length > 0 ? item.selections : undefined
      const delta = addonsDelta(selections)
      const newLine = (): CartLine => ({
        lineId: crypto.randomUUID(),
        itemId: item.id,
        name: item.name,
        qty: 1,
        unitPrice: item.price + delta,
        basePrice: item.price,
        taxId: item.taxId ?? null,
        taxIncluded: item.taxIncluded ?? null,
        ...(selections ? { selections } : {}),
      })

      if (!state.mergeRepeated) {
        // Siempre crear línea nueva — útil para promos con descuento por línea.
        return { lines: [...state.lines, newLine()] }
      }

      // mergeRepeated=true: sumar solo si el ítem coincide con el ÚLTIMO del array.
      // Si B rompe la cadena A-A, el próximo A crea una línea nueva.
      // `!lastLine.voucher`: si la última línea es de un vale (qty/precio
      // bloqueados, aporta 0 al total), clickear el mismo producto NO puede
      // sumarle cantidad a esa línea — el cajero cargaría un ítem pago
      // creyendo que agregó uno, y en realidad infló silenciosamente la
      // cantidad "gratis" del vale. Crea una línea nueva, como si no hubiera
      // coincidido.
      // La identidad de la línea incluye sus add-ons (F4, context/41): el
      // mismo producto con selecciones distintas son líneas DISTINTAS —
      // sumarle cantidad a la anterior cambiaría en silencio lo que se prepara
      // en cocina. Misma selección exacta sí colapsa, como cualquier repetido.
      const lastLine = state.lines.at(-1)
      if (
        lastLine &&
        lastLine.itemId === item.id &&
        !lastLine.voucher &&
        selectionsKey(lastLine.selections) === selectionsKey(selections)
      ) {
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
    set((state) => {
      const line = state.lines.find((l) => l.lineId === lineId)
      // Vale: qty bloqueada (mismo backstop que setLinePrice/setLineDiscount)
      // — el número de ítems es el que trajo el vale, no algo que el cajero
      // ajuste con el stepper. La UI ya deshabilita el botón (qtyLocked en
      // cart-panel.tsx); esto cubre cualquier otro caller.
      if (line?.voucher) return state
      return {
        lines: state.lines.map((l) =>
          l.lineId === lineId ? { ...l, qty: l.qty + 1 } : l,
        ),
      }
    })
  },

  decQty: (lineId) => {
    set((state) => {
      const line = state.lines.find((l) => l.lineId === lineId)
      if (!line) return state
      if (line.voucher) return state // bloqueada — ver incQty
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
      const line = state.lines.find((l) => l.lineId === lineId)
      if (line?.voucher) return state // bloqueada — ver incQty
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

  setLineTags: (lineId, tags) => {
    set((state) => ({
      lines: state.lines.map((l) =>
        l.lineId === lineId ? { ...l, tags } : l,
      ),
    }))
  },

  setLineSelections: (lineId, selections) => {
    set((state) => ({
      lines: state.lines.map((l) => {
        if (l.lineId !== lineId) return l
        const next = selections.length > 0 ? selections : undefined
        const unitPrice = l.unitPrice - addonsDelta(l.selections) + addonsDelta(next)
        return { ...l, selections: next, unitPrice }
      }),
    }))
  },

  setMergeRepeated: (v) => {
    set({ mergeRepeated: v })
  },

  setLinePrice: (lineId, price) => {
    set((state) => ({
      // Precio bloqueado en líneas de vale (congelado al emitir, context/36
      // decisión 6) — la UI ya no ofrece el control, esto es el backstop en
      // el store para que ningún caller lo pise por otra vía.
      lines: state.lines.map((l) =>
        l.lineId === lineId && !l.voucher
          ? { ...l, unitPrice: price, priceOverridden: true }
          : l,
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
        // Vale: precio CONGELADO al emitir (unitPriceAtIssue) — la resolución
        // de lista de precios NUNCA lo pisa, mismo criterio que
        // priceOverridden. Sin este guard, una línea de vale que comparte
        // itemId con un producto de catálogo se re-cotizaría a la lista
        // activa y dejaría de representar lo que el cliente pagó al comprar
        // el vale (context/36, decisión 6).
        if (l.priceOverridden || l.voucher) return l
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
        // F4 (context/41): la lista de precios re-cotiza el producto BASE; los
        // recargos de los add-ons se vuelven a sumar encima. Sin esto, resolver
        // precios borraba los add-ons del total y se vendían gratis.
        const delta = addonsDelta(l.selections)
        const base = l.basePrice ?? l.unitPrice - delta
        if (r.price + delta === l.unitPrice && l.basePrice !== undefined) return l
        return { ...l, basePrice: base, unitPrice: r.price + delta }
      })
      return { lines, priceListName: activeName }
    })
  },

  restoreBasePrices: () => {
    set((state) => ({
      lines: state.lines.map((l) => {
        // El precio base al que se vuelve incluye los add-ons de la línea
        // (F4, context/41) — quitar el contexto de precio no puede regalar el
        // recargo que el cajero cargó.
        const target = (l.basePrice ?? l.unitPrice - addonsDelta(l.selections)) + addonsDelta(l.selections)
        return l.priceOverridden || l.unitPrice === target ? l : { ...l, unitPrice: target }
      }),
      priceListName: null,
    }))
  },

  setLineDiscount: (lineId, discountPercent) => {
    const clamped = Math.min(100, Math.max(0, discountPercent))
    set((state) => ({
      // Vale: aporta 0 al total por construcción (lineSubtotal) — ponerle un
      // descuento propio no tiene efecto en la plata pero sí confundiría el
      // "% propio" que se imprime en la fila. Bloqueado, mismo criterio que
      // setLinePrice.
      lines: state.lines.map((l) =>
        l.lineId === lineId && !l.voucher
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
    const { customers, items: catalogItems } = useCatalogStore.getState()
    const customer = order.customerId
      ? (customers.find((c) => c.id === order.customerId) ?? null)
      : null

    const newLines: CartLine[] = (order.items ?? [])
      .filter((oi) => oi.status !== "cancelled")
      .map((oi) => {
        // `OrderItem` no viaja con datos de impuesto: se re-resuelven del
        // catálogo por itemId al reconstruir la línea. Sin esto, el chip de
        // IVA de una orden/mesa retomada mostraba 0 (selectCartIva trata la
        // línea sin taxId como exenta). Solo afecta el display — el backend
        // congela el impuesto real al persistir igual (enrichWithTaxes).
        const cat = oi.itemId ? catalogItems.find((ci) => ci.id === oi.itemId) : undefined
        return {
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
          tags: oi.tags ?? undefined,
          taxId: cat?.taxId ?? null,
          taxIncluded: cat?.taxIncluded ?? null,
        }
      })

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
    const { customers, items: catalogItems } = useCatalogStore.getState()

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
        .map((oi) => {
          // Igual que loadFromOrder: OrderItem no trae impuesto — se
          // re-resuelve del catálogo para que el chip de IVA de la mesa no
          // muestre 0. Display only; el backend congela el real al cobrar.
          const cat = oi.itemId ? catalogItems.find((ci) => ci.id === oi.itemId) : undefined
          return {
            itemId: oi.itemId ?? "",
            name: oi.name,
            qty: oi.qty,
            unitPrice: oi.price ?? 0,
            // Mismo invariante que loadFromOrder: sin `basePrice` el precio
            // resuelto se realimenta y se descuenta una vez por ciclo.
            basePrice: oi.price ?? 0,
            note: oi.note ?? undefined,
            tags: oi.tags ?? undefined,
            taxId: cat?.taxId ?? null,
            taxIncluded: cat?.taxIncluded ?? null,
          }
        })
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
        // una sola línea con qty=2 y un solo código). Mismo criterio para
        // vale (context/36 "Líneas propias, NO fusionadas"): fusionar en
        // silencio haría imposible ver qué parte del carrito cubre el vale.
        const canMerge =
          last &&
          last.itemId === line.itemId &&
          !last.giftcard &&
          !line.giftcard &&
          !last.voucher &&
          !line.voucher
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

  applyVoucher: (voucher) => {
    set((state) => {
      // Ya aplicado — no-op. El código se valida contra el backend antes de
      // llegar acá; lo único que falta cubrir es el doble-click / reintento
      // sobre el mismo vale ya canjeado en este carrito.
      if (state.lines.some((l) => l.voucher?.voucherId === voucher.voucherId)) {
        return state
      }
      const voucherLines: CartLine[] = voucher.items.map((it) => ({
        lineId: crypto.randomUUID(),
        itemId: it.itemId,
        name: it.name,
        qty: it.qty,
        unitPrice: it.unitPriceAtIssue,
        // basePrice = mismo valor: invariante "toda línea nace con basePrice"
        // (33b050e7) — sin esto, usePriceContext la trataría como sin base y
        // la resolución de precios la pisaría con la lista activa, lo que no
        // tiene sentido para un precio ya congelado al emitir el vale.
        basePrice: it.unitPriceAtIssue,
        voucher: { voucherId: voucher.voucherId, code: voucher.code },
      }))
      return { lines: [...state.lines, ...voucherLines] }
    })
  },

  removeVoucher: (voucherId) => {
    set((state) => {
      const remaining = state.lines.filter((l) => l.voucher?.voucherId !== voucherId)
      const removedSelected =
        state.selectedLineId !== null &&
        !remaining.some((l) => l.lineId === state.selectedLineId)
      return {
        lines: remaining,
        selectedLineId: removedSelected ? null : state.selectedLineId,
      }
    })
  },
}))

