"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface InventoryCountSession {
  inventoryCountId: string
  /** Correlativo del documento por sucursal (mig 129). Null en los
   *  registros anteriores que no se pudieron numerar. */
  docNumber: number | null
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

/**
 * Alcance con el que se abrió la sesión (mig 158). `categoryIds: []` =
 * todas las categorías. En las sesiones anteriores a la migración el backend
 * devuelve `{}` (alcance desconocido: se snapshoteaba todo el tenant), por
 * eso las dos claves son opcionales.
 */
export interface InventoryCountScope {
  categoryIds?: string[]
  includeZeroStock?: boolean
}

export interface InventoryCountItem {
  inventoryCountItemId: string
  itemId: string
  name: string
  sku: string | null
  /** Categoría principal (m2m item_category, fallback item.categoryId). */
  categoryId: string | null
  categoryName: string | null
  expectedQty: number
  countedQty: number | null
  difference: number | null
  unitCost: number
  countedAt: string | null
}

export interface InventoryCountDetail {
  session: {
    inventoryCountId: string
    /** Correlativo del documento por sucursal (mig 129). */
    docNumber: number | null
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
    scope: InventoryCountScope
  }
  items: InventoryCountItem[]
}

/** Alcance que comparten `create` y `preview` — un solo tipo, sin divergencia. */
export interface InventoryCountScopeInput {
  outletId: string
  locationId?: string
  categoryIds?: string[]
  includeZeroStock?: boolean
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
    InventoryCountScopeInput & { note?: string }
  >({
    mutationFn: (data) => api.post("/v1/inventory_count", { action: "create", ...data }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["inventory-counts"] }),
  })
}

/**
 * Cuántos artículos entrarían con el alcance elegido, sin crear la sesión.
 * El backend lo resuelve con el MISMO predicado que usa `create`
 * (`InventoryCountScope`), así que el número que ve el operador es el que
 * va a obtener.
 *
 * `enabled` solo cuando hay sucursal: sin ella el alcance no existe.
 */
export function useInventoryCountPreview(scope: InventoryCountScopeInput | null) {
  return useQuery<{ count: number }>({
    queryKey: ["inventory-counts", "preview", scope],
    queryFn: () => api.post("/v1/inventory_count", { action: "preview", ...scope }),
    enabled: !!scope?.outletId,
    staleTime: 30 * 1000,
    // Un alcance que no matchea nada devuelve 0, no error — no reintentar.
    retry: false,
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
