"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { PeriodCloseSummary } from "@/lib/types/period-close"

const PERIOD_CLOSE_KEY = ["period-close"]

/** D7/E1b de context/48-escalamiento-de-datos.md — estado de cierre de período por mes. */
export function usePeriodClose() {
  return useQuery<PeriodCloseSummary>({
    queryKey: PERIOD_CLOSE_KEY,
    queryFn: () => api.get<PeriodCloseSummary>("/v1/period-close"),
    staleTime: 30 * 1000,
  })
}

/** Cierra manualmente un período ('YYYY-MM') fuera de la ventana abierta del tenant. */
export function useClosePeriod() {
  const qc = useQueryClient()
  return useMutation<{ period: string; closed: true }, Error, string>({
    mutationFn: (period) =>
      api.post<{ period: string; closed: true }>("/v1/period-close", { period }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: PERIOD_CLOSE_KEY })
    },
  })
}
