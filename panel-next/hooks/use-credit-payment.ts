"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

interface CreateCreditPaymentVars {
  parentTransactionId: string
  amount: number
  paymentMethodKey: string
  // paymentMethodName resuelto server-side — no enviar al backend
  note?: string
}

interface CreateCreditPaymentResult {
  id: string
  parentComplete: boolean
  paid: number
  debtRemaining: number
}

export function useCreateCreditPayment() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (vars: CreateCreditPaymentVars) =>
      api.post<CreateCreditPaymentResult>("/v1/credit-payments", {
        action: "create",
        ...vars,
      }),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ["pos-transactions"] })
      qc.invalidateQueries({ queryKey: ["pos-transaction", vars.parentTransactionId] })
      qc.invalidateQueries({ queryKey: ["transactions"] })
      qc.invalidateQueries({ queryKey: ["reports", "transactions"] })
    },
  })
}
