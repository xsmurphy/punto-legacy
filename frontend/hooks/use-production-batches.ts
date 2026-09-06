"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  CreateProductionBatchPayload,
  EstimateBatchPayload,
  ProductionBatch,
  ProductionBatchEstimate,
  ProductionBatchListFilters,
} from "@/lib/types/production-batch"

function filtersToParams(filters?: ProductionBatchListFilters): string {
  const params = new URLSearchParams()
  if (filters?.status) params.set("status", filters.status)
  if (filters?.outletId) params.set("outletId", filters.outletId)
  if (filters?.from) params.set("from", filters.from)
  if (filters?.to) params.set("to", filters.to)
  const qs = params.toString()
  return qs ? `?${qs}` : ""
}

export function useProductionBatches(filters?: ProductionBatchListFilters) {
  return useQuery<{ batches: ProductionBatch[] }>({
    queryKey: ["production-batches", filters ?? {}],
    queryFn: () => api.get(`/v1/production-batches${filtersToParams(filters)}`),
    staleTime: 15 * 1000,
  })
}

export function useProductionBatch(id: string | null) {
  return useQuery<ProductionBatch>({
    queryKey: ["production-batches", id],
    queryFn: () => api.get(`/v1/production-batches?id=${id}`),
    enabled: !!id,
    staleTime: 15 * 1000,
  })
}

/**
 * Necesidad consolidada por insumo. Es una LECTURA PURA: no escribe nada, así
 * que se puede recalcular mientras el operador edita las líneas.
 *
 * Va por POST porque la entrada es una lista de N pares {plato, cantidad} —
 * serializarla en la query string la vuelve frágil (largo, orden, escaping) y
 * la deja en el historial del browser. El verbo es por el BODY, no por el
 * efecto. Por eso NO es `useMutation` conceptualmente pero sí `useQuery`: se
 * quiere el cacheo y el refetch de una lectura.
 *
 * `enabled` corta cuando no hay sucursal o no hay ninguna línea con cantidad —
 * sin eso la pantalla dispararía un request por cada tecla antes de que haya
 * algo que calcular.
 */
export function useProductionBatchEstimate(payload: EstimateBatchPayload | null) {
  return useQuery<ProductionBatchEstimate>({
    queryKey: ["production-batch-estimate", payload],
    queryFn: () =>
      api.post<ProductionBatchEstimate>(
        "/v1/production-batches?resource=estimate",
        payload as unknown as Record<string, unknown>,
      ),
    enabled: !!payload && !!payload.outletId && payload.lines.length > 0,
    staleTime: 10 * 1000,
  })
}

export function useCreateProductionBatch() {
  const qc = useQueryClient()
  return useMutation<ProductionBatch, Error, CreateProductionBatchPayload>({
    mutationFn: (body) =>
      api.post<ProductionBatch>(
        "/v1/production-batches",
        body as unknown as Record<string, unknown>,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["production-batches"] })
      // El lote crea órdenes hijas: el listado de producción también cambió.
      qc.invalidateQueries({ queryKey: ["production-orders"] })
    },
  })
}

/**
 * Confirma el lote: completa TODAS sus líneas. Mueve stock, así que además de
 * los lotes y las órdenes hay que invalidar lo que depende del inventario.
 */
export function useConfirmProductionBatch() {
  const qc = useQueryClient()
  return useMutation<ProductionBatch, Error, string>({
    mutationFn: (id) =>
      api.post<ProductionBatch>(`/v1/production-batches?id=${id}&action=confirm`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["production-batches"] })
      qc.invalidateQueries({ queryKey: ["production-orders"] })
      qc.invalidateQueries({ queryKey: ["production-batch-estimate"] })
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["stock"] })
    },
  })
}

export function useCancelProductionBatch() {
  const qc = useQueryClient()
  return useMutation<ProductionBatch, Error, string>({
    mutationFn: (id) =>
      api.post<ProductionBatch>(`/v1/production-batches?id=${id}&action=cancel`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["production-batches"] })
      qc.invalidateQueries({ queryKey: ["production-orders"] })
    },
  })
}
