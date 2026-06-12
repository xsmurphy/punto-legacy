"use client"

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * Llama a `GET /v1/reports/dashboard?widget=<name>&from=&to=` para un widget.
 * Devuelve los datos CRUDOS del backend — el componente formatea.
 *
 * Defaults: rango = últimos 7 días (matchea lo que hace el dashboard legacy).
 * Custom rangos se pasan en `opts.from` / `opts.to` (formato 'YYYY-MM-DD HH:mm:ss').
 */
export function useDashboardWidget<T>(
  widget: string,
  opts?: { from?: string; to?: string; enabled?: boolean },
) {
  const params = new URLSearchParams({ widget })
  if (opts?.from) params.set("from", opts.from)
  if (opts?.to) params.set("to", opts.to)

  return useQuery<T>({
    queryKey: ["dashboard-widget", widget, opts?.from, opts?.to],
    queryFn: () => api.get<T>(`/v1/reports/dashboard?${params.toString()}`),
    staleTime: 60 * 1000, // 1 min — datos transaccionales cambian frecuente
    enabled: opts?.enabled ?? true,
    retry: false,
  })
}

// Shapes de los widgets que consumimos en el dashboard. Espejo de
// api/lib/Reports/DashboardService.php — no formatean, solo tipan.
export interface InfoWidget {
  giftCardsCount: number
  openDrawersCount: number
  outletsCount: number
  plan: string
  usersCount: number
  usersMax: number
  itemsCount: number
  itemsMax: number
  transactionsCount: number
}

export interface IncomeOutcomeStatsWidget {
  total: number // ingresos del período (ya descontados)
  expenses: number
  revenue: number
  margin: number
  count: number // tickets
  customerAverage: number // ticket promedio
}

export interface PaymentStatusWidget {
  contado: number
  credito: number
  cobrado: number
  porcobrar: number
  contadoCount: number
  creditoCount: number
  cobradoCount: number
  porcobrarCount: number
}

export interface CustomersWidget {
  total: number       // total de clientes
  totalPeriod: number // nuevos + recurrentes del período
  new: number         // nuevos en el período
  old: number         // recurrentes (volvieron) en el período
  returnRate: number  // % retorno
}

export interface CustomersRatesWidget {
  retention?: number
  growth?: number
  churn?: number
  // shape exacto depende del helper customersRate del backend
  [key: string]: unknown
}

export interface TopItemRow {
  name: string
  count: number
  total: number
}

export interface TopTaxonomyRow {
  title: string
  total: number
}

export interface SatisfactionWidget {
  detractors: { percent: number; count: number }
  passives: { percent: number; count: number }
  promoters: { percent: number; count: number }
}

export interface OrdersWidget {
  ordersCount: number
  onlineCount: number
}
