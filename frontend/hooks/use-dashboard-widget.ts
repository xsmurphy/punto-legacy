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
  /** Transacciones del MES calendario actual (no del rango elegido). */
  transactionsCount: number
  /**
   * true si el tenant vendió ALGUNA VEZ (lifetime). Es la señal del hero de
   * bienvenida del dashboard — transactionsCount es mensual y gatear por él
   * mostraba la bienvenida cada día 1 del mes a cuentas con historial.
   * Optional: backend viejo no lo manda (deploy desfasado) — tratar
   * undefined como "no sé", no como "nunca vendió".
   */
  hasSales?: boolean
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

export interface TablesWidget {
  tablesCount: number
  totalTables: number
  occupacy: number
  freeTables: number
}

export interface ScheduleWidget {
  scheduledCount: number
  occupancy: number
  shiftHours: number
  workingHours: number
  freeHours: number
  blockedHours: number
}

export interface TopHoursWidget {
  hour: string[]  // ej. "14:00 Ventas"
  total: number[] // mismo orden que hour, tickets vendidos en esa hora
}

// ── Income chart (BFF) ────────────────────────────────────────────────────
// Llama al route handler de frontend (/api/dashboard/income-chart) que
// hace el reshape del raw /v1/reports/sales?dataset=series. Arquitectura:
// API = raw data, BFF = shape, front = render.

export interface IncomeChartPoint {
  bucket: string
  ingresos: number
  egresos: number
  margen: number
}

export interface IncomeChartData {
  isDay: boolean
  data: IncomeChartPoint[]
  totals: {
    ingresos: number
    egresos: number
    margen: number
    average: number
  }
}

import { useQuery as useQ } from "@tanstack/react-query"
import { readViewScope } from "@/hooks/use-view-scope"

export function useIncomeChart(opts: { from: string; to: string }) {
  // El scope va en el queryKey para que React Query refetchee al cambiar de
  // sucursal. El header `X-Outlet-Id` NO se manda a mano: lo pone el api-client.
  const scope = readViewScope()
  return useQ<IncomeChartData>({
    queryKey: ["bff", "income-chart", opts.from, opts.to, scope],
    // Vía `api` y no `fetch` crudo: el api-client es el único lugar que sabe
    // adjuntar la credencial del panel (Bearer, context/54 F1), el view-scope y
    // el unwrap del envelope `{ok,data}`.
    //
    // Con `fetch` crudo + `credentials: "include"` esto se rompió apenas el
    // panel dejó la cookie: el chart era el ÚNICO widget que se autenticaba solo
    // —viajaba la cookie sin que nadie la mandara— y quedó sin credencial,
    // devolviendo "BFF 401" mientras el resto del dashboard cargaba normal. Un
    // `fetch` directo a `/api/*` desde el panel vuelve a introducir ese agujero;
    // lo bloquea el guard `lib/auth/__tests__/realm-token-separation.test.ts`.
    queryFn: () => {
      const params = new URLSearchParams({ from: opts.from, to: opts.to })
      return api.get<IncomeChartData>(`/dashboard/income-chart?${params.toString()}`)
    },
    staleTime: 60 * 1000,
    retry: false,
  })
}
