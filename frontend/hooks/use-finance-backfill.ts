"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/** Resultado de POST /v1/finance/backfill (Fase 3 — importar histórico). */
export interface FinanceBackfillResult {
  sales: number
  creditPayments: number
  purchases: number
  drawerExpenses: number
  drawerIncomes: number
  errors: number
  movementsBefore: number
  movementsAfter: number
  movementsCreated: number
}

/** Reejecutable sin duplicar — el backend es idempotente (UNIQUE por cuenta). */
export function useFinanceBackfill() {
  const qc = useQueryClient()
  return useMutation<FinanceBackfillResult, Error, void>({
    mutationFn: () => api.post<FinanceBackfillResult>("/v1/finance/backfill", {}),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance"] })
    },
  })
}
