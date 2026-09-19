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

import { Button } from "@/components/ui/button"
import { Card, CardAction, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { BarList } from "@/components/charts/bar-list"
import { DeltaLine, type StatDelta } from "@/components/stat-tile"
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
  useDashboardNow,
  useDashboardWidget,
  useIncomeChart,
  type CustomersRatesWidget,
  type CustomersWidget,
  type IncomeChartData,
  type IncomeOutcomeStatsWidget,
  type InfoWidget,
  type PaymentStatusWidget,
  type SalesByOutletWidget,
  type SatisfactionWidget,
  type TopHoursWidget,
  type TopItemRow,
  type TopTaxonomyRow,
} from "@/hooks/use-dashboard-widget"
import {
  kpiDeltas,
  outletDelta,
  packGrid,
  showCustomers,
  showSalesByOutlet,
  showSplitDonut,
  showTopCategories,
  showTopHours,
  showTopItems,
  visibleAttentionRows,
  visibleDuePart,
  visibleInfoRows,
  visibleNowTiles,
  type AttentionKey,
  type AttentionRow,
  type AttentionWidget,
  type InfoRowKey,
  type KpiKey,
  type NowAgendaTile,
  type NowDrawersTile,
  type NowDuePart,
  type NowDuesTile,
  type NowOrdersTile,
  type NowSpacesTile,
  type NowStaffTile,
  type NowTile,
} from "@/lib/dashboard/visibility"
import { formatInt, formatMoney } from "@/lib/format"
import { formatDate, formatDateTime, formatTime } from "@/lib/format-date"
import { formatQty } from "@/lib/format-qty"
import { resolveNumberLocale } from "@/lib/tenant-locale"
import {
  averageLabel,
  bucketTooltipLabel,
  formatBucketTick,
  tooltipPoint,
} from "@/lib/charts/granularity"
import { partialBarCells } from "@/components/domain/reports/partial-bar-cells"
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

  /**
   * Desde el 2026-09-02 `/v1/reports/dashboard` gatea POR WIDGET: los que
   * devuelven plata del comercio —facturación, ticket promedio, cobranza,
   * rankings, horas pico, analítica de clientes— exigen `reports.sales.view`.
   * Los que no —`info`, el armazón de contadores del plan y cajas abiertas—
   * siguen abiertos, y por eso `info` se pide igual acá: es lo que decide el
   * hero de "negocio sin actividad" y lo tiene que ver cualquiera.
   *
   * `enabled` y no "pedir y descartar": un fetch que sabemos que va a dar 403
   * no se manda. Mismo criterio que `FinanceCard` más abajo, que ya no monta
   * ni dispara nada sin `finance.manage`.
   */
  const canViewSales = usePermission("reports.sales.view")
  const ventas = React.useMemo(() => ({ ...opts, enabled: canViewSales }), [opts, canViewSales])

  const stats = useDashboardWidget<IncomeOutcomeStatsWidget>("incomeOutcomeStats", ventas)
  const salesByOutlet = useDashboardWidget<SalesByOutletWidget>("salesByOutlet", ventas)
  const info = useDashboardWidget<InfoWidget>("info", opts)
  const incomeChart = useIncomeChart(opts, { enabled: canViewSales })
  const paymentStatus = useDashboardWidget<PaymentStatusWidget>("paymentStatus", ventas)
  const customers = useDashboardWidget<CustomersWidget>("customers", ventas)
  const customersRates = useDashboardWidget<CustomersRatesWidget>("customersRates", ventas)
  const topItems = useDashboardWidget<TopItemRow[]>("topItems", ventas)
  const topCategories = useDashboardWidget<TopTaxonomyRow[]>("topCategories", ventas)
  const topHours = useDashboardWidget<TopHoursWidget>("topHours", ventas)
  // NPS oculto (ver comentario en <aside>) — fetch de "satisfaction" removido:
  // quedaría huérfano sin SatisfactionCard montado.
  // "Requiere atención": sin `enabled` a propósito — no es de ventas; el
  // backend gatea FILA por fila con el permiso de la pantalla a la que linkea
  // cada una, así que un usuario sin reportes igual ve lo que sí le compete.
  const attention = useDashboardWidget<AttentionWidget>("attention", opts)
  // "Ahora": el estado del momento, INDEPENDIENTE del rango elegido. Sin
  // `enabled` por la misma razón que "Requiere atención": el backend gatea
  // fila por fila (módulo y/o permiso de la pantalla a la que lleva cada una).
  const now = useDashboardNow()
  const nowTiles = visibleNowTiles(now.data)
  const deltas = kpiDeltas(stats.data)

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
      // `min-h` + `flex items-center` y no más padding: el hero se centra
      // VERTICALMENTE en el alto disponible del panel. Con `py-12` fijo
      // quedaba pegado arriba y con medio viewport vacío abajo — se ve en
      // cualquier pantalla de escritorio, que es donde el dueño abre el panel
      // por primera vez. El `4rem` descontado es el alto del header del panel.
      //
      // El copy sigue el posicionamiento vigente de la marca ("el socio
      // inteligente de tu negocio", `content/sitio/_brief.md`) y no la
      // descripción de features que había antes: es la primera pantalla que ve
      // un comercio recién dado de alta, así que dice QUÉ ES Punto, no qué
      // módulos trae. La IA se nombra por lo que HACE (cargar lo tedioso,
      // responder preguntas del negocio), no como etiqueta.
      <Hero115
        className="flex min-h-[calc(100dvh-4rem)] items-center py-12"
        icon={<PuntoLogo variant="mark" className="size-10" />}
        heading="Bienvenido a Punto"
        description="Tu socio inteligente: vende en la caja, factura sin que lo pienses, carga lo tedioso con IA y te dice cómo va tu negocio cuando se lo preguntás."
        buttons={{ primary: { text: "Ir a la caja", url: "/pos" } }}
        byline="Hacé tu primera venta y Punto empieza a trabajar para vos."
      />
    )
  }

  /**
   * Esta pantalla ES el reporte de ventas del comercio con otra tipografía:
   * facturación del período, margen, cobranza pendiente, ranking de artículos.
   * Sin `reports.sales.view` el backend ya no la contesta, así que mostrarla
   * sería ofrecer ocho cards de ceros y errores.
   *
   * El corte espera a que el bootstrap resuelva: `usePermission` devuelve false
   * mientras carga (safe default), y sin esta guarda la pantalla parpadearía el
   * vacío en cada entrada, hasta para el dueño.
   */
  const permisosResueltos = bootstrap?.user?.permissions !== undefined
  if (permisosResueltos && !canViewSales) {
    // Sin ventas, "Ahora" igual aplica: órdenes, espacios o la agenda son de
    // quien atiende, no del reporte de ventas. El vacío de página solo va si
    // tampoco hay nada del momento que mostrarle.
    return (
      <div className="flex flex-col gap-6">
        <header className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Resumen general de su negocio</h1>
        </header>
        <NowSection tiles={nowTiles} bootstrap={bootstrap} />
        {nowTiles.length === 0 && (
          <EmptyState
            icon={TrendingUp}
            title="No tenés acceso al resumen de ventas"
            description="Este panel muestra la facturación y los indicadores del negocio. Tu usuario no tiene ese permiso; pedíselo a un administrador si lo necesitás."
            actions={
              <Button asChild>
                <Link href="/pos">Ir a la caja</Link>
              </Button>
            }
          />
        )}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <h1 className="text-2xl font-semibold">Resumen general de su negocio</h1>
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
              href="/reports/sales?tab=dashboard"
              value={fmtMoney(stats.data?.total, bootstrap, stats.isLoading)}
              isLoading={stats.isLoading}
              sparkline={incomeChart.data?.data.map((p) => p.ingresos)}
              sparklineColor="var(--chart-1)"
              trend="up"
              delta={deltas.total}
            />
            <BigMetricCard
              label="Egresos"
              href="/purchase"
              value={fmtMoney(stats.data?.expenses, bootstrap, stats.isLoading)}
              isLoading={stats.isLoading}
              sparkline={incomeChart.data?.data.map((p) => p.egresos)}
              sparklineColor="var(--muted-foreground)"
              trend="down"
              delta={deltas.expenses}
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
                <span className="text-xs font-medium text-muted-foreground">
                  Ganancia
                </span>
                {stats.isLoading ? (
                  <Skeleton className="h-8 w-32" />
                ) : (
                  <span className="text-2xl font-bold tabular-nums text-[var(--chart-1)]">
                    {formatMoney(stats.data?.revenue, bootstrap)}
                  </span>
                )}
                <KpiDelta delta={deltas.revenue} loading={stats.isLoading} />
              </div>
              <div className="grid grid-cols-2 divide-x divide-border border-t py-4">
                <div className="flex flex-col items-center gap-1">
                  <span className="text-xs text-muted-foreground">
                    Margen
                  </span>
                  {stats.isLoading ? (
                    <Skeleton className="h-6 w-12" />
                  ) : (
                    <span className="text-xl font-bold tabular-nums">
                      {stats.data?.margin ?? 0}%
                    </span>
                  )}
                  <KpiDelta delta={deltas.margin} loading={stats.isLoading} />
                </div>
                <div className="flex flex-col items-center gap-1">
                  <span className="text-xs text-muted-foreground">
                    Cant. Ventas
                  </span>
                  {stats.isLoading ? (
                    <Skeleton className="h-6 w-12" />
                  ) : (
                    <span className="text-xl font-bold tabular-nums">
                      {formatInt(stats.data?.count, bootstrap)}
                    </span>
                  )}
                  <KpiDelta delta={deltas.count} loading={stats.isLoading} />
                </div>
              </div>
              {/* Ticket promedio junto a los otros KPIs del período (owner).
                  Sin ventas no promedia nada: no se muestra. */}
              {(stats.isLoading || Number(stats.data?.count ?? 0) > 0) && (
                <div className="flex flex-col items-center gap-1 border-t py-4">
                  <span className="text-xs text-muted-foreground">Ticket promedio</span>
                  {stats.isLoading ? (
                    <Skeleton className="h-6 w-24" />
                  ) : (
                    <span className="text-xl font-bold tabular-nums">
                      {formatMoney(stats.data?.customerAverage ?? 0, bootstrap)}
                    </span>
                  )}
                  <KpiDelta delta={deltas.customerAverage} loading={stats.isLoading} />
                </div>
              )}
            </div>
          </section>

          {/* Bloques del período en una grilla de 2 columnas. Cada uno se
              pinta SOLO si tiene algo que decir (lib/dashboard/visibility.ts:
              un donut de una porción, un ranking vacío o una sola categoría
              no informan), y `packGrid` los acomoda para que ocultar uno no
              deje un hueco. */}
          <PeriodBlocksGrid
            blocks={{
              salesByOutlet: showSalesByOutlet(salesByOutlet.data?.rows) && (
                <SalesByOutletCard rows={salesByOutlet.data!.rows} bootstrap={bootstrap} />
              ),
              saleType: showSplitDonut(paymentStatus.data, "sale-type") && (
                <PaymentSplitCard
                  title="Tipos de venta"
                  data={paymentStatus.data}
                  bootstrap={bootstrap}
                  mode="sale-type"
                />
              ),
              receivables: showSplitDonut(paymentStatus.data, "receivables") && (
                <PaymentSplitCard
                  title="Cuentas por cobrar"
                  data={paymentStatus.data}
                  bootstrap={bootstrap}
                  mode="receivables"
                />
              ),
              topItems: showTopItems(topItems.data) && (
                <TopItemsCard data={topItems.data ?? []} bootstrap={bootstrap} />
              ),
              topHours: showTopHours(topHours.data) && <TopHoursCard data={topHours.data!} />,
              topCategories: showTopCategories(topCategories.data) && (
                <TopCategoriesCard data={topCategories.data ?? []} bootstrap={bootstrap} />
              ),
            }}
          />
        </div>

        {/* ── SIDEBAR ────────────────────────────────────────────────────── */}
        <aside className="flex min-w-0 flex-col gap-4">
          {/* "Ahora" encabeza la columna derecha (owner) y NO sigue al rango
              del selector: es lo que está pasando en este momento. Sin filas
              con dato, no existe. */}
          <NowSection tiles={nowTiles} bootstrap={bootstrap} />
          <AttentionCard data={attention.data} bootstrap={bootstrap} />
          <FinanceCard />
          {/* NPS oculto a pedido del owner — el módulo de satisfacción de
              clientes todavía no está desarrollado. Componente y helpers
              (SatisfactionCard, NpsTooltipRow) quedan dormidos: la feature
              vuelve más adelante. */}
          {showCustomers(customers.data) && (
            <CustomersCard data={customers.data} rates={customersRates.data} isLoading={false} />
          )}
          {!stats.isLoading && !info.isLoading && (
            <InfoGeneralCard stats={stats.data} info={info.data} bootstrap={bootstrap} deltas={deltas} />
          )}
        </aside>
      </div>
    </div>
  )
}

// ── KPI cards ──────────────────────────────────────────────────────────────

/**
 * BigMetricCard — KPI card grande con label, valor enorme y
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
  delta,
}: {
  label: string
  href?: string
  value: React.ReactNode
  isLoading: boolean
  sparkline?: number[]
  sparklineColor?: string
  trend?: "up" | "down"
  /** Comparativa contra el período anterior; `undefined` = sin base, no se pinta. */
  delta?: StatDelta
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
          <div className="flex min-w-0 items-center gap-1.5 text-xs font-medium text-muted-foreground">
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
            {delta && <DeltaLine {...delta} compact />}
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

/**
 * Delta compacto debajo de un KPI del período. Sin base (`undefined`) no se
 * pinta nada: el dashboard no dice "sin base para comparar" (regla del owner,
 * nada de ceros ni avisos muertos).
 */
function KpiDelta({ delta, loading }: { delta?: StatDelta; loading: boolean }) {
  if (loading || !delta) return null
  return <DeltaLine {...delta} compact />
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
        <h2 className="text-xl font-semibold">Margen, ingresos y egresos</h2>
        <Skeleton className="h-[240px] w-full" />
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="flex flex-col gap-2">
        <h2 className="text-xl font-semibold">Margen, ingresos y egresos</h2>
        <div className="flex h-[240px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
          {error?.message || "No se pudieron cargar los datos del chart."}
        </div>
      </div>
    )
  }

  const hasData = data.data.some((p) => p.ingresos > 0 || p.egresos > 0)
  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-baseline justify-between gap-3">
        <h2 className="text-xl font-semibold">Margen, ingresos y egresos</h2>
        <span className="text-xs text-muted-foreground">
          {averageLabel(data.granularity)}: {formatMoney(data.totals.average, bootstrap)}
        </span>
      </div>
      <div>
        {!hasData ? (
          // Bloque central: se queda aunque el período no tenga movimientos
          // (el rango lo eligió el usuario y "no hubo nada" es la respuesta),
          // pero como línea compacta de sub-sección, no EmptyState (context/84 T7).
          <p className="flex h-[240px] items-center justify-center text-sm text-muted-foreground">
            Sin movimientos en este período.
          </p>
        ) : (
          <ChartContainer config={incomeChartConfig} className="h-[240px] w-full">
            <ComposedChart data={data.data} margin={{ top: 10, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey="bucket"
                tickFormatter={(v: string) => formatBucketTick(String(v), data.granularity)}
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
                    labelFormatter={(label, payload) =>
                      bucketTooltipLabel(tooltipPoint(payload), data.granularity) ||
                      formatBucketTick(String(label), data.granularity)
                    }
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
              >
                {partialBarCells(data.data)}
              </Bar>
              <Bar
                dataKey="egresos"
                fill="var(--color-egresos)"
                radius={[4, 4, 0, 0]}
                maxBarSize={32}
              >
                {partialBarCells(data.data)}
              </Bar>
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
        <CardTitle>Finanzas</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <span className="text-xs text-muted-foreground">
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
          <div className="flex items-center justify-between text-xs text-muted-foreground">
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
 * para no montar el bloque.
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
  // Módulo apagado = el bloque no existe (regla del dashboard: nunca un
  // bloque que diga "no aplica").
  if (!isLoading && isModuleOff(data)) return null
  const det = data?.detractors.percent ?? 0
  const pas = data?.passives.percent ?? 0
  const pro = data?.promoters.percent ?? 0
  const detCount = data?.detractors.count ?? 0
  const pasCount = data?.passives.count ?? 0
  const proCount = data?.promoters.count ?? 0
  return (
    <Card>
      <CardHeader>
        <CardTitle>Satisfacción de clientes (NPS)</CardTitle>
      </CardHeader>
      <CardContent>
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
      </CardContent>
    </Card>
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

/**
 * Donut de dos porciones. Solo se monta con las DOS porciones en > 0
 * (`showSplitDonut`): con una sola no informa y el bloque no existe.
 */
function PaymentSplitCard({
  title,
  data,
  bootstrap,
  mode,
}: {
  title: string
  data: PaymentStatusWidget | undefined
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
  const totalCount = (leftCount ?? 0) + (rightCount ?? 0)

  const pieData = [
    { name: leftLabel, value: left, color: "var(--chart-1)" },
    { name: rightLabel, value: right, color: "var(--chart-3)" },
  ]

  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col items-center gap-4">
        <div className="relative h-[200px] w-[200px] shrink-0">
          <ChartContainer config={donutChartConfig} className="size-full aspect-square">
            <PieChart>
              <Pie
                data={pieData}
                dataKey="value"
                cx="50%"
                cy="50%"
                innerRadius={90}
                outerRadius={100}
                paddingAngle={2}
                strokeWidth={0}
              >
                {pieData.map((entry, i) => (
                  <Cell key={i} fill={entry.color} />
                ))}
              </Pie>
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
            </PieChart>
          </ChartContainer>
          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
            <span className="text-xl font-bold tabular-nums">{totalCount}</span>
            <span className="text-xs text-muted-foreground">ventas</span>
          </div>
        </div>
        <div className="grid w-full grid-cols-2 gap-3">
          <SplitRow
            dotColor="var(--chart-1)"
            label={leftLabel}
            amount={formatMoney(left, bootstrap)}
            count={formatInt(leftCount, bootstrap)}
          />
          <SplitRow
            dotColor="var(--chart-3)"
            label={rightLabel}
            amount={formatMoney(right, bootstrap)}
            count={formatInt(rightCount, bootstrap)}
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
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
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

  // Misma piel que las demás cards de la columna (Finanzas, Información
  // general, Plan): `soft` + filas label/valor. Los StatTile de tres columnas
  // la hacían la única card distinta de la columna — y en el ancho de la
  // sidebar truncaban "Recurrentes".
  const counts: { label: string; value: number | undefined }[] = [
    { label: "Total", value: data?.total },
    { label: "Nuevos", value: data?.new },
    { label: "Recurrentes", value: data?.old },
  ]
  return (
    <Card variant="soft">
      <CardHeader className="pb-2">
        <CardTitle>Clientes</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-col divide-y divide-border">
          {counts.map((c) => (
            <div
              key={c.label}
              className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0"
            >
              <span className="text-muted-foreground">{c.label}</span>
              {isLoading ? (
                <Skeleton className="h-4 w-12" />
              ) : (
                <span className="font-semibold tabular-nums">
                  {formatInt(c.value, undefined)}
                </span>
              )}
            </div>
          ))}
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

// ── Info general (Ticket promedio + cajas + gift cards) ───────────────────

const INFO_ROW: Record<
  InfoRowKey,
  {
    label: string
    href?: string
    value: (
      stats: IncomeOutcomeStatsWidget | undefined,
      info: InfoWidget | undefined,
      bootstrap: ReturnType<typeof useBootstrap>["data"],
    ) => React.ReactNode
  }
> = {
  giftCards: {
    label: "Gift cards vigentes",
    href: "/reports/giftcards",
    value: (_stats, info, bootstrap) => formatInt(info?.giftCardsCount, bootstrap),
  },
}

/**
 * Filas label/valor de la sidebar. Cada fila existe solo si aplica al
 * comercio (`visibleInfoRows`); sin filas, la card no se monta.
 */
function InfoGeneralCard({
  stats,
  info,
  bootstrap,
  deltas,
}: {
  stats: IncomeOutcomeStatsWidget | undefined
  info: InfoWidget | undefined
  bootstrap: ReturnType<typeof useBootstrap>["data"]
  deltas: Partial<Record<KpiKey, StatDelta>>
}) {
  const keys = visibleInfoRows(stats, info)
  if (keys.length === 0) return null
  return (
    <Card variant="soft">
      <CardHeader className="pb-2">
        <CardTitle>Información general</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col divide-y divide-border">
        {keys.map((k) => {
          const r = INFO_ROW[k]
          return (
            <div
              key={k}
              className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0"
            >
              {r.href ? (
                <Link href={r.href} className="text-muted-foreground hover:text-foreground">
                  {r.label}
                </Link>
              ) : (
                <span className="text-muted-foreground">{r.label}</span>
              )}
              <span className="font-semibold tabular-nums">{r.value(stats, info, bootstrap)}</span>
            </div>
          )
        })}
      </CardContent>
    </Card>
  )
}

// ── Requiere atención ─────────────────────────────────────────────────────

const ATTENTION_LABEL: Record<AttentionKey, string> = {
  einvoice: "Facturas electrónicas con problemas",
  stock: "Artículos agotados o bajo el mínimo",
  margin: "Artículos bajo el margen objetivo",
  receivables: "Deuda de clientes vencida",
  attendance: "Marcaciones para revisar",
}

/**
 * Pendientes del comercio, cada uno con un link a donde se resuelve. El
 * backend (`AttentionService`) manda solo las filas con algo y solo las que el
 * usuario puede abrir; sin filas la card NO se monta — silencio, no "todo en
 * orden". Tampoco hay skeleton: una card que aparece para desaparecer es
 * justo el ruido que la regla evita.
 */
function AttentionCard({
  data,
  bootstrap,
}: {
  data: AttentionWidget | undefined
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const rows = visibleAttentionRows(data)
  if (rows.length === 0) return null
  return (
    <Card variant="soft">
      <CardHeader className="pb-2">
        <CardTitle>Requiere atención</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col divide-y divide-border">
        {rows.map((r) => (
          <AttentionRowLink key={r.key} row={r} bootstrap={bootstrap} />
        ))}
      </CardContent>
    </Card>
  )
}

function AttentionRowLink({
  row,
  bootstrap,
}: {
  row: AttentionRow
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const isDebt = row.key === "receivables"
  return (
    <Link
      href={row.href}
      className="group flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0"
    >
      <span className="flex min-w-0 flex-col">
        <span className="text-muted-foreground group-hover:text-foreground">
          {ATTENTION_LABEL[row.key]}
        </span>
        {isDebt && (
          <span className="text-xs text-muted-foreground">
            {formatInt(row.count, bootstrap)} {row.count === 1 ? "cliente" : "clientes"}
          </span>
        )}
      </span>
      <span className="flex shrink-0 items-center gap-1 font-semibold tabular-nums">
        {isDebt ? formatMoney(row.amount ?? 0, bootstrap) : formatInt(row.count, bootstrap)}
        <ChevronRight className="size-3.5 text-muted-foreground" />
      </span>
    </Link>
  )
}

// ── Grilla de bloques del período ─────────────────────────────────────────

type PeriodBlockKey =
  | "salesByOutlet"
  | "saleType"
  | "receivables"
  | "topItems"
  | "topCategories"
  | "topHours"

/**
 * Orden de pantalla y ancho natural de cada bloque. Los rankings son listas
 * con barras (`BarList`) y entran en media fila; `packGrid` estira el que
 * quede solo.
 */
const PERIOD_BLOCKS: { key: PeriodBlockKey; full: boolean }[] = [
  { key: "salesByOutlet", full: false },
  { key: "saleType", full: false },
  { key: "receivables", full: false },
  { key: "topItems", full: false },
  { key: "topCategories", full: false },
  { key: "topHours", full: false },
]

/**
 * Grilla de 2 columnas con los bloques que tienen algo que mostrar. Un bloque
 * en `false` no existe; `packGrid` reacomoda el resto para que no quede un
 * hueco (una media fila sola sube a la próxima media fila o se estira).
 */
function PeriodBlocksGrid({ blocks }: { blocks: Record<PeriodBlockKey, React.ReactNode | false> }) {
  const visible = PERIOD_BLOCKS.filter((b) => blocks[b.key])
  if (visible.length === 0) return null
  return (
    <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
      {packGrid(visible).map((b) => (
        <div key={b.key} className={cn("min-w-0 [&>*]:h-full", b.full && "md:col-span-2")}>
          {blocks[b.key]}
        </div>
      ))}
    </section>
  )
}

// ── Horarios Pico ────────────────────────────────────────────────────────

const topHoursChartConfig = {
  total: { label: "Ventas", color: "var(--chart-1)" },
} satisfies ChartConfig

/** Solo se monta con ventas en el período (`showTopHours`). */
function TopHoursCard({ data }: { data: TopHoursWidget }) {
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
        <CardTitle>Horarios pico</CardTitle>
      </CardHeader>
      <CardContent>
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
      </CardContent>
    </Card>
  )
}

// ── Rankings (lista con barras) ──────────────────────────────────────────

/**
 * Solo se monta con ventas en el período (`showTopItems`). El backend rankea
 * por UNIDADES, así que la barra mide unidades (medir el monto dejaba barras
 * que no seguían el orden de la lista); el monto va como dato.
 */
function TopItemsCard({
  data,
  bootstrap,
}: {
  data: TopItemRow[]
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Top 5 artículos</CardTitle>
      </CardHeader>
      <CardContent>
        <BarList
          items={data.map((row, i) => ({
            key: `${row.name}-${i}`,
            label: row.name || "(sin nombre)",
            value: Number(row.count) || 0,
            display: formatMoney(row.total, bootstrap),
            meta: `${formatQty(row.count, bootstrap)} vendidos`,
          }))}
        />
      </CardContent>
    </Card>
  )
}

/**
 * Solo se monta con 2+ categorías (`showTopCategories`). El backend rankea por
 * UNIDADES vendidas (`topTaxonomy`), así que eso es lo que se lee y lo que mide
 * la barra.
 */
function TopCategoriesCard({
  data,
  bootstrap,
}: {
  data: TopTaxonomyRow[]
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Top 5 categorías</CardTitle>
      </CardHeader>
      <CardContent>
        <BarList
          // 5, parejo con Top artículos (misma fila del grid).
          items={data.slice(0, 5).map((row, i) => ({
            key: `${row.title}-${i}`,
            label: row.title,
            value: Number(row.total) || 0,
            display: `${formatQty(row.total, bootstrap)} vendidos`,
          }))}
        />
      </CardContent>
    </Card>
  )
}

/**
 * Ventas del período por sucursal: total, % del total y variación contra su
 * propio período anterior. Solo con 2+ sucursales vendiendo en el alcance del
 * usuario (`showSalesByOutlet`).
 */
function SalesByOutletCard({
  rows,
  bootstrap,
}: {
  rows: SalesByOutletWidget["rows"]
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Ventas por sucursal</CardTitle>
      </CardHeader>
      <CardContent>
        <BarList
          items={rows.map((row) => {
            const delta = outletDelta(row)
            return {
              key: row.outletId,
              label: row.name,
              value: Number(row.total) || 0,
              display: formatMoney(row.total, bootstrap),
              meta: (
                <span className="flex items-baseline gap-2">
                  {delta && <DeltaLine {...delta} compact />}
                  <span>{formatShare(row.share, bootstrap)}</span>
                </span>
              ),
            }
          })}
        />
      </CardContent>
    </Card>
  )
}

/** "33,3%" con el separador del tenant; el backend ya redondeó a un decimal. */
function formatShare(share: number, bootstrap: ReturnType<typeof useBootstrap>["data"]): string {
  return `${new Intl.NumberFormat(resolveNumberLocale(bootstrap), { maximumFractionDigits: 1 }).format(share)}%`
}

// ── Ahora ────────────────────────────────────────────────────────────────

type Boot = ReturnType<typeof useBootstrap>["data"]

/**
 * El estado del momento, arriba de la columna derecha e independiente del rango. Cada tile
 * es una card con el link a donde eso se opera; el backend (`NowService`) ya
 * mandó solo las que tienen dato y que la persona puede abrir. Sin tiles, la
 * sección no existe.
 *
 * Una columna: vive en el sidebar, así que los tiles se apilan — nunca queda
 * un hueco al ocultarse uno.
 */
function NowSection({ tiles, bootstrap }: { tiles: NowTile[]; bootstrap: Boot }) {
  if (tiles.length === 0) return null
  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-xl font-semibold">Ahora</h2>
      <div className="flex flex-col gap-3">
        {tiles.map((t) => (
          <NowTileCard key={t.key} tile={t} bootstrap={bootstrap} />
        ))}
      </div>
    </section>
  )
}

function NowTileCard({ tile, bootstrap }: { tile: NowTile; bootstrap: Boot }) {
  switch (tile.key) {
    case "orders":
      return <NowOrders tile={tile} bootstrap={bootstrap} />
    case "spaces":
      return <NowSpaces tile={tile} bootstrap={bootstrap} />
    case "drawers":
      return <NowDrawers tile={tile} bootstrap={bootstrap} />
    case "staff":
      return <NowStaff tile={tile} bootstrap={bootstrap} />
    case "agenda":
      return <NowAgenda tile={tile} bootstrap={bootstrap} />
    case "dues":
      return <NowDues tile={tile} bootstrap={bootstrap} />
  }
}

/** Card de un tile: título canónico y el acceso a la pantalla donde se opera. */
function NowCard({
  title,
  href,
  children,
}: {
  title: string
  href?: string
  children: React.ReactNode
}) {
  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {href && (
          <CardAction>
            <Link
              href={href}
              className="text-muted-foreground transition-colors hover:text-foreground"
              aria-label={`Ir a ${title}`}
            >
              <ChevronRight className="size-4" />
            </Link>
          </CardAction>
        )}
      </CardHeader>
      <CardContent className="flex flex-col gap-2">{children}</CardContent>
    </Card>
  )
}

/** El número grande del tile con su unidad al lado. */
function NowFigure({ value, unit }: { value: string; unit: string }) {
  return (
    <div className="flex items-baseline gap-2">
      <span className="text-2xl font-semibold tabular-nums">{value}</span>
      <span className="text-sm text-muted-foreground">{unit}</span>
    </div>
  )
}

/** "y 3 más" cuando la lista del tile viene recortada. */
function MoreLine({ shown, total, bootstrap }: { shown: number; total: number; bootstrap: Boot }) {
  if (total <= shown) return null
  return (
    <span className="text-xs text-muted-foreground">
      y {formatInt(total - shown, bootstrap)} más
    </span>
  )
}

/**
 * Hora de una marca del momento: solo la hora si es de hoy, con la fecha si
 * no (una caja que quedó abierta desde ayer tiene que decirlo).
 */
function sinceLabel(iso: string): string {
  const d = new Date()
  const today = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`
  return iso.slice(0, 10) === today ? formatTime(iso) : formatDateTime(iso)
}

function NowOrders({ tile, bootstrap }: { tile: NowOrdersTile; bootstrap: Boot }) {
  return (
    <NowCard title="Órdenes" href={tile.href}>
      <NowFigure value={formatInt(tile.active, bootstrap)} unit={tile.active === 1 ? "activa" : "activas"} />
      {tile.late > 0 && (
        <span className="text-sm font-medium text-destructive">
          {formatInt(tile.late, bootstrap)} con más de {tile.lateMinutes} min en cocina
        </span>
      )}
    </NowCard>
  )
}

function NowSpaces({ tile, bootstrap }: { tile: NowSpacesTile; bootstrap: Boot }) {
  const busy = tile.occupied + tile.billRequested
  const details = [
    busy > 0 && tile.free > 0 ? `${formatInt(tile.free, bootstrap)} ${tile.free === 1 ? "libre" : "libres"}` : null,
    tile.billRequested > 0
      ? `${formatInt(tile.billRequested, bootstrap)} ${tile.billRequested === 1 ? "pidió" : "pidieron"} la cuenta`
      : null,
  ].filter(Boolean)
  return (
    <NowCard title="Espacios" href={tile.href}>
      {busy > 0 ? (
        <NowFigure
          value={`${formatInt(busy, bootstrap)} de ${formatInt(tile.total, bootstrap)}`}
          unit={busy === 1 ? "ocupado" : "ocupados"}
        />
      ) : (
        <NowFigure value={formatInt(tile.free, bootstrap)} unit={tile.free === 1 ? "libre" : "libres"} />
      )}
      {details.length > 0 && <span className="text-sm text-muted-foreground">{details.join(" · ")}</span>}
    </NowCard>
  )
}

function NowDrawers({ tile, bootstrap }: { tile: NowDrawersTile; bootstrap: Boot }) {
  // La sucursal solo suma cuando las cajas abiertas son de más de una.
  const manyOutlets = new Set(tile.rows.map((r) => r.outletName)).size > 1
  return (
    <NowCard title="Cajas abiertas" href={tile.href}>
      <ul className="flex flex-col divide-y divide-border">
        {tile.rows.map((r) => (
          <li
            key={r.drawerId}
            className="flex items-baseline justify-between gap-2 py-1.5 text-sm first:pt-0 last:pb-0"
          >
            <span className="flex min-w-0 flex-col">
              <span className="truncate">
                {r.registerName}
                {manyOutlets && r.outletName ? ` · ${r.outletName}` : ""}
              </span>
              {r.operator && <span className="truncate text-xs text-muted-foreground">{r.operator}</span>}
            </span>
            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
              desde {sinceLabel(r.openedAt)}
            </span>
          </li>
        ))}
      </ul>
      <MoreLine shown={tile.rows.length} total={tile.count} bootstrap={bootstrap} />
    </NowCard>
  )
}

function NowStaff({ tile, bootstrap }: { tile: NowStaffTile; bootstrap: Boot }) {
  return (
    <NowCard title="Personal presente" href={tile.href}>
      <NowFigure
        value={formatInt(tile.count, bootstrap)}
        unit={tile.count === 1 ? "persona" : "personas"}
      />
      <ul className="flex flex-col gap-1">
        {tile.people.map((p) => (
          <li key={p.employeeId} className="flex items-baseline justify-between gap-2 text-sm">
            <span className="min-w-0 truncate">{p.name}</span>
            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
              desde {formatTime(p.since)}
            </span>
          </li>
        ))}
      </ul>
      <MoreLine shown={tile.people.length} total={tile.count} bootstrap={bootstrap} />
    </NowCard>
  )
}

function NowAgenda({ tile, bootstrap }: { tile: NowAgendaTile; bootstrap: Boot }) {
  return (
    <NowCard title="Agenda de hoy" href={tile.href}>
      <NowFigure
        value={formatInt(tile.count, bootstrap)}
        unit={tile.count === 1 ? "cita pendiente" : "citas pendientes"}
      />
      <ul className="flex flex-col gap-1">
        {tile.next.map((a) => (
          <li key={a.id} className="flex items-baseline gap-2 text-sm">
            <span className="shrink-0 tabular-nums text-muted-foreground">{formatTime(a.from)}</span>
            {a.customer && <span className="min-w-0 truncate">{a.customer}</span>}
          </li>
        ))}
      </ul>
      <MoreLine shown={tile.next.length} total={tile.count} bootstrap={bootstrap} />
    </NowCard>
  )
}

/**
 * Lo que VENCE en la semana, por tipo, cada uno con su pantalla. No repite el
 * total "a pagar" de la card de Finanzas: acá va cuántos, cuándo el próximo y
 * cuánto por tipo, y lo ya vencido aparte.
 */
function NowDues({ tile, bootstrap }: { tile: NowDuesTile; bootstrap: Boot }) {
  const parts: { label: string; part: NowDuePart; overdue: [string, string] }[] = []
  const checks = visibleDuePart(tile.checks)
  const payables = visibleDuePart(tile.payables)
  if (checks) parts.push({ label: "Cheques emitidos", part: checks, overdue: ["vencido", "vencidos"] })
  if (payables) parts.push({ label: "Compras a pagar", part: payables, overdue: ["vencida", "vencidas"] })
  return (
    <NowCard title="Vencimientos de la semana">
      <ul className="flex flex-col divide-y divide-border">
        {parts.map(({ label, part, overdue }) => (
          <li key={label} className="py-1.5 first:pt-0 last:pb-0">
            <Link href={part.href} className="group flex items-baseline justify-between gap-2 text-sm">
              <span className="flex min-w-0 flex-col">
                <span className="truncate group-hover:underline">{label}</span>
                <span className="text-xs text-muted-foreground">
                  {part.count > 0 && (
                    <>
                      {formatInt(part.count, bootstrap)} esta semana
                      {part.next && ` · el próximo ${formatDate(part.next)}`}
                    </>
                  )}
                  {part.count > 0 && part.overdue > 0 && " · "}
                  {part.overdue > 0 && (
                    <span className="font-medium text-destructive">
                      {formatInt(part.overdue, bootstrap)} {part.overdue === 1 ? overdue[0] : overdue[1]}
                    </span>
                  )}
                </span>
              </span>
              {part.count > 0 && (
                <span className="shrink-0 font-semibold tabular-nums">{formatMoney(part.amount, bootstrap)}</span>
              )}
            </Link>
          </li>
        ))}
      </ul>
    </NowCard>
  )
}

// ── Pequeños helpers ─────────────────────────────────────────────────────


function fmtMoney(
  v: number | undefined,
  bootstrap: ReturnType<typeof useBootstrap>["data"],
  loading: boolean,
): React.ReactNode {
  if (loading) return <Skeleton className="h-8 w-24 inline-block align-middle" />
  return formatMoney(v ?? 0, bootstrap)
}
