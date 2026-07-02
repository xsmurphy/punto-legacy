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
export function usePaymentMethods() {
  return useQuery<{ paymentMethods: PaymentMethod[] }>({
    queryKey: ["payment-methods"],
    queryFn: () => api.get("/v1/payment-methods"),
    staleTime: 5 * 60 * 1000,
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
