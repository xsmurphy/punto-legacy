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

/**
 * Reordena los impuestos (drag&drop). PUT ?resource=reorder con
 * { orderedIds }. El backend setea sortOrder=índice, scopeado por companyId.
 * Optimista: reordena la cache al instante para que la fila no "rebote" al
 * soltar; rollback si el PUT falla.
 */
export function useReorderTaxes() {
  const qc = useQueryClient()
  return useMutation<{ taxes: Tax[] }, Error, string[], { prev?: { taxes: Tax[] } }>({
    mutationFn: (orderedIds) =>
      api.put<{ taxes: Tax[] }>(
        "/v1/taxes?resource=reorder",
        { orderedIds } as unknown as Record<string, unknown>,
      ),
    onMutate: async (orderedIds) => {
      await qc.cancelQueries({ queryKey: ["taxes"] })
      const prev = qc.getQueryData<{ taxes: Tax[] }>(["taxes"])
      if (prev) {
        const byId = new Map(prev.taxes.map((t) => [t.id, t]))
        const next = orderedIds
          .map((id) => byId.get(id))
          .filter((t): t is Tax => t !== undefined)
        qc.setQueryData(["taxes"], { taxes: next })
      }
      return { prev }
    },
    onError: (_err, _ids, ctx) => {
      if (ctx?.prev) qc.setQueryData(["taxes"], ctx.prev)
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ["taxes"] }),
  })
}

export function useDeleteTax() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/taxes?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["taxes"] }),
  })
}
