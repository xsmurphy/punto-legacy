"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface PurchaseListRow {
  id: string
  date: string
  dueDate: string | null
  total: number
  tax: number
  discount: number
  status: number
  invoiceNo: number | null
  invoicePrefix: string | null
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
  invoiceNo: number | null
  invoicePrefix: string | null
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
}

export interface PurchaseFormItem {
  itemId?: string
  title?: string
  units: number
  price: number
  taxId?: string
  taxValue?: number
}

export interface PurchaseCreatePayload {
  supplierId?: string | null
  outletId?: string
  invoiceDate?: string
  dueDate?: string
  invoiceNo?: number | string | null
  invoicePrefix?: string
  authNo?: string
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
