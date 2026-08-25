"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api, type HttpClient } from "@/lib/api-client"
import type { OrderStatus } from "@/hooks/use-orders"

/**
 * Hook genérico para reports: fetcher GET con date range + extra params.
 * Cada report tiene su propio endpoint `/v1/reports/<name>.php` con shape
 * crudo (la mayoría devuelve `{ rows: [...] }`). El componente del report
 * tipa el resultado con su propio type.
 */
export function useReport<T>(
  name: string,
  opts: {
    from?: string
    to?: string
    params?: Record<string, string>
    enabled?: boolean
    /**
     * Cliente HTTP a usar — define el REALM y por lo tanto el scope de sucursal.
     * Default `api` (panel: cookie + view-scope del selector del logo). Los
     * call-sites del POS DEBEN pasar `posApi`: con el cliente del panel, el
     * listado de la caja mostraba la sucursal elegida en el PANEL (o "Todas"),
     * no la del device (bug 2026-07-30, /pos > transacciones).
     */
    client?: HttpClient
  },
) {
  const params = new URLSearchParams()
  if (opts.from) params.set("from", opts.from)
  if (opts.to) params.set("to", opts.to)
  if (opts.params) {
    Object.entries(opts.params).forEach(([k, v]) => {
      if (v !== "" && v !== undefined) params.set(k, v)
    })
  }
  const qs = params.toString()
  const client = opts.client ?? api
  return useQuery<T>({
    // El realm entra a la queryKey: el mismo reporte pedido como panel y como
    // device son datasets distintos (scope de sucursal distinto) y no deben
    // pisarse en cache.
    queryKey: [
      "reports",
      name,
      opts.from ?? "",
      opts.to ?? "",
      JSON.stringify(opts.params ?? {}),
      client === api ? "panel" : "pos",
    ],
    queryFn: () => client.get<T>(`/v1/reports/${name}${qs ? `?${qs}` : ""}`),
    staleTime: 60 * 1000, // 1 min — reports cambian con transacciones nuevas
    enabled: opts.enabled ?? true,
    retry: false,
  })
}

// ── Shapes de los reports que consumimos en frontend ──────────────────────

/**
 * Fila del endpoint /v1/reports/drawers. Espejo del shape que devuelve
 * `DrawersService::listMovements` (los IDs ya vienen resueltos a
 * outletName / registerName / openUserName / closeUserName).
 */
export interface DrawerRow {
  drawerId: string
  outletName: string
  registerName: string
  openDate: string
  closeDate: string | null
  openAmount: number | string | null
  closeAmount: number | string | null
  openUserName: string
  closeUserName: string
  isClosed: boolean
  /** Componentes de venta del período de la caja (calculados server-side). */
  sold: number | string
  /** Parte de `sold` cobrada en efectivo — la única que entra al cajón. */
  cashSold: number | string
  expense: number | string
  income: number | string
  return: number | string

  // ── Cuadre del arqueo (mig 164) ───────────────────────────────────────────
  // El backend resuelve el veredicto porque es el único que conoce la
  // tolerancia del comercio; el front no recalcula nada.
  /** Efectivo que debía haber en el cajón. `null` si no se pudo determinar. */
  expectedAmount: number | string | null
  /**
   * `frozen`    — congelado al cerrar: el número que el cajero tenía delante.
   * `estimated` — cierre anterior a la mig 164, recalculado ahora (se marca en la UI).
   * `live`      — caja todavía abierta: el "debería haber" del momento.
   */
  expectedSource: "frozen" | "estimated" | "live" | null
  /** `closeAmount − expectedAmount`. Negativo = falta plata. */
  difference: number | string | null
  /** Veredicto de `Reports\CashCountStatus`. */
  cashStatus: "ok" | "short" | "over" | "unknown"
}

/** Respuesta del endpoint /v1/reports/drawers (lista). */
export interface DrawersReportResponse {
  rows: DrawerRow[]
  /** Margen con el que se clasificó el cuadre, en moneda del tenant. */
  tolerance: number
}

/**
 * Una fila del arqueo por medio de pago de un cierre (mig 169): lo que se
 * esperaba de ese medio, lo que el cajero declaró haber contado y el veredicto.
 */
export interface DrawerCountRow {
  key: string
  name: string
  isCash: boolean
  /** `null` = no se pudo congelar el esperado de ese medio. Nunca es cero. */
  expected: number | string | null
  counted: number | string
  difference: number | string | null
  status: "ok" | "short" | "over" | "unknown"
  /**
   * `frozen` = filas escritas por el cierre. `estimated` = el cierre es
   * anterior a la mig 169 (o lo hizo el panel, que solo pide el efectivo) y lo
   * único reconstruible es la fila del cajón: los demás medios NO se muestran
   * en cero, porque nadie los contó.
   */
  source: "frozen" | "estimated"
}

/**
 * Detalle de UNA caja (`GET /v1/reports/drawers?id=`). Trae lo mismo que la
 * fila del listado más el desglose por medio de pago: `payments` (lo que se
 * vendió por cada medio) y `countByMethod` (lo que se arqueó por cada medio).
 */
export interface DrawerDetail extends DrawerRow {
  payments: { type: string; label: string; price: number | string }[]
  countByMethod: DrawerCountRow[]
}

/**
 * El detalle de una caja, pedido solo cuando hay una seleccionada.
 *
 * El endpoint existía desde siempre y ningún cliente lo llamaba: el modal de
 * detalle renderizaba la fila que ya tenía del listado, así que el desglose por
 * medio de pago que el backend calculaba no se veía en ninguna parte. Con el
 * arqueo por medio (mig 169) ese desglose es el contenido principal del
 * informe, así que ahora sí se pide.
 */
export function useDrawerDetail(drawerId: string | null) {
  return useReport<{ detail: DrawerDetail; tolerance: number }>("drawers", {
    params: drawerId ? { id: drawerId } : undefined,
    enabled: !!drawerId,
  })
}

/**
 * Fila del endpoint /v1/reports/transactions?view=detail. El backend resuelve
 * los IDs a nombres (customerName/userName/outletName/registerName) y agrega
 * los componentes financieros calculados (subtotal/tax/discount/total).
 */
export interface TransactionRow {
  transactionId: string
  date: string
  dueDate: string
  customerName: string
  customerTIN: string
  userName: string
  outletName: string
  registerName: string
  docNo: string
  invoiceNo: number | string | null
  authNo: number | string | null
  payments: Array<{ name: string }>
  note: string
  tags: string[]
  transactionType: number
  transactionComplete: 0 | 1
  topay: number
  netTotal: number
  discount: number
  subtotal: number
  tax: number
  totalGravado: number
  total: number
  /**
   * Venta emitida sin IVA (toggle del POS, mig 101). Cuando es true, `tax` y
   * `totalGravado` vienen en 0: los importes ya son netos y no hay base gravada.
   */
  ivaRemoved?: boolean
  // F1 facturación electrónica — null = tenant sin FE o venta no encolada
  // (no es un error, simplemente no aplica). Ver TransactionsService::einvoiceInfo.
  einvoiceStatus: "pending" | "sending" | "issued" | "error" | "cancelled" | "skipped" | null
  einvoiceCdc: string | null
  einvoiceError: string | null
}

export interface TransactionsReportResponse {
  rows: TransactionRow[]
}

/**
 * Fila del endpoint /v1/reports/products (view=general). Cada row es un item
 * con su agregado de ventas + metadata (name, sku, brand, category, etc.).
 */
export interface ProductRow {
  id: string
  name: string
  sku: string
  brand: string
  category: string
  itemType: string
  price: number
  taxName: string
  deleted: boolean
  usold: number
  total: number
  tax: number
  cogs: number
  comission: number
  discount: number
  utility?: number
}

export interface ProductsReportResponse {
  rows: ProductRow[]
  month: boolean
}

/**
 * Fila del endpoint /v1/reports/products?view=detail. Una fila por línea de
 * venta (no agregada) — ítem, comprobante, cliente, usuario, sucursal/caja.
 * `itemId` viaja crudo (puede ser "" en líneas manuales sin ítem asociado)
 * para poder linkear cada fila al historial del artículo.
 */
export interface ProductDetailRow {
  transactionId: string
  itemSoldId: string
  itemId: string
  outletName: string
  registerName: string
  invoiceNo: string
  userName: string
  customerName: string
  date: string
  name: string
  deleted: boolean
  sku: string
  brand: string
  category: string
  usold: number
  comission: number
  cogs: number
  tax: number
  taxName: string
  discount: number
  utility: number
  total: number
}

export interface ProductsDetailReportResponse {
  rows: ProductDetailRow[]
}

/**
 * Endpoint /v1/reports/open_invoices.
 * `state=income` (default) = cuentas por cobrar; `state=outcome` = cuentas por pagar.
 */
export interface OpenInvoiceRow {
  saleId: string
  invoiceNo: string
  date: string
  dueDate: string
  total: number
  payed: number
  topay: number
  /** 'ok' | 'toExpire' | 'expired'. */
  dueStatus: string
}

export interface OpenInvoiceContactRow {
  contactId: string
  name: string
  tin: string
  phone: string
  email: string
  totalSales: number
  totalPaid: number
  totalDebt: number
  invoices: OpenInvoiceRow[]
}

export interface OpenInvoicesReportResponse {
  rows: OpenInvoiceContactRow[]
  kpi: {
    totalDebt: number
    accounts: number
    expired: number
    toExpire: number
  }
}

// ── Rankings simples (Categories / Brands / Payment Methods) ─────────────────

export interface CategoryRow {
  categoryId: string
  name: string
  usold: number
  total: number
  tax: number
  cogs: number
  comission: number
  discount: number
}

export type BrandRow = Omit<CategoryRow, "categoryId"> & { brandId: string }

/** Customers report — ranking de clientes por consumo en el período. */
export interface CustomerRow {
  customerId: string
  name: string
  secondName: string
  ruc: string
  ci: string
  bday: string
  email: string
  phone: string
  loyalty: number
  usold: number
  grossTotal: number
  discount: number
  count: number
  tags: string[]
}

/** Payment methods report — devuelve detail + summary. */
export interface PaymentDetailRow {
  transactionId: string
  date: string
  customerName: string
  outletName: string
  registerName: string
  methodType: string
  methodName: string
  total: number
}

export interface PaymentSummaryRow {
  type: string
  name: string
  total: number
  count: number
}

export interface PaymentMethodsReportResponse {
  detail: PaymentDetailRow[]
  summary: PaymentSummaryRow[]
}

/** Expenses report — movimientos de caja (extracciones/ingresos manuales). */
export interface ExpenseRow {
  expensesId: string
  date: string
  outletName: string
  registerName: string
  userId: string | null
  userName: string
  note: string
  /** 1=extracción (sale del cajón), 2=ingreso (entra al cajón). */
  type: number
  amount: number
}

// ── Users (Staff / Recursos) ─────────────────────────────────────────────────

/** Fila del endpoint /v1/reports/users. Ventas por usuario/recurso del período. */
export interface UserReportRow {
  userId: string
  name: string
  usold: number
  total: number
  comission: number
  discount: number
  count: number
}

/** Respuesta de /v1/reports/users (array directo, no envuelto en rows). */
export type UsersReportResponse = UserReportRow[]

// ── Inventory movements ───────────────────────────────────────────────────────

/** Fila del endpoint /v1/reports/inventory?dataset=movements. */
export interface InventoryMovementRow {
  stockId: string
  stockDate: string
  itemId: string
  itemName: string
  itemSKU: string
  outletName: string
  locationName: string
  userName: string
  source: string
  transactionId: string
  note: string
  count: number
  onHand: number
  cogs: number
}

export type InventoryReportResponse = InventoryMovementRow[]

// ── Stock levels ──────────────────────────────────────────────────────────────

export interface StockDepot {
  locationId: string
  locationName: string
  min: number
  count: number
}

export interface StockRow {
  itemId: string
  name: string
  sku: string
  onHand: number
  cogs: number
  depots: StockDepot[]
  principal: { min: number; count: number }
}

export interface StockReportResponse {
  needsOutlet: boolean
  rows: StockRow[]
}

// ── Production ────────────────────────────────────────────────────────────────

export interface ProductionRow {
  itemId: string
  name: string
  sku: string
  category: string
  outletName: string
  userName: string
  date: string
  typeLabel: string
  isOrder: boolean
  units: number
  average: number
  cogs: number
  wasteValue: number
  utility: number
}

export interface ProductionTotals {
  qty: number
  cogs: number
  utility: number
}

export interface ProductionReportResponse {
  rows: ProductionRow[]
  totals: ProductionTotals
}

// ── Gift cards ────────────────────────────────────────────────────────────────
// F2 giftcard-issue-flow (2026-07-18): repuntado a la tabla `giftcard` (mig
// 44+78) — antes leía `giftCardSold` (legacy, no borrado pero ya no reportado).

export interface GiftCardRow {
  id: string
  transactionId: string
  doc: string
  beneficiaryId: string
  beneficiary: string
  expires: string
  /** Código alfanumérico (antes int en el legacy). */
  code: string
  note: string
  lastUsed: string
  outletName: string
  /** Saldo disponible (currentBalance). */
  value: number
  /** Monto emitido originalmente (initialBalance). */
  initialValue: number
}

export interface GiftcardsReportResponse {
  rows: GiftCardRow[]
}

// ── Summary year ──────────────────────────────────────────────────────────────

export interface SummaryYearMonth {
  month: number
  usold: number
  count: number
  discount: number
  tax: number
  salesTotal: number
  expensesTotal: number
  returnsTotal: number
  nonAddingTotal: number
  customers: number
}

export interface SummaryYearResponse {
  year: number
  years: number[]
  months: SummaryYearMonth[]
}

// ── Cashflow ──────────────────────────────────────────────────────────────────

export interface CashflowResponse {
  cashSales: number
  cashPayments: number
  incomeTotal: number
  stockPurchase: number
  expensesPurchase: number
  outPayment: number
  outcomeTotal: number
  remains: number
  initialCash: number
  accumulated: number
}

// ── vPayments (ePOS) ──────────────────────────────────────────────────────────

export interface VPaymentRow {
  status: string
  deposited: boolean
  date: string
  payoutDate: string
  depositedDate: string
  authCode: string
  operationNo: string
  source: string
  accountType: string
  brand: string
  outletName: string
  amount: number
  payoutAmount: number
  eUID: string
}

export interface VPaymentsKpi {
  sold: number
  deposited: number
  pendingDeposit: number
  count: number
}

export interface VPaymentsReportResponse {
  rows: VPaymentRow[]
  kpi: VPaymentsKpi
}

// ── Schedule (Agendamientos) ──────────────────────────────────────────────────

export interface ScheduleRow {
  transactionId: string
  transactionStatus: number
  docInvoice: string
  docTxId: string
  scheduleNo: string
  scheduledAt: string
  outletName: string
  userName: string
  responsibleName: string
  customerName: string
  note: string
  items: string[]
  fromDate: string
  toDate: string
  total: number
  attended: boolean
}

export interface ScheduleSummary {
  new: number
  ended: number
  cancelled: number
  noshow: number
  blocked: number
  totals: number
}

export interface ScheduleReportResponse {
  rows: ScheduleRow[]
  summary: ScheduleSummary
}

// ── Recurring (Facturas recurrentes) ─────────────────────────────────────────

export interface RecurringRow {
  recurringId: string
  clientId: string
  clientName: string
  clientSecondName: string
  invoiceNo: string
  txUid: string
  nextDate: string
  endDate: string
  frecuency: string
  status: number
  total: number
}

export interface RecurringReportResponse {
  rows: RecurringRow[]
}

// ── Satisfaction (NPS) ────────────────────────────────────────────────────────

export interface SatisfactionRow {
  satisfactionId: string
  level: number
  date: string
  customerName: string
  comment: string
  outletName: string
  transactionId: string
}

export type SatisfactionReportResponse = SatisfactionRow[]

// ── Sales summary (Resumen) — el más complejo del set ───────────────────────

export interface SalesSummaryResponse {
  totals: {
    /** Total bruto facturado (antes de devoluciones). */
    total: number
    /** Subtotal antes de impuestos. */
    netTotal?: number
    /** Cantidad de ventas (count). */
    count?: number
    /** Descuento aplicado en total. */
    discount?: number
    /** Impuesto cobrado. */
    tax?: number
    [key: string]: number | undefined
  }
  returns: {
    total: number
  }
  byType: Array<{ type: string; name?: string; total: number; count?: number }>
  giftcards: Array<{ name?: string; total: number; count?: number }>
  payments: Array<{
    type: string
    name: string
    price: number
    total: number
  }>
  nonAddingToSales: {
    total: number
    totalGiftCards: number
  }
}

/**
 * Fila del endpoint /v1/reports/orders. Órdenes reales = `pos_order`
 * (módulo Órdenes, context/24) — antes leía `transaction` type=12 (pedido
 * online legacy, casi sin uso). Migrado junto con el tab Órdenes de la
 * ficha del cliente (commit 003195c2, T5).
 * status: union de `OrderStatus` (hooks/use-orders.ts) — pintar SIEMPRE con
 * `OrderStatusBadge` compartido, no redefinir el mapeo acá.
 * channel: 'ecom' (source='ecommerce') | 'local' (counter/table/schedule).
 * dueDate: `pos_order` no tiene vencimiento, siempre null.
 */
export interface OrderRow {
  id: string
  date: string
  dueDate: string | null
  orderNo: string
  customerName: string
  outletName: string
  total: number
  status: OrderStatus
  channel: "ecom" | "local"
}

export interface OrdersReportResponse {
  rows: OrderRow[]
}

// ── Transaction detail (panel mode) ──────────────────────────────────────────

export interface CobrosRow {
  transactionId: string
  parentId: string
  parentInvoice: string
  invoiceNo: string
  date: string
  customerName: string
  userName: string
  outletName: string
  registerName: string
  payments: Array<{ name: string }>
  total: number
  type: number
}
export interface CobrosReportResponse { rows: CobrosRow[] }

// ── Fiscal PY (RG90 / Libro Ventas, F5 context/38 §E) ────────────────────────

/**
 * Fila cruda de `/v1/reports/fiscal?dataset=rg90|libro-ventas` — el backend
 * ya devuelve las columnas con el HEADER exacto como key (RG90 debe importar
 * a Marangatu con nombre/orden fijo, ver `FiscalService::rg90`), así el
 * front solo arma la matriz de export, no vuelve a nombrar columnas.
 */
export type FiscalReportRow = Record<string, string | number>

export interface FiscalReportResponse {
  rows: FiscalReportRow[]
  meta: {
    /** Ventas en el rango con desglose fiscal válido, ya filtradas. */
    totalCount: number
    /** De esas, cuántas resolvieron por fallback (meta.transactionDetails
     *  en vez de toTaxObj — típicamente ventas anteriores a la mig 124 con
     *  el JSON truncado). Informativo, no bloquea el export. */
    fallbackCount: number
    /** Ventas del rango EXCLUIDAS por no tener desglose fiscal congelado
     *  reconstruible (anteriores a F2a, D3 del plan) — no se inventó un
     *  desglose para ellas. */
    excludedCount: number
    /** El backend cortó en 5000 filas (mismo cap que el resto de
     *  /v1/reports) — para un documento que se declara ante el SET, un
     *  rango con más ventas que eso queda INCOMPLETO. Achicar el rango. */
    truncated: boolean
  }
}

/** Fetch imperativo (no `useQuery` — se dispara on-demand desde el botón de export). */
export async function fetchFiscalReport(
  dataset: "rg90" | "libro-ventas",
  from: string,
  to: string,
): Promise<FiscalReportResponse> {
  const params = new URLSearchParams({ dataset, from, to })
  return api.get<FiscalReportResponse>(`/v1/reports/fiscal?${params.toString()}`)
}

// ── Auditoría Tenant ──────────────────────────────────────────────────────────

/** Fila del endpoint /v1/reports/audit. */
export interface AuditRow {
  id: string
  companyId: string
  userId: string | null
  outletId: string | null
  realm: string | null
  method: string
  endpoint: string
  targetId: string | null
  meta: Record<string, unknown> | null
  ip: string | null
  createdAt: string
  /** Resuelto por JOIN a contact en el backend. */
  userName: string | null
  /** Resuelto por JOIN a outlet en el backend. */
  outletName: string | null
}

export interface AuditReportResponse {
  rows: AuditRow[]
}

// ── Purchases (Compras y gastos) ─────────────────────────────────────────────

/**
 * Fila del endpoint /v1/reports/purchases?view=general. Espejo de
 * `Reports/PurchasesService::general` — cubre transactionType 1 (contado) y
 * 4 (crédito), con `authNo`/`prefix`/`invoiceNo` ya separados por el backend
 * (no la combinación legacy "authNo;prefix" que usa el CRUD `/v1/purchases`).
 */
export interface PurchaseReportRow {
  transactionId: string
  authNo: string
  prefix: string
  invoiceNo: string
  date: string
  dueDate: string | null
  outletName: string
  userName: string
  supplierName: string
  supplierTIN: string
  note: string
  payments: Array<{ name: string; price: number }>
  transactionType: number
  transactionComplete: 0 | 1
  transactionStatus: string
  category: string
  tax: number
  discount: number
  total: number
  debt: number
  canAddPayment: boolean
}

export interface PurchasesReportResponse {
  rows: PurchaseReportRow[]
}

export interface QuoteRow {
  transactionId: string
  invoiceNo: string
  date: string
  transactionStatus: string
  customerName: string
  customerTIN: string
  userName: string
  outletName: string
  total: number
  type: number
}
export interface QuotesReportResponse { rows: QuoteRow[] }

export interface TxDetailItem {
  itemSoldId: string
  itemId: string
  itemName: string
  itemSoldUnits: number
  itemSoldTotal: number
  itemSoldTax: number
  userId: string | null
  /** F1 (context/39-detalle-transaccion.md) — campos nuevos del resolver canónico. */
  note?: string | null
  unitPrice?: number
  itemSoldDiscount?: number
  discountPercent?: number | null
  itemSoldComission?: number | null
  userName?: string | null
  taxId?: string | null
  taxRate?: number | null
  taxKind?: string | null
  taxIncluded?: boolean | null
  taxAmount?: number
  taxNet?: number | null
}

/** F1 — desglose de impuestos por tasa (toTaxObj congelado, o reconstruido si trunca). */
export interface TxTaxByRateRow {
  taxId: string | null
  rate: number
  kind: string
  base: number
  amount: number
}

/** F1 — resumen mínimo de un documento vinculado (cotización/orden), para linkear sin otra ida y vuelta. */
export interface TxRelatedDoc {
  id: string
  type: number
  date: string | null
  invoiceNo: string | null
  invoicePrefix: string | null
  total: number
}

export interface TxRelatedOrder {
  id: string
  orderNumber: number | null
  status: string
  date: string | null
  total: number
}

export interface TxDetailFull {
  transaction: {
    transactionId: string
    transactionDate: string
    transactionDueDate: string | null
    transactionNote: string | null
    transactionType: number
    transactionComplete: 0 | 1
    transactionTotal: number
    transactionDiscount: number
    transactionTax: number
    transactionPaymentType: Array<{ type: string; name: string; total: number; price: number; extra: string }>
    invoiceNo: string | null
    customerId: string | null
    customerName: string | null
    userId: string | null
    userName: string | null
    responsibleId: string | null
    outletId: string | null
    outletName: string | null
    meta: { tags?: string[] } | null
    /** F1 — cabecera/ámbito ampliados del resolver canónico. */
    transactionStatus?: number
    customerTIN?: string | null
    responsibleName?: string | null
    condition?: "cash" | "credit"
    void?: boolean
    currency?: string | null
    ivaRemoved?: boolean
    registerId?: string | null
    registerName?: string
    authNo?: string
    invoicePrefix?: string
    invoiceNoPad?: string
    docNo?: string
    subtotal?: number
    netTotal?: number
  }
  items: TxDetailItem[]
  creditNotes: Array<{ transactionId: string; transactionDate: string; transactionTotal: number; invoiceNo: string | null }>
  appointments: Array<{ transactionId: string; transactionDate: string; transactionTotal: number }>
  toTransactions: unknown[]
  creditPayments: { total: number; paid: number; debt: number } | null
  paymentsReceived: Array<{ transactionId: string; date: string; amount: number; invoiceNo: string; paymentMethod: string }>
  /** F1 — nuevos, ver context/39-detalle-transaccion.md. */
  taxByRate?: TxTaxByRateRow[]
  quotesOrigin?: TxRelatedDoc[]
  quotesDerived?: TxRelatedDoc[]
  orders?: TxRelatedOrder[]
}

export function useTransactionDetail(id: string | null) {
  return useQuery<TxDetailFull>({
    queryKey: ["transaction-detail", id],
    queryFn: () => api.get<TxDetailFull>(`/v1/reports/transactions?id=${id}`),
    enabled: !!id,
    staleTime: 30 * 1000,
    retry: false,
  })
}

/**
 * Corrige fechas y montos de una caja ya registrada (`action=correct`).
 *
 * A diferencia de `close`, que solo actúa sobre una caja abierta, esto pisa
 * los cuatro campos de una caja YA cerrada — es la corrección de un arqueo
 * mal cargado. El backend lo scopea por companyId; acá solo se manda el id.
 *
 * `closeDate` vacío deja la caja como abierta (el backend lo persiste NULL):
 * es la forma de revertir un cierre hecho por error.
 */
export function useCorrectDrawer() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (vars: {
      drawerId: string
      openDate: string
      openAmount: number
      closeDate: string
      closeAmount: number
    }) =>
      api.post<{ id: string; action: string }>("/v1/reports/drawers", {
        action: "correct",
        id: vars.drawerId,
        openDate: vars.openDate,
        openAmount: vars.openAmount,
        closeDate: vars.closeDate,
        closeAmount: vars.closeAmount,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["reports", "drawers"] })
      // El arqueo alimenta el resumen de caja del POS y los reportes de
      // efectivo: si se corrige el monto, esos números también cambian.
      qc.invalidateQueries({ queryKey: ["reports", "cashflow"] })
      qc.invalidateQueries({ queryKey: ["drawer"] })
    },
  })
}

/** Cierra una caja desde el panel (realm panel, companyId por JWT). */
export function useCloseDrawerPanel() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (vars: { drawerId: string; amount: number; date: string }) =>
      api.post<{ id: string; action: string }>("/v1/reports/drawers", {
        action: "close",
        id: vars.drawerId,
        closeAmount: vars.amount,
        closeDate: vars.date,
      }),
    onSuccess: () => {
      // Invalidar todos los queries de drawers para que el listado se refresque.
      qc.invalidateQueries({ queryKey: ["reports", "drawers"] })
    },
  })
}
