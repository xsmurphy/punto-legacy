"use client"

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { CustomersKpis } from "@/hooks/use-reports"

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
  /**
   * true si el comercio abrió alguna vez una caja (en el alcance). Decide si
   * "Cajas abiertas" aplica: en 0 es un dato para quien trabaja con caja y
   * ruido para quien no. Optional por deploy desfasado — undefined = no.
   */
  usesDrawers?: boolean
}

export interface PeriodStats {
  total: number // ingresos del período (ya descontados)
  expenses: number
  revenue: number
  margin: number
  count: number // tickets
  customerAverage: number // ticket promedio
}

export interface IncomeOutcomeStatsWidget extends PeriodStats {
  /**
   * Los mismos KPIs del período inmediatamente anterior, del mismo largo
   * (`Date::previousRange()` del backend). `null` = el anterior no tuvo ni
   * ventas ni egresos: no hay contra qué comparar. Optional por deploy
   * desfasado (backend viejo no lo manda) — se trata igual que `null`.
   */
  previous?: PeriodStats | null
}

/** Ventas del período por sucursal (widget `salesByOutlet`). */
export interface SalesByOutletRow {
  outletId: string
  name: string
  total: number
  /** % del total de las filas, ya redondeado a un decimal. */
  share: number
  /** Total de esa sucursal en el período anterior; `null` = no vendió. */
  previous: number | null
}

export interface SalesByOutletWidget {
  rows: SalesByOutletRow[]
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

/**
 * Card "Clientes": los KPIs del reporte de clientes al que linkea — el backend
 * delega en el mismo `CustomersService::kpis()`. "Nuevo" = primera venta
 * histórica en el período (no el alta del contacto).
 */
export type CustomersWidget = CustomersKpis

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
// API = agregado por hora / día / semana / mes (`TimeBuckets`), BFF = shape,
// front = render con `lib/charts/granularity.ts`.

export interface IncomeChartPoint extends TimeBucket {
  ingresos: number
  egresos: number
  margen: number
}

export interface IncomeChartData {
  isDay: boolean
  granularity: Granularity
  data: IncomeChartPoint[]
  totals: {
    ingresos: number
    egresos: number
    margen: number
    average: number
  }
}

import { useQuery as useQ } from "@tanstack/react-query"
import type { Granularity, TimeBucket } from "@/lib/charts/granularity"
import { readViewScope } from "@/hooks/use-view-scope"
import type { NowWidget } from "@/lib/dashboard/visibility"

export function useIncomeChart(
  opts: { from: string; to: string },
  // Mismo tercer campo que `useDashboardWidget`: el chart pega contra
  // `/v1/reports/sales?dataset=series`, que desde el 2026-09-02 exige
  // `reports.sales.view`. Sin poder apagarlo, el dashboard le disparaba un 403
  // seguro a todo el que no tenga la clave.
  extra?: { enabled?: boolean },
) {
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
    enabled: extra?.enabled ?? true,
  })
}

// ── "Ahora" ───────────────────────────────────────────────────────────────

/**
 * Estado operativo del momento (widget `now`, `NowService`). NO depende del
 * rango del dashboard: no lleva `from`/`to` y su clave no los incluye.
 *
 * Se mantiene al día por dos caminos: la invalidación por eventos de sync del
 * tenant (`["dashboard-now"]` en `use-realtime-sync.ts`, para órdenes,
 * espacios, cajas, marcaciones, citas, compras y finanzas) y un refetch de
 * respaldo cada 60 s — la demora de una orden avanza sola con el reloj, sin
 * que ningún evento la dispare.
 */
export function useDashboardNow() {
  const scope = readViewScope()
  return useQ<NowWidget>({
    queryKey: ["dashboard-now", scope],
    queryFn: () => api.get<NowWidget>(`/v1/reports/dashboard?widget=now`),
    staleTime: 30 * 1000,
    refetchInterval: 60 * 1000,
    retry: false,
  })
}
