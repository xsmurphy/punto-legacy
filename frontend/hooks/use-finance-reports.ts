"use client"

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface FinanceReportRow {
  id: string | null
  name: string
  income: number
  expense: number
  net: number
}

export interface FinanceReport {
  rows: FinanceReportRow[]
  period: { from: string; to: string }
}

/** Reporte de Finanzas: montos de ingresos/egresos/neto agrupados por categoría o cuenta. */
export function useFinanceReport(by: "category" | "account", range?: { from?: string; to?: string }) {
  const qs = new URLSearchParams({ by })
  if (range?.from) qs.set("from", range.from)
  if (range?.to) qs.set("to", range.to)
  return useQuery<FinanceReport>({
    queryKey: ["finance", "reports", by, range ?? {}],
    queryFn: () => api.get<FinanceReport>(`/v1/finance/reports?${qs.toString()}`),
    staleTime: 30_000,
  })
}
