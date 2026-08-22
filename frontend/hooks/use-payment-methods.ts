"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { PaymentMethod, PaymentMethodPayload } from "@/lib/types/payment-method"

/**
 * CRUD de medios de pago del tenant. Endpoint /v1/payment-methods
 * (tabla taxonomy, taxonomyType='paymentMethod'). El backend auto-seedea
 * los 3 defaults (Efectivo/T.Crédito/T.Débito) en el primer GET si el tenant
 * no tiene ninguno.
 */
export function usePaymentMethods(options?: { enabled?: boolean }) {
  return useQuery<{ paymentMethods: PaymentMethod[] }>({
    queryKey: ["payment-methods"],
    queryFn: () => api.get("/v1/payment-methods"),
    staleTime: 5 * 60 * 1000,
    enabled: options?.enabled ?? true,
  })
}

export function useCreatePaymentMethod() {
  const qc = useQueryClient()
  return useMutation<PaymentMethod, Error, PaymentMethodPayload>({
    mutationFn: (body) =>
      api.post<PaymentMethod>(
        "/v1/payment-methods",
        body as unknown as Record<string, unknown>,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["payment-methods"] })
      // El accountId se persiste en finAccountMap → invalidar la config de Finanzas.
      qc.invalidateQueries({ queryKey: ["finance", "config"] })
    },
  })
}

export function useUpdatePaymentMethod() {
  const qc = useQueryClient()
  return useMutation<PaymentMethod, Error, { id: string; values: Partial<PaymentMethodPayload> }>({
    mutationFn: ({ id, values }) =>
      api.put<PaymentMethod>(
        `/v1/payment-methods?id=${id}`,
        values as unknown as Record<string, unknown>,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["payment-methods"] })
      qc.invalidateQueries({ queryKey: ["finance", "config"] })
    },
  })
}

/**
 * Reordena los medios de pago (drag&drop). PUT ?resource=reorder con
 * { orderedIds }. El backend setea sortOrder=índice, scopeado por companyId.
 * Optimista: reordena la cache al instante para que la fila no "rebote" al
 * soltar; rollback si el PUT falla.
 */
export function useReorderPaymentMethods() {
  const qc = useQueryClient()
  return useMutation<
    { paymentMethods: PaymentMethod[] },
    Error,
    string[],
    { prev?: { paymentMethods: PaymentMethod[] } }
  >({
    mutationFn: (orderedIds) =>
      api.put<{ paymentMethods: PaymentMethod[] }>(
        "/v1/payment-methods?resource=reorder",
        { orderedIds } as unknown as Record<string, unknown>,
      ),
    onMutate: async (orderedIds) => {
      await qc.cancelQueries({ queryKey: ["payment-methods"] })
      const prev = qc.getQueryData<{ paymentMethods: PaymentMethod[] }>(["payment-methods"])
      if (prev) {
        const byId = new Map(prev.paymentMethods.map((m) => [m.id, m]))
        const next = orderedIds
          .map((id) => byId.get(id))
          .filter((m): m is PaymentMethod => m !== undefined)
        qc.setQueryData(["payment-methods"], { paymentMethods: next })
      }
      return { prev }
    },
    onError: (_err, _ids, ctx) => {
      if (ctx?.prev) qc.setQueryData(["payment-methods"], ctx.prev)
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ["payment-methods"] }),
  })
}

export function useDeletePaymentMethod() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/payment-methods?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["payment-methods"] })
      qc.invalidateQueries({ queryKey: ["finance", "config"] })
    },
  })
}
