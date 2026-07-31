"use client"

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export type ForecastRowType = "check" | "loan_installment" | "purchase"

export interface ForecastRow {
  id: string
  type: ForecastRowType
  label: string
  party: string | null
  dueDate: string
  amount: number
  /** Ruta al módulo origen — la fila linkea ahí, la acción vive en cada módulo. */
  link: string
}

export interface FinanceForecastResponse {
  from: string
  to: string
  /** Egresos futuros: cheques emitidos, cuotas de crédito, compras impagas. */
  obligations: ForecastRow[]
  /** Ingresos futuros conocidos: cheques recibidos pendientes de cobrar. */
  income: ForecastRow[]
}

export interface FinanceForecastFilters {
  from?: string
  to?: string
}

/** Previsión (F3, context/30) — lista de vencimientos futuros, solo lectura. */
export function useFinanceForecast(
  filters: FinanceForecastFilters = {},
  opts?: { enabled?: boolean },
) {
  const qs = new URLSearchParams()
  if (filters.from) qs.set("from", filters.from)
  if (filters.to) qs.set("to", filters.to)
  const query = qs.toString()
  return useQuery<FinanceForecastResponse>({
    queryKey: ["finance", "forecast", filters],
    queryFn: () => api.get<FinanceForecastResponse>(`/v1/finance/forecast${query ? "?" + query : ""}`),
    staleTime: 15_000,
    enabled: opts?.enabled ?? true,
  })
}
