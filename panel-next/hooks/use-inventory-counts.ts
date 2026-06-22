"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface InventoryCountSession {
  inventoryCountId: string
  outletId: string
  outletName: string
  locationId: string | null
  locationName: string | null
  status: 0 | 1 | 2
  startedAt: string
  finishedAt: string | null
  note: string | null
  totalItems: number
  countedItems: number
  totalCostDelta: number
}

export interface InventoryCountItem {
  inventoryCountItemId: string
  itemId: string
  name: string
  sku: string | null
  expectedQty: number
  countedQty: number | null
  difference: number | null
  unitCost: number
  countedAt: string | null
}

export interface InventoryCountDetail {
  session: {
    inventoryCountId: string
    outletId: string
    locationId: string | null
    status: 0 | 1 | 2
    note: string | null
    startedAt: string
    finishedAt: string | null
    startedBy: string
    startedByName: string | null
    finishedBy: string | null
    finishedByName: string | null
  }
  items: InventoryCountItem[]
}

export function useInventoryCounts(filters?: { outletId?: string; status?: number }) {
  return useQuery<{ rows: InventoryCountSession[]; total: number }>({
    queryKey: ["inventory-counts", filters ?? {}],
    queryFn: () => {
      const params = new URLSearchParams({ action: "list" })
      if (filters?.outletId) params.set("outletId", filters.outletId)
      if (filters?.status !== undefined) params.set("status", String(filters.status))
      return api.get(`/v1/inventory_count?${params.toString()}`)
    },
    staleTime: 30 * 1000,
  })
}

export function useInventoryCount(id: string | null) {
  return useQuery<InventoryCountDetail>({
    queryKey: ["inventory-counts", id],
    queryFn: () => api.get(`/v1/inventory_count?action=get&id=${id}`),
    enabled: !!id,
    staleTime: 10 * 1000,
  })
}

export function useCreateInventoryCount() {
  const qc = useQueryClient()
  return useMutation<
    { id: string; itemCount: number },
    Error,
    { outletId: string; locationId?: string; note?: string }
  >({
    mutationFn: (data) => api.post("/v1/inventory_count", { action: "create", ...data }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["inventory-counts"] }),
  })
}

export function useSetCountedQty() {
  const qc = useQueryClient()
  return useMutation<
    { ok: boolean },
    Error,
    { countId: string; itemId: string; qty: number }
  >({
    mutationFn: ({ countId, itemId, qty }) =>
      api.post("/v1/inventory_count", { action: "setQty", id: countId, itemId, qty }),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ["inventory-counts", vars.countId] })
    },
  })
}

export function useBulkSetCountedQty() {
  const qc = useQueryClient()
  return useMutation<
    { updatedCount: number },
    Error,
    { countId: string; rows: Array<{ itemId: string; qty: number }> }
  >({
    mutationFn: ({ countId, rows }) =>
      api.post("/v1/inventory_count", { action: "bulkSetQty", id: countId, rows }),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ["inventory-counts", vars.countId] })
    },
  })
}

export function useFinishInventoryCount() {
  const qc = useQueryClient()
  return useMutation<
    { adjustmentsCount: number; totalCostDelta: number },
    Error,
    { countId: string }
  >({
    mutationFn: ({ countId }) =>
      api.post("/v1/inventory_count", { action: "finish", id: countId }),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ["inventory-counts"] })
      qc.invalidateQueries({ queryKey: ["inventory-counts", vars.countId] })
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["stock"] })
      qc.invalidateQueries({ queryKey: ["inventory"] })
    },
  })
}

export function useCancelInventoryCount() {
  const qc = useQueryClient()
  return useMutation<{ ok: boolean }, Error, { countId: string }>({
    mutationFn: ({ countId }) =>
      api.post("/v1/inventory_count", { action: "cancel", id: countId }),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ["inventory-counts"] })
      qc.invalidateQueries({ queryKey: ["inventory-counts", vars.countId] })
    },
  })
}
