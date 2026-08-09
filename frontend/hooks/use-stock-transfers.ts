"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface StockTransfer {
  stockTransferId: string
  /** Correlativo del documento por sucursal (mig 129). Null en los
   *  registros anteriores que no se pudieron numerar. */
  docNumber: number | null
  status: 0 | 1
  createdAt: string
  note: string | null
  fromOutletId: string
  fromOutletName: string
  fromLocationId: string | null
  fromLocationName: string | null
  toOutletId: string
  toOutletName: string
  toLocationId: string | null
  toLocationName: string | null
  itemsCount: number
}

export interface StockTransferItem {
  stockTransferItemId: string
  itemId: string
  name: string
  sku: string | null
  qty: number
  unitCost: number
}

export interface StockTransferDetail {
  transfer: {
    stockTransferId: string
    /** Correlativo del documento por sucursal (mig 129). */
    docNumber: number | null
    companyId: string
    status: 0 | 1
    createdAt: string
    note: string | null
    fromOutletId: string
    fromOutletName: string
    fromLocationId: string | null
    fromLocationName: string | null
    toOutletId: string
    toOutletName: string
    toLocationId: string | null
    toLocationName: string | null
    createdBy: string
    createdByName: string | null
  }
  items: StockTransferItem[]
}

export interface StockTransferFilters {
  fromOutletId?: string
  toOutletId?: string
  status?: number
  dateFrom?: string
  dateTo?: string
  limit?: number
  offset?: number
}

export function useStockTransfers(filters?: StockTransferFilters) {
  return useQuery<{ rows: StockTransfer[]; total: number }>({
    queryKey: ["stock-transfers", filters ?? {}],
    queryFn: () => {
      const params = new URLSearchParams({ action: "list" })
      if (filters?.fromOutletId) params.set("fromOutletId", filters.fromOutletId)
      if (filters?.toOutletId)   params.set("toOutletId",   filters.toOutletId)
      if (filters?.status !== undefined) params.set("status", String(filters.status))
      if (filters?.dateFrom) params.set("dateFrom", filters.dateFrom)
      if (filters?.dateTo)   params.set("dateTo",   filters.dateTo)
      if (filters?.limit)    params.set("limit",    String(filters.limit))
      if (filters?.offset)   params.set("offset",   String(filters.offset))
      return api.get(`/v1/stock_transfer?${params.toString()}`)
    },
    staleTime: 30 * 1000,
  })
}

export function useStockTransfer(id: string | null) {
  return useQuery<StockTransferDetail>({
    queryKey: ["stock-transfers", id],
    queryFn: () => api.get(`/v1/stock_transfer?action=get&id=${id}`),
    enabled: !!id,
    staleTime: 10 * 1000,
  })
}

export function useCreateStockTransfer() {
  const qc = useQueryClient()
  return useMutation<
    { id: string; itemsProcessed: number; skippedItems: string[] },
    Error,
    {
      from: { outletId: string; locationId?: string | null }
      to:   { outletId: string; locationId?: string | null }
      note?: string
      items: Array<{ itemId: string; qty: number }>
    }
  >({
    mutationFn: (data) => api.post("/v1/stock_transfer", { action: "create", ...data }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["stock-transfers"] })
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["stock"] })
      qc.invalidateQueries({ queryKey: ["inventory"] })
    },
  })
}

export function useCancelStockTransfer() {
  const qc = useQueryClient()
  return useMutation<{ ok: boolean }, Error, { id: string }>({
    mutationFn: ({ id }) => api.post("/v1/stock_transfer", { action: "cancel", id }),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ["stock-transfers"] })
      qc.invalidateQueries({ queryKey: ["stock-transfers", vars.id] })
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["stock"] })
      qc.invalidateQueries({ queryKey: ["inventory"] })
    },
  })
}
