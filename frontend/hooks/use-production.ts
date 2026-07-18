"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  CompleteProductionOrderPayload,
  CreateProductionOrderPayload,
  ProductionCapacity,
  ProductionListFilters,
  ProductionOrder,
} from "@/lib/types/production"

function filtersToParams(filters?: ProductionListFilters): string {
  const params = new URLSearchParams()
  if (filters?.status) params.set("status", filters.status)
  if (filters?.outletId) params.set("outletId", filters.outletId)
  if (filters?.from) params.set("from", filters.from)
  if (filters?.to) params.set("to", filters.to)
  if (filters?.q) params.set("q", filters.q)
  const qs = params.toString()
  return qs ? `?${qs}` : ""
}

export function useProductionOrders(filters?: ProductionListFilters) {
  return useQuery<{ orders: ProductionOrder[] }>({
    queryKey: ["production-orders", filters ?? {}],
    queryFn: () => api.get(`/v1/production${filtersToParams(filters)}`),
    staleTime: 15 * 1000,
  })
}

export function useProductionOrder(id: string | null) {
  return useQuery<ProductionOrder>({
    queryKey: ["production-orders", id],
    queryFn: () => api.get(`/v1/production?id=${id}`),
    enabled: !!id,
    staleTime: 15 * 1000,
  })
}

/**
 * Capacidad de producción dado el stock actual de insumos. `capacity: null`
 * significa receta sin insumos con control de stock (capacidad ilimitada) —
 * distinto de `capacity: 0` (sin stock suficiente / sin receta).
 */
export function useProductionCapacity(itemId: string | null, outletId: string | null) {
  return useQuery<ProductionCapacity>({
    queryKey: ["production-capacity", itemId, outletId],
    queryFn: () =>
      api.get(`/v1/production?resource=capacity&itemId=${itemId}&outletId=${outletId}`),
    enabled: !!itemId && !!outletId,
    staleTime: 10 * 1000,
  })
}

export function useCreateProductionOrder() {
  const qc = useQueryClient()
  return useMutation<ProductionOrder, Error, CreateProductionOrderPayload>({
    mutationFn: (body) =>
      api.post<ProductionOrder>("/v1/production", body as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["production-orders"] })
      qc.invalidateQueries({ queryKey: ["waste-events"] })
    },
  })
}

export function useStartProductionOrder() {
  const qc = useQueryClient()
  return useMutation<ProductionOrder, Error, string>({
    mutationFn: (id) => api.post<ProductionOrder>(`/v1/production?id=${id}&action=start`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["production-orders"] }),
  })
}

export function useCompleteProductionOrder() {
  const qc = useQueryClient()
  return useMutation<ProductionOrder, Error, { id: string; values: CompleteProductionOrderPayload }>({
    mutationFn: ({ id, values }) =>
      api.post<ProductionOrder>(
        `/v1/production?id=${id}&action=complete`,
        values as unknown as Record<string, unknown>,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["production-orders"] })
      qc.invalidateQueries({ queryKey: ["waste-events"] })
    },
  })
}

export function useCancelProductionOrder() {
  const qc = useQueryClient()
  return useMutation<ProductionOrder, Error, string>({
    mutationFn: (id) => api.post<ProductionOrder>(`/v1/production?id=${id}&action=cancel`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["production-orders"] }),
  })
}
