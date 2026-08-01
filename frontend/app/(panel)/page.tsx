"use client"

import * as React from "react"
import Link from "next/link"
import {
  ArrowDownRight,
  ArrowUpRight,
  ChevronRight,
  TrendingUp,
} from "lucide-react"
import { EmptyState } from "@/components/empty-state"
import { Hero115 } from "@/components/hero115"
import { PuntoLogo } from "@/components/layout/punto-logo"

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ComposedChart,
  Line,
  Pie,
  PieChart,
  ResponsiveContainer,
  XAxis,
  YAxis,
} from "recharts"

import { Card, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"
import { useFinanceSummary } from "@/hooks/use-finance-summary"
import {
  useFinanceForecast,
  type ForecastRow,
  type ForecastRowType,
} from "@/hooks/use-finance-forecast"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import {
  useDashboardWidget,
  useIncomeChart,
  type CustomersRatesWidget,
  type CustomersWidget,
  type IncomeChartData,
  type IncomeOutcomeStatsWidget,
  type InfoWidget,
  type PaymentStatusWidget,
  type SatisfactionWidget,
  type TopHoursWidget,
  type TopItemRow,
  type TopTaxonomyRow,
} from "@/hooks/use-dashboard-widget"
import { formatInt, formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { cn } from "@/lib/utils"

/**
 * Dashboard — espejo del panel legacy con widgets agrupados.
 * Cada sección consulta su propio widget. Se siguen las shapes de
 * api/lib/Reports/DashboardService.php.
 */
export default function DashboardPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const stats = useDashboardWidget<IncomeOutcomeStatsWidget>("incomeOutcomeStats", opts)
  const info = useDashboardWidget<InfoWidget>("info", opts)
  const incomeChart = useIncomeChart(opts)
  const paymentStatus = useDashboardWidget<PaymentStatusWidget>("paymentStatus", opts)
  const customers = useDashboardWidget<CustomersWidget>("customers", opts)
  const customersRates = useDashboardWidget<CustomersRatesWidget>("customersRates", opts)
  const topItems = useDashboardWidget<TopItemRow[]>("topItems", opts)
  const topCategories = useDashboardWidget<TopTaxonomyRow[]>("topCategories", opts)
  const topHours = useDashboardWidget<TopHoursWidget>("topHours", opts)
  // NPS oculto (ver comentario en <aside>) — fetch de "satisfaction" removido:
  // quedaría huérfano sin SatisfactionCard montado.

  // "Negocio sin actividad" = NUNCA vendió (lifetime, info.hasSales).
  // No gateamos por itemsCount/clientes: al crear la cuenta se seedean
  // artículos y contactos, así que esos nunca son 0. Tampoco por
  // transactionsCount: es del MES calendario, y usarlo acá mostraba
  // "Bienvenido a Punto" cada día 1 del mes a cuentas con historial de
  // ventas (bug 2026-08-01). Fallback al gate mensual solo si hasSales no
  // vino (backend sin deployar) — undefined nunca fuerza el hero solo.
  const isEmptyState =
    !info.isLoading &&
    !info.error &&
    (info.data?.hasSales !== undefined
      ? info.data.hasSales === false
      : (info.data?.transactionsCount ?? 1) === 0)

  if (isEmptyState) {
    return (
      <Hero115
        className="py-12"
        icon={<PuntoLogo variant="mark" className="size-10" />}
        heading="Bienvenido a Punto"
        description="El sistema que reúne caja, inventario, clientes y reportes para que vendas más y controles todo tu negocio desde un solo lugar."
        buttons={{ primary: { text: "Ir a la caja", url: "/pos" } }}
        byline="Empezá a vender y hacé crecer tu negocio."
      />
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Resumen general de su negocio</h1>
          <p className="text-sm text-muted-foreground">
            Datos del período seleccionado
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {/* Layout 2-col espejo del legacy (8/4): main col con widgets de negocio,
          sidebar derecho con resumen/módulos opcionales/plan. Stack en <lg. */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_22rem]">
        {/* ── MAIN COLUMN ────────────────────────────────────────────────── */}
        <div className="flex min-w-0 flex-col gap-4">
          {/* KPI ROW — 2 cards grandes: Ingresos y Egresos. Tickets / Ticket
              promedio se mueven a la card "Información general" en sidebar. */}
          <section className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <BigMetricCard
              label="Ingresos"
              href="/reports/summary"
              value={fmtMoney(stats.data?.total, bootstrap, stats.isLoading)}
              isLoading={stats.isLoading}
              sparkline={incomeChart.data?.data.map((p) => p.ingresos)}
              sparklineColor="var(--chart-1)"
              trend="up"
            />
            <BigMetricCard
              label="Egresos"
              href="/purchase"
              value={fmtMoney(stats.data?.expenses, bootstrap, stats.isLoading)}
              isLoading={stats.isLoading}
              sparkline={incomeChart.data?.data.map((p) => p.egresos)}
              sparklineColor="var(--muted-foreground)"
              trend="down"
            />
          </section>

          {/* Chart Ingresos vs Egresos + Margen — con sidebar de KPIs derivados
              (Ganancia / Margen% / Cant. Ventas) a la derecha en lg+. */}
          <section className="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_15rem]">
            <IncomeOutcomeChart
              data={incomeChart.data}
              isLoading={incomeChart.isLoading}
              error={incomeChart.error}
              bootstrap={bootstrap}
            />
            <div className="flex flex-col self-start">
              <div className="flex flex-col items-center gap-1 py-6">
                <span className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                  Ganancia
                </span>
                {stats.isLoading ? (
                  <Skeleton className="h-8 w-32" />
                ) : (
                  <span className="text-2xl font-bold tabular-nums text-[var(--chart-1)]">
                    {formatMoney(stats.data?.revenue, bootstrap)}
                  </span>
                )}
              </div>
              <div className="grid grid-cols-2 divide-x divide-border border-t py-4">
                <div className="flex flex-col items-center gap-1">
                  <span className="text-[10px] uppercase tracking-wider text-muted-foreground">
                    Margen
                  </span>
                  {stats.isLoading ? (
                    <Skeleton className="h-6 w-12" />
                  ) : (
                    <span className="text-xl font-bold tabular-nums">
                      {stats.data?.margin ?? 0}%
                    </span>
                  )}
                </div>
                <div className="flex flex-col items-center gap-1">
                  <span className="text-[10px] uppercase tracking-wider text-muted-foreground">
                    Cant. Ventas
                  </span>
                  {stats.isLoading ? (
                    <Skeleton className="h-6 w-12" />
                  ) : (
                    <span className="text-xl font-bold tabular-nums">
                      {formatInt(stats.data?.count, bootstrap)}
                    </span>
                  )}
                </div>
              </div>
            </div>
          </section>

          {/* Tipos de ventas + Cuentas por cobrar — donuts lado a lado */}
          <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <PaymentSplitCard
              title="Tipos de venta"
              data={paymentStatus.data}
              isLoading={paymentStatus.isLoading}
              bootstrap={bootstrap}
              mode="sale-type"
            />
            <PaymentSplitCard
              title="Cuentas por cobrar"
              data={paymentStatus.data}
              isLoading={paymentStatus.isLoading}
              bootstrap={bootstrap}
              mode="receivables"
            />
          </section>

          {/* Top 5 Artículos (Clientes se movió a la sidebar) */}
          <section>
            <TopItemsCard
              data={topItems.data ?? []}
              isLoading={topItems.isLoading}
              bootstrap={bootstrap}
            />
          </section>

          {/* Horarios Pico + Top 10 Categorías */}
          <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <TopHoursCard data={topHours.data} isLoading={topHours.isLoading} />
            <TopCategoriesCard
              data={topCategories.data ?? []}
              isLoading={topCategories.isLoading}
            />
          </section>
        </div>

        {/* ── SIDEBAR ────────────────────────────────────────────────────── */}
        <aside className="flex min-w-0 flex-col gap-4">
          <FinanceCard />
          {/* NPS oculto a pedido del owner — el módulo de satisfacción de
              clientes todavía no está desarrollado. Componente y helpers
              (SatisfactionCard, NpsTooltipRow) quedan dormidos: la feature
              vuelve más adelante. */}
          <CustomersCard
            data={customers.data}
            rates={customersRates.data}
            isLoading={customers.isLoading}
          />
          <InfoGeneralCard
            stats={stats.data}
            info={info.data}
            customers={customers.data}
            loading={stats.isLoading || info.isLoading}
            bootstrap={bootstrap}
          />
          <PlanSidebarCard info={info.data} loading={info.isLoading} bootstrap={bootstrap} />
        </aside>
      </div>
    </div>
  )
}

// ── KPI cards ──────────────────────────────────────────────────────────────

/**
 * BigMetricCard — KPI card grande con label uppercase, valor enorme y
 * sparkline al pie. Sin íconos en el title. La flecha trend va al lado del
 * label como indicador semántico.
 */
function BigMetricCard({
  label,
  href,
  value,
  isLoading,
  sparkline,
  sparklineColor,
  trend,
}: {
  label: string
  href?: string
  value: React.ReactNode
  isLoading: boolean
  sparkline?: number[]
  sparklineColor?: string
  trend?: "up" | "down"
}) {
  const TrendIcon = trend === "up" ? ArrowUpRight : trend === "down" ? ArrowDownRight : null
  const trendColor =
    trend === "up"
      ? "text-[var(--chart-1)]"
      : trend === "down"
        ? "text-[var(--muted-foreground)]"
        : ""

  return (
    <Card className="relative overflow-hidden">
      <CardContent className="flex flex-col gap-3">
        <div className="flex items-start justify-between gap-2">
          <div className="flex min-w-0 items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
            {TrendIcon && <TrendIcon className={cn("size-3.5 shrink-0", trendColor)} />}
            <span className="truncate">{label}</span>
          </div>
          {href && (
            <Link
              href={href}
              className="text-muted-foreground transition-colors hover:text-foreground"
              aria-label={`Ir a ${label}`}
            >
              <ChevronRight className="size-4" />
            </Link>
          )}
        </div>

        {isLoading ? (
          <Skeleton className="h-10 w-40" />
        ) : (
          <div className="flex items-baseline gap-2">
            <span className="text-3xl font-bold tracking-tight tabular-nums">{value}</span>
          </div>
        )}

        {sparkline && sparkline.length > 1 && (
          <Sparkline values={sparkline} color={sparklineColor ?? "var(--chart-1)"} />
        )}
      </CardContent>
    </Card>
  )
}

/**
 * Sparkline — mini area chart sin ejes ni labels. Recharts ResponsiveContainer
 * adentro de un wrapper de altura fija para que se ancle al ancho del card.
 */
function Sparkline({ values, color }: { values: number[]; color: string }) {
  const data = React.useMemo(
    () => values.map((v, i) => ({ i, v })),
    [values],
  )
  const gradId = React.useId()
  return (
    <div className="-mx-1 mt-1 h-12">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 2, right: 2, bottom: 2, left: 2 }}>
          <defs>
            <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={color} stopOpacity={0.25} />
              <stop offset="100%" stopColor={color} stopOpacity={0} />
            </linearGradient>
          </defs>
          <Area
            type="monotone"
            dataKey="v"
            stroke={color}
            strokeWidth={1.5}
            fill={`url(#${gradId})`}
            isAnimationActive={false}
            dot={false}
            activeDot={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}

// ── Income chart (ComposedChart Bars + Line vía shadcn) ───────────────────

const incomeChartConfig = {
  ingresos: { label: "Ingresos", color: "var(--chart-1)" },
  egresos: { label: "Egresos", color: "var(--muted-foreground)" },
  margen: { label: "Margen", color: "var(--chart-3)" },
} satisfies ChartConfig

function IncomeOutcomeChart({
  data,
  isLoading,
  error,
  bootstrap,
}: {
  data: IncomeChartData | undefined
  isLoading: boolean
  error: Error | null
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  if (isLoading) {
    return (
      <div className="flex flex-col gap-2">
        <h3 className="text-base font-semibold tracking-tight">
          Margen, Ingresos y Egresos
        </h3>
        <Skeleton className="h-[240px] w-full" />
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="flex flex-col gap-2">
        <h3 className="text-base font-semibold tracking-tight">
          Margen, Ingresos y Egresos
        </h3>
        <div className="flex h-[240px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
          {error?.message || "No se pudieron cargar los datos del chart."}
        </div>
      </div>
    )
  }

  const hasData = data.data.some((p) => p.ingresos > 0 || p.egresos > 0)
  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-center justify-between text-sm font-medium">
        <span>Margen, Ingresos y Egresos</span>
        <span className="text-xs font-normal text-muted-foreground">
          Promedio: {formatMoney(data.totals.average, bootstrap)}
        </span>
      </div>
      <div>
        {!hasData ? (
          <div className="flex h-[240px] items-center justify-center">
            <EmptyState
              icon={TrendingUp}
              title="Sin movimientos en este período"
              showMarquee={false}
              className="border-0 p-0"
            />
          </div>
        ) : (
          <ChartContainer config={incomeChartConfig} className="h-[240px] w-full">
            <ComposedChart data={data.data} margin={{ top: 10, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey="bucket"
                tickFormatter={(v: string) => formatBucketLabel(v, data.isDay)}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickFormatter={(v: number) => compactNumber(v)}
                tickLine={false}
                axisLine={false}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={
                  <ChartTooltipContent
                    labelFormatter={(label) => formatBucketLabel(String(label), data.isDay)}
                    formatter={(value, name) => (
                      <div className="flex w-full items-center justify-between gap-3">
                        <span className="text-muted-foreground">
                          {incomeChartConfig[name as keyof typeof incomeChartConfig]?.label ?? name}
                        </span>
                        <span className="font-medium tabular-nums">
                          {formatMoney(Number(value) || 0, bootstrap)}
                        </span>
                      </div>
                    )}
                  />
                }
              />
              <ChartLegend content={<ChartLegendContent />} />
              <Bar
                dataKey="ingresos"
                fill="var(--color-ingresos)"
                radius={[4, 4, 0, 0]}
                maxBarSize={32}
              />
              <Bar
                dataKey="egresos"
                fill="var(--color-egresos)"
                radius={[4, 4, 0, 0]}
                maxBarSize={32}
              />
              <Line
                type="monotone"
                dataKey="margen"
                stroke="var(--color-margen)"
                strokeWidth={2}
                dot={false}
              />
            </ComposedChart>
          </ChartContainer>
        )}
      </div>
    </div>
  )
}

function formatBucketLabel(b: string, isDay: boolean): string {
  if (isDay) {
    return String(b).padStart(2, "0") + "h"
  }
  // 'YYYY-MM-DD' → 'DD/MM'
  if (/^\d{4}-\d{2}-\d{2}$/.test(b)) {
    return b.slice(8) + "/" + b.slice(5, 7)
  }
  return b
}

function compactNumber(v: number): string {
  if (Math.abs(v) >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (Math.abs(v) >= 1_000) return `${(v / 1_000).toFixed(0)}k`
  return String(Math.round(v))
}

// ── Finanzas ──────────────────────────────────────────────────────────────

const FORECAST_TYPE_LABELS: Record<ForecastRowType, string> = {
  check: "Cheque",
  loan_installment: "Cuota",
  purchase: "Factura",
}

function isForecastOverdue(dueDate: string): boolean {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return new Date(dueDate).getTime() < today.getTime()
}

function addDaysISO(base: Date, days: number): string {
  const d = new Date(base.getTime() + days * 24 * 60 * 60 * 1000)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`
}

/**
 * Card de Finanzas en el dashboard — mismo gate que el link "Finanzas" del
 * sidebar nav (permiso `finance.manage`). Sin placeholder: si el usuario no
 * tiene el permiso, la card no se monta (ni dispara sus fetches).
 */
function FinanceCard() {
  const canManageFinance = usePermission("finance.manage")
  const { data: bootstrap } = useBootstrap()

  const forecastRange = React.useMemo(() => ({ to: addDaysISO(new Date(), 7) }), [])

  const summary = useFinanceSummary(undefined, { enabled: canManageFinance })
  const forecast = useFinanceForecast(forecastRange, { enabled: canManageFinance })

  if (!canManageFinance) return null

  const obligations = [...(forecast.data?.obligations ?? [])].sort((a, b) => {
    const overdueA = isForecastOverdue(a.dueDate)
    const overdueB = isForecastOverdue(b.dueDate)
    if (overdueA !== overdueB) return overdueA ? -1 : 1
    return a.dueDate.localeCompare(b.dueDate)
  })
  const top3 = obligations.slice(0, 3)
  const totalToPay = obligations.reduce((s, r) => s + r.amount, 0)

  return (
    <Card variant="soft">
      <CardHeader>
        <CardTitle className="text-sm font-medium">Finanzas</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
            Saldo disponible
          </span>
          {summary.isLoading ? (
            <Skeleton className="h-7 w-28" />
          ) : (
            <span className="text-xl font-semibold tabular-nums">
              {formatMoney(summary.data?.totalBalance ?? 0, bootstrap)}
            </span>
          )}
        </div>

        <div className="flex flex-col gap-2 border-t pt-3">
          <div className="flex items-center justify-between text-[10px] uppercase tracking-wide text-muted-foreground">
            <span>Próximos 7 días</span>
            {!forecast.isLoading && (
              <span className="text-xs font-medium normal-case tabular-nums text-foreground">
                {formatMoney(totalToPay, bootstrap)}
              </span>
            )}
          </div>
          {forecast.isLoading ? (
            <div className="flex flex-col gap-1.5">
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-3/4" />
            </div>
          ) : top3.length === 0 ? (
            <p className="text-xs text-muted-foreground">Sin vencimientos próximos.</p>
          ) : (
            <div className="flex flex-col gap-1.5">
              {top3.map((row) => {
                const overdue = isForecastOverdue(row.dueDate)
                return (
                  <div
                    key={`${row.type}-${row.id}`}
                    className="flex items-center justify-between gap-2 text-xs"
                  >
                    <span className={cn("truncate", overdue && "font-medium text-destructive")}>
                      {FORECAST_TYPE_LABELS[row.type]} · {formatDate(row.dueDate)}
                    </span>
                    <span className={cn("shrink-0 tabular-nums", overdue && "font-medium text-destructive")}>
                      {formatMoney(row.amount, bootstrap)}
                    </span>
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </CardContent>
      <CardFooter className="border-t">
        <Link
          href="/finanzas/prevision"
          className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
        >
          Ver previsión <ChevronRight className="size-3.5" />
        </Link>
      </CardFooter>
    </Card>
  )
}

// ── Satisfacción (NPS) — sin caritas, solo dots + número ──────────────────

/**
 * Helper: el endpoint devuelve `[]` (PHP array vacío) cuando el módulo está
 * apagado para el tenant. React Query lo recibe como array. Detectamos eso
 * para mostrar el ModuleOffCard.
 */
function isModuleOff<T>(data: T | undefined): boolean {
  if (data === undefined || data === null) return true
  if (Array.isArray(data)) return true
  return false
}

function SatisfactionCard({
  data,
  isLoading,
}: {
  data: SatisfactionWidget | undefined
  isLoading: boolean
}) {
  if (!isLoading && isModuleOff(data)) return <ModuleOffCard title="Satisfacción (NPS)" />
  const det = data?.detractors.percent ?? 0
  const pas = data?.passives.percent ?? 0
  const pro = data?.promoters.percent ?? 0
  const detCount = data?.detractors.count ?? 0
  const pasCount = data?.passives.count ?? 0
  const proCount = data?.promoters.count ?? 0
  return (
    <div className="flex flex-col gap-2 px-1">
      <h3 className="text-sm font-medium">Satisfacción de clientes (NPS)</h3>
      {isLoading ? (
        <Skeleton className="h-3 w-full rounded-full" />
      ) : (
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="flex h-3 w-full cursor-default overflow-hidden rounded-full bg-muted">
              {det > 0 && (
                <div
                  className="bg-[var(--destructive)] transition-all"
                  style={{ width: `${det}%` }}
                />
              )}
              {pas > 0 && (
                <div
                  className="bg-[var(--muted-foreground)] transition-all"
                  style={{ width: `${pas}%` }}
                />
              )}
              {pro > 0 && (
                <div
                  className="bg-[var(--chart-1)] transition-all"
                  style={{ width: `${pro}%` }}
                />
              )}
            </div>
          </TooltipTrigger>
          <TooltipContent side="top" className="flex flex-col gap-1 px-3 py-2">
            <NpsTooltipRow color="var(--destructive)" label="Detractores" percent={det} count={detCount} />
            <NpsTooltipRow color="var(--muted-foreground)" label="Pasivos" percent={pas} count={pasCount} />
            <NpsTooltipRow color="var(--chart-1)" label="Promotores" percent={pro} count={proCount} />
          </TooltipContent>
        </Tooltip>
      )}
    </div>
  )
}

function NpsTooltipRow({
  color,
  label,
  percent,
  count,
}: {
  color: string
  label: string
  percent: number
  count: number
}) {
  return (
    <div className="flex items-center gap-2 tabular-nums">
      <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: color }} />
      <span className="flex-1">{label}</span>
      <span className="font-medium">{percent}%</span>
      <span className="text-background/70">· {count} resp.</span>
    </div>
  )
}

// ── Tipos de ventas + Cuentas por cobrar ──────────────────────────────────

const donutChartConfig = {
  contado: { label: "Al contado", color: "var(--chart-1)" },
  credito: { label: "A crédito", color: "var(--chart-3)" },
  cobrado: { label: "Cobrado", color: "var(--chart-1)" },
  porcobrar: { label: "Por cobrar", color: "var(--chart-3)" },
} satisfies ChartConfig

function PaymentSplitCard({
  title,
  data,
  isLoading,
  bootstrap,
  mode,
}: {
  title: string
  data: PaymentStatusWidget | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
  mode: "sale-type" | "receivables"
}) {
  const isSaleType = mode === "sale-type"
  const left = (isSaleType ? data?.contado : data?.cobrado) ?? 0
  const right = (isSaleType ? data?.credito : data?.porcobrar) ?? 0
  const leftCount = isSaleType ? data?.contadoCount : data?.cobradoCount
  const rightCount = isSaleType ? data?.creditoCount : data?.porcobrarCount
  const leftLabel = isSaleType ? "Al contado" : "Cobrado"
  const rightLabel = isSaleType ? "A crédito" : "Por cobrar"
  const total = left + right
  const totalCount = (leftCount ?? 0) + (rightCount ?? 0)

  // Donut data — recharts ignora segments con value=0. Para mostrar un donut
  // incluso cuando todo es 0 usamos un placeholder gris (var(--muted)).
  const pieData =
    total > 0
      ? [
          { name: leftLabel, value: left, color: "var(--chart-1)" },
          { name: rightLabel, value: right, color: "var(--chart-3)" },
        ]
      : [{ name: "Sin datos", value: 1, color: "var(--muted)" }]

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col items-center gap-4">
        <div className="relative h-[200px] w-[200px] shrink-0">
          {isLoading ? (
            <Skeleton className="size-full rounded-full" />
          ) : (
            <>
              <ChartContainer config={donutChartConfig} className="size-full aspect-square">
                <PieChart>
                  <Pie
                    data={pieData}
                    dataKey="value"
                    cx="50%"
                    cy="50%"
                    innerRadius={90}
                    outerRadius={100}
                    paddingAngle={total > 0 ? 2 : 0}
                    strokeWidth={0}
                  >
                    {pieData.map((entry, i) => (
                      <Cell key={i} fill={entry.color} />
                    ))}
                  </Pie>
                  {total > 0 && (
                    <ChartTooltip
                      content={
                        <ChartTooltipContent
                          hideLabel
                          formatter={(value, _name, item) => (
                            <div className="flex w-full items-center justify-between gap-3">
                              <span className="text-muted-foreground">
                                {(item?.payload as { name?: string } | undefined)?.name}
                              </span>
                              <span className="font-medium tabular-nums">
                                {formatMoney(Number(value) || 0, bootstrap)}
                              </span>
                            </div>
                          )}
                        />
                      }
                    />
                  )}
                </PieChart>
              </ChartContainer>
              <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-xl font-bold tabular-nums">{totalCount}</span>
                <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
                  ventas
                </span>
              </div>
            </>
          )}
        </div>
        <div className="grid w-full grid-cols-2 gap-3">
          <SplitRow
            dotColor="var(--chart-1)"
            label={leftLabel}
            amount={isLoading ? null : formatMoney(left, bootstrap)}
            count={isLoading ? null : formatInt(leftCount, bootstrap)}
          />
          <SplitRow
            dotColor="var(--chart-3)"
            label={rightLabel}
            amount={isLoading ? null : formatMoney(right, bootstrap)}
            count={isLoading ? null : formatInt(rightCount, bootstrap)}
          />
        </div>
      </CardContent>
    </Card>
  )
}

function SplitRow({
  dotColor,
  label,
  amount,
  count,
}: {
  dotColor: string
  label: string
  amount: string | null
  count: string | null
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <div className="flex items-center gap-2 text-[10px] uppercase tracking-wide text-muted-foreground">
        <span
          className="size-2 shrink-0 rounded-full"
          style={{ backgroundColor: dotColor }}
        />
        {label}
      </div>
      {amount === null ? (
        <Skeleton className="h-6 w-20" />
      ) : (
        <>
          <span className="text-xl font-semibold tabular-nums">{amount}</span>
          <span className="text-[10px] text-muted-foreground tabular-nums">
            {count} ventas
          </span>
        </>
      )}
    </div>
  )
}

// ── Clientes ──────────────────────────────────────────────────────────────

function CustomersCard({
  data,
  rates,
  isLoading,
}: {
  data: CustomersWidget | undefined
  rates: CustomersRatesWidget | undefined
  isLoading: boolean
}) {
  // El widget customersRates puede devolver retention/growth/churn como
  // strings o numbers según el backend. Normalizamos para mostrarlos como
  // porcentajes con barra de progreso fina.
  const retention = toPct(rates?.retention)
  const growth = toPct(rates?.growth)
  const churn = toPct(rates?.churn)

  return (
    <Card variant="soft">
      <CardHeader>
        <CardTitle className="text-sm font-medium">Clientes</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid grid-cols-3 gap-3">
          <Stat
            label="Total"
            value={isLoading ? null : formatInt(data?.total, undefined)}
          />
          <Stat
            label="Nuevos"
            value={isLoading ? null : formatInt(data?.new, undefined)}
          />
          <Stat
            label="Recurrentes"
            value={isLoading ? null : formatInt(data?.old, undefined)}
          />
        </div>
        <div className="flex flex-col gap-2">
          <RateRow
            label="Tasa de retorno"
            percent={data?.returnRate ?? null}
            isLoading={isLoading}
            barColor="var(--chart-1)"
          />
          {retention !== null && (
            <RateRow
              label="Retención"
              percent={retention}
              isLoading={isLoading}
              barColor="var(--chart-1)"
            />
          )}
          {growth !== null && (
            <RateRow
              label="Crecimiento"
              percent={growth}
              isLoading={isLoading}
              barColor="var(--chart-1)"
            />
          )}
          {churn !== null && (
            <RateRow
              label="Pérdida (churn)"
              percent={churn}
              isLoading={isLoading}
              barColor="var(--destructive)"
            />
          )}
        </div>
      </CardContent>
    </Card>
  )
}

function toPct(v: unknown): number | null {
  if (v === undefined || v === null) return null
  const n = typeof v === "string" ? parseFloat(v) : typeof v === "number" ? v : NaN
  if (!Number.isFinite(n)) return null
  return n
}

function RateRow({
  label,
  percent,
  isLoading,
  barColor,
}: {
  label: string
  percent: number | null
  isLoading: boolean
  barColor: string
}) {
  const clamped = Math.max(0, Math.min(100, percent ?? 0))
  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center justify-between text-xs">
        <span className="text-muted-foreground">{label}</span>
        <span className="font-medium tabular-nums">
          {isLoading ? "…" : `${percent ?? 0}%`}
        </span>
      </div>
      <div className="h-1 w-full overflow-hidden rounded-full bg-muted">
        <div
          className="h-full rounded-full transition-all"
          style={{ width: `${clamped}%`, backgroundColor: barColor }}
        />
      </div>
    </div>
  )
}

// ── Info general (Ticket promedio + cajas) ────────────────────────────────

/**
 * Tabla compacta para la sidebar (espejo del panel "Información general"
 * del legacy): 4 rows con label + valor, sin charts ni KPIs grandes.
 * Cada row tiene su propio link al reporte correspondiente.
 */
function InfoGeneralCard({
  stats,
  info,
  customers,
  loading,
  bootstrap,
}: {
  stats: IncomeOutcomeStatsWidget | undefined
  info: InfoWidget | undefined
  customers: CustomersWidget | undefined
  loading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const rows: { label: string; value: React.ReactNode; href?: string }[] = [
    {
      label: "Ticket promedio",
      value: loading ? null : fmtMoney(stats?.customerAverage, bootstrap, false),
    },
    {
      label: "Clientes en total",
      value: loading ? null : formatInt(customers?.total, bootstrap),
      href: "/contacts",
    },
    {
      label: "Cajas abiertas",
      value: loading ? null : formatInt(info?.openDrawersCount, bootstrap),
      href: "/reports",
    },
    {
      label: "Gift cards vigentes",
      value: loading ? null : formatInt(info?.giftCardsCount, bootstrap),
      href: "/reports",
    },
  ]
  return (
    <Card variant="soft">
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium">Información general</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col divide-y divide-border">
        {rows.map((r) => (
          <div
            key={r.label}
            className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0"
          >
            {r.href ? (
              <Link href={r.href} className="text-muted-foreground hover:text-foreground">
                {r.label}
              </Link>
            ) : (
              <span className="text-muted-foreground">{r.label}</span>
            )}
            {r.value === null ? (
              <Skeleton className="h-4 w-12" />
            ) : (
              <span className="font-semibold tabular-nums">{r.value}</span>
            )}
          </div>
        ))}
      </CardContent>
    </Card>
  )
}

/**
 * Card del Plan en la sidebar (legacy: panelAccountInfo). Tabla con
 * Productos / Usuarios / Transacciones / Sucursales — los topes del plan
 * van como sufijo "X / max" cuando aplica.
 */
function PlanSidebarCard({
  info,
  loading,
  bootstrap,
}: {
  info: InfoWidget | undefined
  loading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const rows: { label: string; value: React.ReactNode }[] = [
    {
      label: "Productos y servicios",
      value: loading
        ? null
        : planValue(info?.itemsCount, info?.itemsMax, bootstrap),
    },
    {
      label: "Usuarios",
      value: loading
        ? null
        : planValue(info?.usersCount, info?.usersMax, bootstrap),
    },
    {
      label: "Transacciones (mes)",
      value: loading ? null : formatInt(info?.transactionsCount, bootstrap),
    },
    {
      label: "Sucursales",
      value: loading ? null : formatInt(info?.outletsCount, bootstrap),
    },
  ]
  return (
    <Card variant="soft">
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center justify-between text-sm font-medium">
          <span>Plan {info?.plan || ""}</span>
          <Link
            href="/settings"
            className="flex items-center gap-1 text-xs font-normal text-muted-foreground hover:text-foreground"
          >
            Cambiar <ChevronRight className="size-3.5" />
          </Link>
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col divide-y divide-border">
        {rows.map((r) => (
          <div
            key={r.label}
            className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0"
          >
            <span className="text-muted-foreground">{r.label}</span>
            {r.value === null ? (
              <Skeleton className="h-4 w-14" />
            ) : (
              <span className="font-semibold tabular-nums">{r.value}</span>
            )}
          </div>
        ))}
      </CardContent>
    </Card>
  )
}

function planValue(
  current: number | undefined,
  max: number | undefined,
  bootstrap: ReturnType<typeof useBootstrap>["data"],
): string {
  const cur = formatInt(current, bootstrap)
  if (!max || max <= 0) return cur
  return `${cur} / ${formatInt(max, bootstrap)}`
}

// ── Horarios Pico ────────────────────────────────────────────────────────

const topHoursChartConfig = {
  total: { label: "Ventas", color: "var(--chart-1)" },
} satisfies ChartConfig

function TopHoursCard({
  data,
  isLoading,
}: {
  data: TopHoursWidget | undefined
  isLoading: boolean
}) {
  const points = React.useMemo(() => {
    if (!data?.hour?.length) return []
    return data.hour.map((h, i) => ({
      hour: h.split(" ")[0],
      total: data.total?.[i] ?? 0,
    }))
  }, [data])

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">Horarios pico</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-[200px] w-full" />
        ) : points.length === 0 ? (
          <div className="flex h-[200px] items-center justify-center">
            <EmptyState
              icon={TrendingUp}
              title="Sin ventas en este período"
              showMarquee={false}
              className="border-0 p-0"
            />
          </div>
        ) : (
          <ChartContainer config={topHoursChartConfig} className="h-[200px] w-full">
            <BarChart data={points} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
              <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
              <XAxis
                dataKey="hour"
                tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                allowDecimals={false}
                tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
                width={28}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={<ChartTooltipContent />}
              />
              <Bar dataKey="total" fill="var(--color-total)" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ChartContainer>
        )}
      </CardContent>
    </Card>
  )
}

// ── Top 5 artículos ───────────────────────────────────────────────────────

function TopItemsCard({
  data,
  isLoading,
  bootstrap,
}: {
  data: TopItemRow[]
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">Top 5 Artículos</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">
            {[1, 2, 3, 4, 5].map((i) => (
              <Skeleton key={i} className="h-8 w-full" />
            ))}
          </div>
        ) : data.length === 0 ? (
          <EmptyState
            icon={TrendingUp}
            title="Sin ventas en este período"
            showMarquee={false}
            className="border-0 py-6"
          />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="text-xs">Artículo</TableHead>
                <TableHead className="w-20 text-right text-xs">Cantidad</TableHead>
                <TableHead className="w-28 text-right text-xs">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((row, i) => (
                <TableRow key={`${row.name}-${i}`}>
                  <TableCell className="font-medium">{row.name || "(sin nombre)"}</TableCell>
                  <TableCell className="text-right tabular-nums">{row.count}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatMoney(row.total, bootstrap)}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}

// ── Top 10 Categorías — BarChart horizontal ──────────────────────────────

const topCategoriesChartConfig = {
  total: { label: "Ventas", color: "var(--chart-1)" },
} satisfies ChartConfig

function TopCategoriesCard({
  data,
  isLoading,
}: {
  data: TopTaxonomyRow[]
  isLoading: boolean
}) {
  // BarChart horizontal: eje Y = title, eje X = total. La altura se calcula
  // según cantidad de filas para que no se aplasten (28px por bar mínimo).
  const chartHeight = Math.max(200, data.length * 28)

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">Top 10 Categorías</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">
            {[1, 2, 3, 4, 5].map((i) => (
              <Skeleton key={i} className="h-5 w-full" />
            ))}
          </div>
        ) : data.length === 0 ? (
          <EmptyState
            icon={TrendingUp}
            title="Sin ventas en este período"
            showMarquee={false}
            className="border-0 py-6"
          />
        ) : (
          <ChartContainer
            config={topCategoriesChartConfig}
            className="w-full"
            style={{ height: `${chartHeight}px` }}
          >
            <BarChart
              data={data}
              layout="vertical"
              margin={{ top: 4, right: 12, left: 0, bottom: 0 }}
            >
              <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" horizontal={false} />
              <XAxis
                type="number"
                tick={{ fontSize: 10, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
                tickFormatter={(v: number) => compactNumber(v)}
              />
              <YAxis
                type="category"
                dataKey="title"
                tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
                width={100}
                interval={0}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={<ChartTooltipContent />}
              />
              <Bar dataKey="total" fill="var(--color-total)" radius={[0, 4, 4, 0]} />
            </BarChart>
          </ChartContainer>
        )}
      </CardContent>
    </Card>
  )
}

// ── Pequeños helpers ─────────────────────────────────────────────────────

function Stat({
  label,
  value,
}: {
  label: string
  value: string | null
}) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
        {label}
      </span>
      {value === null ? (
        <Skeleton className="h-6 w-16" />
      ) : (
        <span className="text-lg font-semibold tabular-nums">{value}</span>
      )}
    </div>
  )
}

function ModuleOffCard({ title }: { title: string }) {
  return (
    <Card className="opacity-60">
      <CardHeader>
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <p className="text-xs text-muted-foreground">
          Módulo no activado para tu plan.
        </p>
      </CardContent>
    </Card>
  )
}

function fmtMoney(
  v: number | undefined,
  bootstrap: ReturnType<typeof useBootstrap>["data"],
  loading: boolean,
): React.ReactNode {
  if (loading) return <Skeleton className="h-8 w-24 inline-block align-middle" />
  return formatMoney(v ?? 0, bootstrap)
}
