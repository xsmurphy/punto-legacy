"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api, type HttpClient } from "@/lib/api-client"

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
  expense: number | string
  income: number | string
  return: number | string
}

/** Respuesta del endpoint /v1/reports/drawers (lista). */
export interface DrawersReportResponse {
  rows: DrawerRow[]
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
 * Fila del endpoint /v1/reports/orders. Órdenes = transaction type=12.
 * status: 0/1 Pendiente · 2 En Espera · 3 En Proceso · 4 Finalizado · 5 Enviado · 6 Cancelado.
 * channel: 'ecom' (online) | 'local'.
 */
export interface OrderRow {
  id: string
  date: string
  dueDate: string | null
  orderNo: string
  customerName: string
  outletName: string
  total: number
  status: number
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
  userId: string
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
  }
  items: TxDetailItem[]
  creditNotes: Array<{ transactionId: string; transactionDate: string; transactionTotal: number; invoiceNo: string | null }>
  appointments: Array<{ transactionId: string; transactionDate: string; transactionTotal: number }>
  toTransactions: unknown[]
  creditPayments: { total: number; paid: number; debt: number } | null
  paymentsReceived: Array<{ transactionId: string; date: string; amount: number; invoiceNo: string; paymentMethod: string }>
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
