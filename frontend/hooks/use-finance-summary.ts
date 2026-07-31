"use client"

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { FinanceAccount } from "@/hooks/use-finance-accounts"
import type { FinanceMovement } from "@/hooks/use-finance-movements"

export interface FinanceSummary {
  accounts: FinanceAccount[]
  totalBalance: number
  period: { from: string; to: string }
  totalIncome: number
  totalExpense: number
  netFlow: number
  recentMovements: FinanceMovement[]
}

/** Resumen del dashboard de Finanzas: saldos + KPIs del período + últimos movimientos. */
export function useFinanceSummary(range?: { from?: string; to?: string }, opts?: { enabled?: boolean }) {
  const qs = new URLSearchParams()
  if (range?.from) qs.set("from", range.from)
  if (range?.to) qs.set("to", range.to)
  const query = qs.toString()
  return useQuery<FinanceSummary>({
    queryKey: ["finance", "summary", range ?? {}],
    queryFn: () => api.get<FinanceSummary>(`/v1/finance/summary${query ? "?" + query : ""}`),
    staleTime: 30_000,
    enabled: opts?.enabled ?? true,
  })
}
