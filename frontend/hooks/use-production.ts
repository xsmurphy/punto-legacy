"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  CompleteProductionOrderPayload,
  CreateProductionOrderPayload,
  ProducibleNow,
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
 * Capacidad de producción dado el stock actual de insumos, en UNA sucursal.
 * `capacity: null` significa que ningún insumo con control de stock limita (no
 * hay número que dar) — distinto de `capacity: 0`, que es "no se puede producir
 * ni una". Un ítem sin receta devuelve 0 con `ingredients` vacío.
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

/**
 * "Producibles ahora" de la ficha del artículo: la misma capacidad, POR
 * SUCURSAL. La sucursal NO viaja en la query — sale del view-scope, o sea del
 * header `X-Outlet-Id` que `api-client` ya manda: con una sucursal elegida en el
 * selector del panel vuelve esa sola, en consolidado vuelven las asignadas al
 * usuario. Mismo criterio de alcance que el resto de los lectores del panel.
 */
export function useProducibleNow(itemId: string | undefined, enabled = true) {
  return useQuery<ProducibleNow>({
    queryKey: ["producible-now", itemId],
    queryFn: () => api.get(`/v1/production?resource=producible&itemId=${itemId}`),
    enabled: !!itemId && enabled,
    // Es una lectura del momento: cualquier venta, compra o ajuste la mueve.
    // El evento realtime `item` la invalida (use-realtime-sync.ts); el
    // staleTime corto es el piso para el caso sin websocket.
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
