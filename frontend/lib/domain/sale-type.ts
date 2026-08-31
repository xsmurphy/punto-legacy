/**
 * Tipos de transacción (`transaction.transactionType`) — fuente de verdad del
 * lado TS.
 *
 * El dominio lo define `api/lib/Sales/SaleType.php`: este archivo es su espejo,
 * y `lib/__tests__/sale-type.test.ts` lee ese enum del filesystem y falla si
 * aparece un caso que acá no está. Existe porque el vocabulario estaba
 * triplicado —el enum PHP, un mapa parcial de 9 casos escondido dentro de
 * `components/domain/transactions/transactions-list.tsx`, y enteros mágicos
 * (`tx.transactionType === 3`) esparcidos por el panel— y las tres copias ya
 * habían divergido entre sí.
 *
 * El valor viaja como NUMBER (así lo devuelve la API y así lo guarda la BD).
 * Los helpers aceptan `number | string` porque varios call-sites vienen de
 * estado de UI que ya es string (el `value` de un `<Select>`, por ejemplo).
 */

export const SaleType = {
  /** Venta al contado */
  Cashsale: 0,
  /** Compra al contado */
  CashPurchase: 1,
  /** Venta guardada */
  Saved: 2,
  /** Venta a crédito */
  Creditsale: 3,
  /** Compra a crédito */
  CreditPurchase: 4,
  /** Pago de créditos (recibo) */
  CreditPayment: 5,
  /** Devolución */
  Return: 6,
  /** Venta anulada */
  Canceled: 7,
  /** Venta recursiva */
  Recurring: 8,
  /** Presupuesto / cotización */
  Quote: 9,
  /** Delivery / remisión */
  Delivery: 10,
  /** Abrir espacio */
  OpenTable: 11,
  /** Orden (KDS) */
  Order: 12,
  /** Agendado (sesiones) */
  Schedule: 13,
  /** Nota de crédito de compra (el proveedor nos acredita/devuelve) */
  PurchaseCreditNote: 14,
} as const

export type SaleType = (typeof SaleType)[keyof typeof SaleType]

/**
 * Etiquetas en español, tal como las ve el usuario.
 *
 * Los 9 primeros valores se copiaron EXACTOS del mapa que vivía en
 * `transactions-list.tsx` — son UI en producción y este archivo es un refactor,
 * no una recopia.
 *
 * `OpenTable` (11) y `Order` (12) SÍ se corrigieron, el 2026-08-31. El mapa
 * viejo etiquetaba el 12 como "Mesa" aunque en el enum PHP el 12 es la orden
 * del KDS y el espacio abierto es el 11 — la etiqueta estaba puesta sobre el
 * valor equivocado. Al principio se conservó para no mover UI conocida; la
 * directiva del owner de sacar "mesa" del vocabulario del sistema (el módulo
 * no es solo gastronómico: el espacio puede ser una silla de atención, un box
 * o un puesto) obligó a tocar las dos etiquetas igual, así que se aprovechó
 * para dejarlas donde corresponde: 11 = "Espacio abierto", 12 = "Orden".
 */
export const SALE_TYPE_LABELS: Record<SaleType, string> = {
  [SaleType.Cashsale]: "Contado",
  [SaleType.CashPurchase]: "Compra al contado",
  [SaleType.Saved]: "Guardado",
  [SaleType.Creditsale]: "Crédito",
  [SaleType.CreditPurchase]: "Compra a crédito",
  [SaleType.CreditPayment]: "Recibo",
  [SaleType.Return]: "Devolución",
  [SaleType.Canceled]: "Anulada",
  [SaleType.Recurring]: "Recurrente",
  [SaleType.Quote]: "Cotización",
  [SaleType.Delivery]: "Remisión",
  [SaleType.OpenTable]: "Espacio abierto",
  [SaleType.Order]: "Orden",
  [SaleType.Schedule]: "Cita",
  [SaleType.PurchaseCreditNote]: "Nota de crédito de compra",
}

/** Normaliza a un valor del dominio; `null` si no es un tipo conocido. */
export function toSaleType(type: number | string | null | undefined): SaleType | null {
  if (type === null || type === undefined || type === "") return null
  const n = typeof type === "number" ? type : Number(type)
  if (!Number.isInteger(n)) return null
  return (n in SALE_TYPE_LABELS ? n : null) as SaleType | null
}

/** Etiqueta del tipo, o `null` si es desconocido — para el call-site que
 *  prefiere su propio fallback. */
export function saleTypeLabelOrNull(type: number | string | null | undefined): string | null {
  const t = toSaleType(type)
  return t === null ? null : SALE_TYPE_LABELS[t]
}

/** Etiqueta del tipo. Fallback `Tipo N` — un tipo que la API devuelve y acá no
 *  está es un bug, y mostrarlo crudo es mejor que ocultarlo. */
export function saleTypeLabel(type: number | string | null | undefined): string {
  return saleTypeLabelOrNull(type) ?? `Tipo ${type}`
}

// ── Predicados ────────────────────────────────────────────────────────────────
// Reemplazan las comparaciones con enteros mágicos que estaban inline en el
// panel. El nombre dice la intención; el número queda en un solo lugar.

/** Venta al contado. */
export function isCashSale(type: number | string | null | undefined): boolean {
  return toSaleType(type) === SaleType.Cashsale
}

/** Venta a crédito (la deuda vive aparte, en `transactionComplete`). */
export function isCreditSale(type: number | string | null | undefined): boolean {
  return toSaleType(type) === SaleType.Creditsale
}

/** Devolución de mercadería. */
export function isReturn(type: number | string | null | undefined): boolean {
  return toSaleType(type) === SaleType.Return
}

/** Recibo de pago de crédito. */
export function isReceipt(type: number | string | null | undefined): boolean {
  return toSaleType(type) === SaleType.CreditPayment
}

/**
 * Venta anulada por el patrón `void` de VENTAS. No cubre el soft-void de
 * compras/notas de crédito (`transactionStatus = 6`, correlativo conservado —
 * ver `context/40-anulacion-y-nota-credito.md`): eso es estado, no tipo, y el
 * call-site tiene que chequearlo aparte.
 */
export function isVoided(type: number | string | null | undefined): boolean {
  return toSaleType(type) === SaleType.Canceled
}

/** Cotización / presupuesto. No es documento fiscal. */
export function isQuote(type: number | string | null | undefined): boolean {
  return toSaleType(type) === SaleType.Quote
}

/** Venta facturada: contado o crédito. Es el par que comparte numeración
 *  fiscal y del que la UI muestra la "condición". */
export function isInvoicedSale(type: number | string | null | undefined): boolean {
  const t = toSaleType(type)
  return t === SaleType.Cashsale || t === SaleType.Creditsale
}

/** Compra a proveedor: contado o crédito. */
export function isPurchase(type: number | string | null | undefined): boolean {
  const t = toSaleType(type)
  return t === SaleType.CashPurchase || t === SaleType.CreditPurchase
}

/**
 * Tipos cuyo contenido (ítems) admite edición: venta contado, crédito o
 * cotización. Es SOLO el criterio por tipo — el estado del documento se chequea
 * aparte en cada call-site (una venta anulada no se edita aunque sea contado).
 */
export function isEditableSale(type: number | string | null | undefined): boolean {
  return isInvoicedSale(type) || isQuote(type)
}

/**
 * Tipos de venta que efectivamente devuelve
 * `/v1/reports/transactions?view=detail` (`TransactionsService::TX_TYPES =
 * '0,3,6,7,8'`) — mismo universo que pinta la columna "Tipo" de esa tabla, así
 * el filtro nunca ofrece una opción que no puede aparecer en la vista.
 */
export const REPORT_DETAIL_SALE_TYPES: SaleType[] = [
  SaleType.Cashsale,
  SaleType.Creditsale,
  SaleType.Return,
  SaleType.Canceled,
  SaleType.Recurring,
]
