"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { Tax, TaxPayload } from "@/lib/types/tax"

/**
 * CRUD de impuestos (Slice 3 del refactor taxonomy).
 *
 * Endpoint /v1/taxes — tabla `tax` dedicada. POS sigue leyendo de
 * `taxonomy` con sync automático vía triggers PG. Facturación electrónica
 * NO se afecta.
 */
export function useTaxes() {
  return useQuery<{ taxes: Tax[] }>({
    queryKey: ["taxes"],
    queryFn: () => api.get("/v1/taxes"),
    staleTime: 5 * 60 * 1000,
  })
}

export function useTax(id: string | undefined) {
  return useQuery<Tax>({
    queryKey: ["taxes", id],
    queryFn: () => api.get<Tax>(`/v1/taxes?id=${id}`),
    enabled: !!id,
    staleTime: 60 * 1000,
  })
}

export function useCreateTax() {
  const qc = useQueryClient()
  return useMutation<Tax, Error, TaxPayload>({
    mutationFn: (body) =>
      api.post<Tax>("/v1/taxes", body as unknown as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["taxes"] }),
  })
}

export function useUpdateTax() {
  const qc = useQueryClient()
  return useMutation<Tax, Error, { id: string; values: Partial<TaxPayload> }>({
    mutationFn: ({ id, values }) =>
      api.put<Tax>(`/v1/taxes?id=${id}`, values as unknown as Record<string, unknown>),
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: ["taxes"] })
      qc.invalidateQueries({ queryKey: ["taxes", id] })
    },
  })
}

export function useDeleteTax() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/taxes?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["taxes"] }),
  })
}
