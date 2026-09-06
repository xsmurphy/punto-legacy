"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { api } from "@/lib/api-client"

/**
 * Órdenes de pago a proveedor (migs 196/197) — el documento que AUTORIZA el
 * pago, antes de que el pago exista.
 *
 * Realm: SOLO `api` (cliente de panel, Bearer del panel). `api/v1/payment-orders.php`
 * exige `apiAuthTenant(['panel'])`, así que nunca `posApi`: el POS no le compra
 * a proveedores ni autoriza desembolsos.
 */

export type PaymentOrderStatus = "draft" | "approved" | "paid" | "cancelled"

export const PAYMENT_ORDER_STATUS_META: Record<
  PaymentOrderStatus,
  { label: string; variant: "default" | "secondary" | "destructive" | "outline" }
> = {
  // Mismo criterio semántico que `PRODUCTION_STATUS_META`: outline = todavía
  // no pasó nada, secondary = en curso, default = terminó bien, destructive =
  // se descartó. Sin hex colors.
  draft: { label: "Borrador", variant: "outline" },
  approved: { label: "Aprobada", variant: "secondary" },
  paid: { label: "Pagada", variant: "default" },
  cancelled: { label: "Cancelada", variant: "destructive" },
}

export const PAYMENT_ORDER_STATUS_OPTIONS: Array<{ value: PaymentOrderStatus; label: string }> = (
  Object.keys(PAYMENT_ORDER_STATUS_META) as PaymentOrderStatus[]
).map((value) => ({ value, label: PAYMENT_ORDER_STATUS_META[value].label }))

export interface PaymentOrder {
  paymentOrderId: string
  outletId: string
  supplierId: string
  docNumber: number | null
  status: PaymentOrderStatus
  total: number
  paymentDate: string | null
  notes: string | null
  createdBy: string
  createdAt: string
  approvedBy: string | null
  approvedAt: string | null
  paidBy: string | null
  paidAt: string | null
  /** Recibo (transaction type=5) que ejecutó la orden. Solo cuando status="paid". */
  paymentTransactionId: string | null
  cancelledBy: string | null
  cancelledAt: string | null
  cancelReason: string | null
}

export interface PaymentOrderListRow extends PaymentOrder {
  supplierName: string
  outletName: string
  lineCount: number
}

export interface PaymentOrderLine {
  lineId: string
  transactionId: string
  amount: number
  invoiceNo: string
  date: string
  dueDate: string
  /** Total de la factura de compra. */
  total: number
  /** Ya imputado por otros recibos/notas de crédito. */
  paid: number
  /** Saldo VIVO al momento de la consulta — no el congelado al armar la orden. */
  debt: number
  voided: boolean
}

/**
 * Cabecera del detalle: la orden más los NOMBRES resueltos server-side.
 *
 * La atribución es el punto de la feature, así que el detalle la muestra con
 * nombre y no con uuid. Cada `*Name` puede venir vacío si el contacto ya no
 * existe — el render cae al id, que es peor pero sigue siendo el dato.
 */
export interface PaymentOrderDetailHeader extends PaymentOrder {
  supplierName: string
  outletName: string
  createdByName: string
  approvedByName: string
  paidByName: string
  cancelledByName: string
}

export interface PaymentOrderDetail {
  order: PaymentOrderDetailHeader
  lines: PaymentOrderLine[]
}

/** Una factura de compra con saldo, candidata a entrar en una orden. */
export interface PendingInvoice {
  transactionId: string
  invoiceNo: string
  date: string
  dueDate: string
  outletId: string
  total: number
  paid: number
  debt: number
  /**
   * Ya está en OTRA orden de pago viva. El backend la devuelve marcada en vez
   * de esconderla: sin esto, el usuario no entiende por qué no aparece una
   * factura que sabe que existe.
   */
  committed: boolean
  committedOrderId: string | null
  committedDocNumber: number | null
}

export interface PaymentOrderFilters {
  status?: PaymentOrderStatus | ""
  supplierId?: string
  dateFrom?: string
  dateTo?: string
}

export interface PaymentOrderLineInput {
  transactionId: string
  amount: number
}

/**
 * Invalidaciones compartidas. Ejecutar una orden crea un recibo real, salda
 * facturas de compra y mueve Finanzas — o sea que toca exactamente las mismas
 * queries que un pago a proveedor hecho a mano. Se replica el criterio de
 * `invalidatePaymentQueries()` (use-credit-payment.ts) en vez de invalidar
 * solo lo propio: si no, el detalle de la compra y el reporte de cuentas por
 * pagar seguirían mostrando la deuda vieja.
 *
 * `refetchType: "all"` donde la query puede estar INACTIVA: el diálogo de
 * ejecución tapa el detalle, y una query inactiva se marca stale pero no
 * refetchea.
 */
function invalidatePaymentOrderQueries(qc: ReturnType<typeof useQueryClient>) {
  qc.invalidateQueries({ queryKey: ["payment-orders"], refetchType: "all" })
  qc.invalidateQueries({ queryKey: ["purchases"], refetchType: "all" })
  qc.invalidateQueries({ queryKey: ["reports", "purchases"] })
  qc.invalidateQueries({ queryKey: ["reports", "open_invoices"] })
  qc.invalidateQueries({ queryKey: ["transactions"], refetchType: "all" })
  qc.invalidateQueries({ queryKey: ["transaction-detail"], refetchType: "all" })
  qc.invalidateQueries({ queryKey: ["contacts"] })
}

export function usePaymentOrders(filters?: PaymentOrderFilters) {
  return useQuery<{ rows: PaymentOrderListRow[]; total: number }>({
    queryKey: ["payment-orders", "list", filters ?? {}],
    queryFn: () => {
      const params = new URLSearchParams({ action: "list" })
      if (filters?.status) params.set("status", filters.status)
      if (filters?.supplierId) params.set("supplierId", filters.supplierId)
      if (filters?.dateFrom) params.set("dateFrom", filters.dateFrom)
      if (filters?.dateTo) params.set("dateTo", filters.dateTo)
      return api.get(`/v1/payment-orders?${params.toString()}`)
    },
    staleTime: 30 * 1000,
  })
}

export function usePaymentOrder(id: string | null | undefined) {
  return useQuery<PaymentOrderDetail>({
    queryKey: ["payment-orders", "detail", id],
    queryFn: () => api.get(`/v1/payment-orders?action=get&id=${id}`),
    enabled: !!id,
    staleTime: 10 * 1000,
  })
}

/**
 * Facturas del proveedor con saldo. `excludeOrderId` deja fuera del "ya
 * comprometida" a la orden que se está editando — si no, editar un borrador
 * mostraría sus propias facturas como tomadas por otra orden.
 */
export function usePendingSupplierInvoices(
  supplierId: string | null | undefined,
  excludeOrderId?: string,
) {
  return useQuery<{ rows: PendingInvoice[] }>({
    queryKey: ["payment-orders", "pending-invoices", supplierId, excludeOrderId ?? null],
    queryFn: () => {
      const params = new URLSearchParams({ action: "pendingInvoices", supplierId: supplierId! })
      if (excludeOrderId) params.set("excludeOrderId", excludeOrderId)
      return api.get(`/v1/payment-orders?${params.toString()}`)
    },
    enabled: !!supplierId,
    // Corto a propósito: el saldo es un derivado vivo y la pantalla de armado
    // es justamente donde una foto vieja hace perder tiempo (se arma la orden
    // y el backend la rechaza al validar contra el saldo real).
    staleTime: 5 * 1000,
  })
}

export function useCreatePaymentOrder() {
  const qc = useQueryClient()
  return useMutation<
    { paymentOrderId: string; docNumber: number; total: number; status: PaymentOrderStatus },
    Error,
    {
      supplierId: string
      outletId: string
      lines: PaymentOrderLineInput[]
      paymentDate?: string | null
      notes?: string | null
    }
  >({
    mutationFn: (data) => api.post("/v1/payment-orders", { action: "create", ...data }),
    onSuccess: () => invalidatePaymentOrderQueries(qc),
  })
}

export function useUpdatePaymentOrder() {
  const qc = useQueryClient()
  return useMutation<
    { paymentOrderId: string; total: number; status: PaymentOrderStatus },
    Error,
    { id: string; lines: PaymentOrderLineInput[]; paymentDate?: string | null; notes?: string | null }
  >({
    mutationFn: (data) => api.post("/v1/payment-orders", { action: "update", ...data }),
    onSuccess: () => invalidatePaymentOrderQueries(qc),
  })
}

export function useApprovePaymentOrder() {
  const qc = useQueryClient()
  return useMutation<{ paymentOrderId: string; status: PaymentOrderStatus; total: number }, Error, { id: string }>({
    mutationFn: ({ id }) => api.post("/v1/payment-orders", { action: "approve", id }),
    onSuccess: () => invalidatePaymentOrderQueries(qc),
  })
}

/**
 * Ejecuta la orden. El backend llama a `CreditPaymentService` —el mismo que
 * usa el pago a proveedor a mano—, así que acepta el mismo `supplierDoc`
 * (comprobante + timbrado del proveedor, mig 144).
 */
export function useExecutePaymentOrder() {
  const qc = useQueryClient()
  return useMutation<
    { paymentOrderId: string; status: PaymentOrderStatus; paymentTransactionId: string; amount: number },
    Error,
    {
      id: string
      paymentMethodKey: string
      note?: string | null
      identifier?: string | null
      supplierDoc?: Record<string, unknown> | null
    }
  >({
    mutationFn: (data) => api.post("/v1/payment-orders", { action: "execute", ...data }),
    onSuccess: () => invalidatePaymentOrderQueries(qc),
  })
}

export function useCancelPaymentOrder() {
  const qc = useQueryClient()
  return useMutation<{ paymentOrderId: string; status: PaymentOrderStatus }, Error, { id: string; reason: string }>({
    mutationFn: ({ id, reason }) => api.post("/v1/payment-orders", { action: "cancel", id, reason }),
    onSuccess: () => invalidatePaymentOrderQueries(qc),
  })
}
