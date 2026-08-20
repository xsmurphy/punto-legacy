"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/** Condición de la factura: contado (cerrada) o crédito (cuenta por pagar). */
export type PurchaseCondition = "cash" | "credit"

export interface PurchaseListRow {
  id: string
  date: string
  dueDate: string | null
  total: number
  tax: number
  discount: number
  status: number
  /** 1 = contado, 4 = crédito. */
  type: number
  condition: PurchaseCondition
  /** false en una compra a crédito aún no saldada. */
  complete: boolean
  invoiceNo: number | null
  invoicePrefix: string | null
  /** Timbrado del proveedor (número de comprobante = invoicePrefix + invoiceNo). */
  authNo: string | null
  authNoDueDate: string | null
  supplierId: string | null
  supplierName: string | null
  outletId: string
  outletName: string | null
}

export interface PurchaseListResponse {
  rows: PurchaseListRow[]
  total: number
}

export interface PurchaseDetailItem {
  itemId: string
  title: string
  qty: number
  price: number
  tax: number
  taxId: string
  /** Categoría de GASTO (Finanzas) elegida para esta línea al cargar la compra. */
  expenseCategoryId?: string | null
}

export interface PurchaseDetail {
  id: string
  date: string
  dueDate: string | null
  total: number
  tax: number
  discount: number
  units: number
  status: number
  /** 1 = contado, 4 = crédito. */
  type: number
  condition: PurchaseCondition
  complete: boolean
  invoiceNo: number | null
  invoicePrefix: string | null
  /** Timbrado del proveedor (número de comprobante = invoicePrefix + invoiceNo). */
  authNo: string | null
  authNoDueDate: string | null
  note: string | null
  supplierId: string | null
  supplierName: string | null
  outletId: string
  outletName: string | null
  userId: string | null
  userName: string | null
  paymentType: Array<{
    type: string
    name?: string
    total?: number
    /** Shape legacy pre-mig 102 — algunas compras viejas solo tienen `price`. */
    price?: number
    identifier?: string
    bankName?: string
    dueDate?: string
  }> | null
  details: PurchaseDetailItem[]
  /** Notas de crédito ya emitidas contra esta compra (`/v1/purchases?id=` las agrega al detalle). */
  creditNotes: PurchaseCreditNote[]
  /** itemId → cantidad ya acreditada por NC previas no anuladas. */
  creditedQty: Record<string, number>
}

/** Modo de una nota de crédito de compra: 'cash' = el proveedor devuelve plata, 'credit' = reduce el saldo pendiente. */
export type CreditNoteRefundMode = "cash" | "credit"

/**
 * Número de comprobante + timbrado IMPRESOS en el documento del PROVEEDOR
 * (context/29 §5) — documento ajeno, sin correlativo interno propio (owner
 * 2026-08-17). Compartido por NC de compra y pago a proveedor; la compra
 * usa los campos planos ya existentes (invoicePrefix/invoiceNo/authNo).
 */
export interface SupplierDocumentInput {
  docPrefix?: string | null
  docNo?: string | number | null
  docDate?: string | null
  authNo?: string | null
  authNoDueDate?: string | null
}

export interface PurchaseCreditNote {
  id: string
  total: number
  date: string
  note: string | null
  /** 1 = activa, 6 = anulada (mismo criterio que `PurchaseDetail.status`). */
  status: number
  refundMode: CreditNoteRefundMode
  docPrefix: string | null
  docNo: number | null
  docDate: string | null
  authNo: string | null
  authNoDueDate: string | null
}

export interface PurchaseCreditNoteItemInput {
  itemId: string
  qty: number
}

export interface CreatePurchaseCreditNotePayload {
  parentTransactionId: string
  items: PurchaseCreditNoteItemInput[]
  refundMode: CreditNoteRefundMode
  /** true = el proveedor se lleva la mercadería (resta stock). false = bonificación, no toca stock. */
  affectsStock: boolean
  note?: string
  supplierDoc?: SupplierDocumentInput
}

export interface PurchaseCreditNoteResult {
  id: string
  total: number
  refundMode: CreditNoteRefundMode
  affectsStock: boolean
  stockMovements: number
}

export interface PurchaseFormItem {
  itemId?: string
  title?: string
  units: number
  price: number
  taxId?: string
  taxValue?: number
  /** Unidades por paquete/caja (ej. 1 caja = 24 unidades). Default 1 — solo aplica a productos. */
  packSize?: number
  /**
   * Categoría de GASTO (Finanzas) de esta línea puntual — precargada desde
   * `item.expenseCategoryId` al elegir el producto, editable sin alterar esa
   * configuración (owner 2026-08-20). Opcional, no bloquea la carga.
   */
  expenseCategoryId?: string | null
}

export interface PurchaseCreatePayload {
  supplierId?: string | null
  outletId?: string
  /**
   * Default 'cash' si se omite. 'credit' → transactionType=4, pendiente:
   * aparece en Cuentas por pagar y Previsiones, y exige `dueDate` (422 sin él).
   */
  condition?: PurchaseCondition
  invoiceDate?: string
  dueDate?: string
  invoiceNo?: number | string | null
  invoicePrefix?: string
  authNo?: string
  /** Vencimiento del timbrado (authNo) — mig 144. */
  authNoDueDate?: string
  /** taxonomyId real del medio de pago (@see usePaymentMethods) — resuelve cuenta vía finAccountMap. */
  paymentMethodId?: string
  /** Solo si el método tiene systemKey='check' — F1, context/30. */
  checkNumber?: string
  checkBank?: string
  checkDueDate?: string
  discount?: number
  note?: string
  items: PurchaseFormItem[]
}

export interface PurchaseListFilters {
  from?: string
  to?: string
  supplierId?: string
  limit?: number
  offset?: number
}

/** Listado de compras. */
export function usePurchases(filters: PurchaseListFilters = {}) {
  const qs = new URLSearchParams()
  if (filters.from) qs.set("from", filters.from)
  if (filters.to) qs.set("to", filters.to)
  if (filters.supplierId) qs.set("supplierId", filters.supplierId)
  if (filters.limit) qs.set("limit", String(filters.limit))
  if (filters.offset) qs.set("offset", String(filters.offset))
  const query = qs.toString()
  return useQuery<PurchaseListResponse>({
    queryKey: ["purchases", "list", filters],
    queryFn: () => api.get<PurchaseListResponse>(`/v1/purchases${query ? "?" + query : ""}`),
    staleTime: 30_000,
  })
}

/** Detalle de una compra. */
export function usePurchase(id: string | null) {
  return useQuery<PurchaseDetail>({
    queryKey: ["purchases", "detail", id],
    queryFn: () => api.get<PurchaseDetail>(`/v1/purchases?id=${id}`),
    enabled: !!id,
    staleTime: 30_000,
  })
}

/** Crea una compra. Invalida `purchases` al éxito para refrescar el listado. */
export function useCreatePurchase() {
  const qc = useQueryClient()
  return useMutation<{ id: string }, Error, PurchaseCreatePayload>({
    // api.post espera `Json` (Record<string, unknown> | unknown[]); el cast
    // refleja que PurchaseCreatePayload es JSON-compatible aunque TS no infiera
    // index signature por los keys opcionales.
    mutationFn: (payload) =>
      api.post<{ id: string }>(
        "/v1/purchases",
        payload as unknown as Record<string, unknown>,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["purchases"] })
    },
  })
}

/**
 * Anula una compra (soft-void): revierte stock y marca status=6 en el backend.
 * Invalida `purchases` (listado + detalle) y `dashboard` (egresos) al éxito.
 */
export function useVoidPurchase() {
  const qc = useQueryClient()
  return useMutation<{ id: string; status: number }, Error, string>({
    mutationFn: (id) =>
      api.del<{ id: string; status: number }>(`/v1/purchases?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["purchases"] })
      qc.invalidateQueries({ queryKey: ["dashboard-widget"] })
    },
  })
}

/**
 * Emite una nota de crédito de compra (total o parcial) — caso real:
 * compramos N unidades, algunas vienen dañadas, el proveedor nos acredita.
 * Invalida el detalle de la compra (recalcula `creditedQty`/`creditNotes`),
 * el listado de compras (el saldo pendiente puede cambiar en modo 'credit'),
 * finanzas (modo 'cash' genera un ingreso) y stock (si `affectsStock`).
 */
export function useCreatePurchaseCreditNote() {
  const qc = useQueryClient()
  return useMutation<PurchaseCreditNoteResult, Error, CreatePurchaseCreditNotePayload>({
    mutationFn: (payload) =>
      api.post<PurchaseCreditNoteResult>(
        "/v1/purchases?resource=creditNote",
        payload as unknown as Record<string, unknown>,
      ),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ["purchases", "detail", variables.parentTransactionId] })
      qc.invalidateQueries({ queryKey: ["purchases", "list"] })
      qc.invalidateQueries({ queryKey: ["dashboard-widget"] })
      qc.invalidateQueries({ queryKey: ["stock"] })
    },
  })
}
