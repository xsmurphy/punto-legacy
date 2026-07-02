"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/** Método de pago real del tenant (taxonomía paymentMethod) con su cuenta asignada. */
export interface FinanceConfigMethod {
  methodId: string
  methodName: string
  accountId: string | null
  isCash: boolean
}

export type FinanceConfigResponse = FinanceConfigMethod[]

export function useFinanceConfig() {
  return useQuery<FinanceConfigResponse>({
    queryKey: ["finance", "config"],
    queryFn: () => api.get<FinanceConfigResponse>("/v1/finance/config"),
    staleTime: 30_000,
  })
}

export function useUpdateFinanceConfig() {
  const qc = useQueryClient()
  return useMutation<FinanceConfigResponse, Error, Record<string, string | null>>({
    mutationFn: (map) => api.post<FinanceConfigResponse>("/v1/finance/config", map),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "config"] })
    },
  })
}
