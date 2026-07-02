"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/** Métodos de pago gestionables desde Ajustes. "efectivo" es fijo (cuenta Efectivo, no viaja acá). */
export const MANAGED_PAYMENT_METHODS = [
  "tarjeta_debito",
  "tarjeta_credito",
  "transferencia",
  "billetera",
  "cheque",
  "otro",
] as const

export type ManagedPaymentMethod = (typeof MANAGED_PAYMENT_METHODS)[number]

export const PAYMENT_METHOD_LABELS: Record<ManagedPaymentMethod, string> = {
  tarjeta_debito: "Tarjeta de débito",
  tarjeta_credito: "Tarjeta de crédito",
  transferencia: "Transferencia",
  billetera: "Billetera (Tigo Money, etc.)",
  cheque: "Cheque",
  otro: "Otro",
}

/** Mapa resuelto: efectivo siempre presente (cuenta Efectivo fija); el resto accountId | null. */
export type FinanceAccountMap = { efectivo: string } & Record<ManagedPaymentMethod, string | null>

export function useFinanceConfig() {
  return useQuery<FinanceAccountMap>({
    queryKey: ["finance", "config"],
    queryFn: () => api.get<FinanceAccountMap>("/v1/finance/config"),
    staleTime: 30_000,
  })
}

export function useUpdateFinanceConfig() {
  const qc = useQueryClient()
  return useMutation<FinanceAccountMap, Error, Partial<Record<ManagedPaymentMethod, string | null>>>({
    mutationFn: (map) => api.post<FinanceAccountMap>("/v1/finance/config", map),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "config"] })
    },
  })
}
