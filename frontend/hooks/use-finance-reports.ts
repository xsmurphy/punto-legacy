"use client"

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface FinanceReportRow {
  id: string | null
  name: string
  /**
   * Código contable externo de la categoría o del centro de costo — lo que
   * cruza la taxonomía de Punto con el plan de cuentas del contador. `null`
   * cuando no se cargó, en la fila de lo no clasificado, y siempre en el corte
   * por cuenta, que no lleva código.
   */
  code: string | null
  income: number
  expense: number
  net: number
}

export interface FinanceReport {
  rows: FinanceReportRow[]
  period: { from: string; to: string }
}

/** Dimensiones por las que se puede cortar el reporte de Finanzas. */
export type FinanceReportDimension = "category" | "account" | "costcenter"

/**
 * Reporte de Finanzas: montos de ingresos/egresos/neto agrupados por
 * categoría, cuenta o centro de costo. Cada corte incluye una fila para lo no
 * clasificado ("Sin categoría" / "Sin centro de costo") al final.
 */
export function useFinanceReport(
  by: FinanceReportDimension,
  range?: { from?: string; to?: string },
) {
  const qs = new URLSearchParams({ by })
  if (range?.from) qs.set("from", range.from)
  if (range?.to) qs.set("to", range.to)
  return useQuery<FinanceReport>({
    queryKey: ["finance", "reports", by, range ?? {}],
    queryFn: () => api.get<FinanceReport>(`/v1/finance/reports?${qs.toString()}`),
    staleTime: 30_000,
  })
}
