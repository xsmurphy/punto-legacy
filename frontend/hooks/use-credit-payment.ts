"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

interface CreateCreditPaymentVars {
  parentTransactionId: string
  amount: number
  paymentMethodKey: string
  // paymentMethodName resuelto server-side — no enviar al backend
  note?: string
  // Identificador del pago (nº de operación, voucher) para métodos que lo exigen
  identifier?: string
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
    onSuccess: () => {
      // refetchType:"all" fuerza el refetch aunque la query esté inactiva: el
      // diálogo de pago (modal) tapa el detalle, así que la query del detalle
      // puede no estar "activa" al confirmar → sin esto se marca stale pero NO
      // refetchea, y la deuda mostrada no baja hasta reabrir.
      // Usamos el prefijo ["pos-transaction"] (sin id) para pegarle al detalle
      // sea cual sea el formato de id.
      qc.invalidateQueries({ queryKey: ["pos-transactions"], refetchType: "all" })
      qc.invalidateQueries({ queryKey: ["pos-transaction"], refetchType: "all" })
      qc.invalidateQueries({ queryKey: ["transactions"], refetchType: "all" })
      qc.invalidateQueries({ queryKey: ["reports", "transactions"] })
    },
  })
}
