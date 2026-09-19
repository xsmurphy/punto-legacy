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
  Cell,
  ComposedChart,
  Label,
  Line,
  Pie,
  PieChart,
  ResponsiveContainer,
  XAxis,
  YAxis,
} from "recharts"

import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { BarList } from "@/components/charts/bar-list"
import { MiniBars } from "@/components/charts/mini-bars"
import { SplitBar } from "@/components/charts/split-bar"
import { DeltaLine, type StatDelta } from "@/components/stat-tile"
import {
  ChartContainer,
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
  type ForecastRowType,
} from "@/hooks/use-finance-forecast"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import {
  useDashboardGoal,
  useDashboardNow,
  useDashboardWidget,
  useIncomeChart,
  type CustomersWidget,
  type IncomeChartData,
  type IncomeChartPoint,
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
  showSplitBar,
  showTopCategories,
  showTopHours,
  showTopItems,
  visibleAttentionRows,
  visibleDuePart,
  visibleInfoRows,
  visibleNowTiles,
  visibleWeeklyGoal,
  type AttentionKey,
  type AttentionWidget,
  type InfoRowKey,
  type NowAgendaTile,
  type NowDrawersTile,
  type NowDuePart,
  type NowDuesTile,
  type NowOrdersTile,
  type NowSpacesTile,
  type NowStaffTile,
  type NowTile,
  type GridBlock,
} from "@/lib/dashboard/visibility"
import { formatInt, formatIntCompact, formatMoney, formatMoneyCompact } from "@/lib/format"
import { formatDate, formatDateTime, formatTime } from "@/lib/format-date"
import { resolveNumberLocale } from "@/lib/tenant-locale"
import {
  averageLabel,
  bucketTooltipLabel,
  formatBucketLabel,
  formatBucketTick,
  granularityUnit,
  rhythmTitle,
  tooltipPoint,
} from "@/lib/charts/granularity"
import { partialBarCells } from "@/components/domain/reports/partial-bar-cells"
import { cn } from "@/lib/utils"
import { isDashboardFirstLoad, isSettled } from "@/lib/dashboard/first-load"
import { goalPaceLabel, goalProgress, type WeeklyGoal } from "@/lib/dashboard/weekly-goal"
import { hourBars, hourRange, peakHour } from "@/lib/dashboard/top-hours"
import { DashboardHeader, DashboardSkeleton } from "@/components/domain/dashboard/dashboard-skeleton"
import {
  TileBar,
  TileCard,
  TileFigure,
  TileNote,
  TileRow,
  TileRows,
} from "@/components/domain/dashboard/tile"

/**
 * Dashboard — espejo del panel legacy con widgets agrupados.
 * Cada sección consulta su propio widget. Se siguen las shapes de
 * api/lib/Reports/DashboardService.php.
 */
export default function DashboardPage() {
  const { data: bootstrap, error: bootstrapError } = useBootstrap()
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
  // `keepPrevious`: un cambio de rango no vuelve a "sin datos" — la página
  // sigue armada con el rango anterior hasta que llega el nuevo.
  const periodo = React.useMemo(() => ({ ...opts, keepPrevious: true }), [opts])
  const ventas = React.useMemo(
    () => ({ ...periodo, enabled: canViewSales }),
    [periodo, canViewSales],
  )

  const stats = useDashboardWidget<IncomeOutcomeStatsWidget>("incomeOutcomeStats", ventas)
  const salesByOutlet = useDashboardWidget<SalesByOutletWidget>("salesByOutlet", ventas)
  const info = useDashboardWidget<InfoWidget>("info", periodo)
  const incomeChart = useIncomeChart(opts, { enabled: canViewSales, keepPrevious: true })
  const paymentStatus = useDashboardWidget<PaymentStatusWidget>("paymentStatus", ventas)
  const customers = useDashboardWidget<CustomersWidget>("customers", ventas)
  const topItems = useDashboardWidget<TopItemRow[]>("topItems", ventas)
  const topCategories = useDashboardWidget<TopTaxonomyRow[]>("topCategories", ventas)
  const topHours = useDashboardWidget<TopHoursWidget>("topHours", ventas)
  // NPS oculto (ver comentario en <aside>) — fetch de "satisfaction" removido:
  // quedaría huérfano sin SatisfactionCard montado.
  // "Requiere atención": sin `enabled` a propósito — no es de ventas; el
  // backend gatea FILA por fila con el permiso de la pantalla a la que linkea
  // cada una, así que un usuario sin reportes igual ve lo que sí le compete.
  const attention = useDashboardWidget<AttentionWidget>("attention", periodo)
  // "Ahora": el estado del momento, INDEPENDIENTE del rango elegido. Sin
  // `enabled` por la misma razón que "Requiere atención": el backend gatea
  // fila por fila (módulo y/o permiso de la pantalla a la que lleva cada una).
  const now = useDashboardNow()
  // "Objetivo semanal": tampoco sigue al rango (semana en curso contra la
  // mejor de las últimas 12). Es plata del comercio: va con la clave de ventas.
  const goal = useDashboardGoal({ enabled: canViewSales })
  // Finanzas: sus queries viven acá (y no dentro de la card) para que la
  // primera carga las espere también. Sin `finance.manage` no se disparan.
  const canManageFinance = usePermission("finance.manage")
  const forecastRange = React.useMemo(() => ({ to: addDaysISO(new Date(), 7) }), [])
  const financeSummary = useFinanceSummary(undefined, { enabled: canManageFinance })
  const financeForecast = useFinanceForecast(forecastRange, { enabled: canManageFinance })

  const nowTiles = visibleNowTiles(now.data)
  const drawersTile = nowTiles.find((t): t is NowDrawersTile => t.key === "drawers")
  const weeklyGoal = visibleWeeklyGoal(goal.data)
  const deltas = kpiDeltas(stats.data)

  /**
   * Primera carga = skeleton de la página completa (`DashboardSkeleton`).
   * Sin esto, las queries de ventas arrancan APAGADAS (`usePermission` es
   * false hasta que llega el bootstrap) y una query apagada tiene
   * `isLoading=false` sin datos: la página pintaba los bloques reales vacíos,
   * después skeletons sueltos y recién después el contenido. Las reglas de
   * visibilidad (`lib/dashboard/visibility.ts`) se evalúan solo con datos
   * resueltos. Un error del bootstrap cuenta como resuelto (sin permisos):
   * nunca skeleton infinito.
   */
  const permisosResueltos =
    bootstrap?.user?.permissions !== undefined || Boolean(bootstrapError)
  const firstLoad = isDashboardFirstLoad({
    permissionsResolved: permisosResueltos,
    queries: [
      { query: info, enabled: true },
      { query: attention, enabled: true },
      { query: now, enabled: true },
      { query: goal, enabled: canViewSales },
      { query: stats, enabled: canViewSales },
      { query: incomeChart, enabled: canViewSales },
      { query: salesByOutlet, enabled: canViewSales },
      { query: paymentStatus, enabled: canViewSales },
      { query: customers, enabled: canViewSales },
      { query: topItems, enabled: canViewSales },
      { query: topCategories, enabled: canViewSales },
      { query: topHours, enabled: canViewSales },
      { query: financeSummary, enabled: canManageFinance },
      { query: financeForecast, enabled: canManageFinance },
    ],
  })

  // Después de la primera carga, un cambio de rango muestra los datos del rango
  // anterior como placeholder: los skeletons locales (los que ya existían) se
  // pintan mientras tanto sobre los números, sin desarmar la página.
  const statsPending = stats.isLoading || stats.isPlaceholderData
  const chartPending = incomeChart.isLoading || incomeChart.isPlaceholderData

  // "Negocio sin actividad" = NUNCA vendió (lifetime, info.hasSales).
  // No gateamos por itemsCount/clientes: al crear la cuenta se seedean
  // artículos y contactos, así que esos nunca son 0. Tampoco por
  // transactionsCount: es del MES calendario, y usarlo acá mostraba
  // "Bienvenido a Punto" cada día 1 del mes a cuentas con historial de
  // ventas (bug 2026-08-01). Fallback al gate mensual solo si hasSales no
  // vino (backend sin deployar) — undefined nunca fuerza el hero solo.
  const isEmptyState =
    isSettled(info) &&
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

  // Sin permisos todavía no se sabe qué layout va: skeleton del caso típico.
  if (!permisosResueltos) return <DashboardSkeleton />

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
  if (!canViewSales) {
    // Sin ventas, "Ahora" igual aplica: órdenes, espacios o la agenda son de
    // quien atiende, no del reporte de ventas. El vacío de página solo va si
    // tampoco hay nada del momento que mostrarle.
    return (
      <div className="flex flex-col gap-6">
        <header className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Resumen general</h1>
        </header>
        <DashboardGrid blocks={nowBlocks(nowTiles, bootstrap)} />
        {/* "Sin acceso" solo con "Ahora" resuelto: mientras carga no se sabe. */}
        {isSettled(now) && nowTiles.length === 0 && (
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

  if (firstLoad) return <DashboardSkeleton />

  return (
    <div className="flex flex-col gap-6">
      <DashboardHeader actions={<DateRangePicker value={range} onChange={setRange} />} />

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
              value={fmtMoney(stats.data?.total, bootstrap, statsPending)}
              isLoading={statsPending}
              sparkline={incomeChart.data?.data.map((p) => p.ingresos)}
              sparklineColor="var(--chart-1)"
              trend="up"
              delta={deltas.total}
            />
            <BigMetricCard
              label="Egresos"
              href="/purchase"
              value={fmtMoney(stats.data?.expenses, bootstrap, statsPending)}
              isLoading={statsPending}
              sparkline={incomeChart.data?.data.map((p) => p.egresos)}
              sparklineColor="var(--muted-foreground)"
              trend="down"
              delta={deltas.expenses}
            />
          </section>

          {/* Chart Ingresos vs Egresos + Margen — con sidebar de KPIs derivados
              (Ganancia / Margen% / Cant. Ventas) a la derecha en lg+. */}
          <section>
            <IncomeOutcomeChart
              data={incomeChart.data}
              isLoading={chartPending}
              error={incomeChart.error}
              bootstrap={bootstrap}
            />
          </section>

          {/* Grilla de 2 columnas debajo del gráfico (owner 2026-09-19: la
              columna derecha quedó para lo que RESUME). Primero lo del
              momento (órdenes, espacios, cajas, vencimientos), después los
              rankings y horarios, la composición de las ventas, clientes y
              sucursales. Cada bloque se pinta SOLO si tiene algo que decir
              (lib/dashboard/visibility.ts) y `packGrid` los acomoda para que
              ocultar uno no deje un hueco ni un compacto quede al lado de uno
              alto. */}
          <DashboardGrid
            blocks={{
              ...nowBlocks(nowTiles.filter((t) => t.key !== "drawers"), bootstrap),
              topItems: showTopItems(topItems.data) && (
                <TopItemsCard data={topItems.data ?? []} bootstrap={bootstrap} />
              ),
              topCategories: showTopCategories(topCategories.data) && (
                <TopCategoriesCard data={topCategories.data ?? []} bootstrap={bootstrap} />
              ),
              topHours: showTopHours(topHours.data) && (
                <TopHoursCard data={topHours.data!} bootstrap={bootstrap} />
              ),
              saleType: showSplitBar(paymentStatus.data, "sale-type") && (
                <PaymentSplitCard
                  title="Tipos de venta"
                  data={paymentStatus.data}
                  bootstrap={bootstrap}
                  mode="sale-type"
                />
              ),
              receivables: showSplitBar(paymentStatus.data, "receivables") && (
                <PaymentSplitCard
                  title="Cuentas por cobrar"
                  data={paymentStatus.data}
                  bootstrap={bootstrap}
                  mode="receivables"
                />
              ),
              customers: showCustomers(customers.data) && (
                <CustomersCard data={customers.data} bootstrap={bootstrap} />
              ),
              salesByOutlet: showSalesByOutlet(salesByOutlet.data?.rows) && (
                <SalesByOutletCard rows={salesByOutlet.data!.rows} bootstrap={bootstrap} />
              ),
              info: visibleInfoRows(stats.data, info.data).length > 0 && (
                <InfoGeneralCard stats={stats.data} info={info.data} bootstrap={bootstrap} />
              ),
            }}
          />
        </div>

        {/* ── SIDEBAR ────────────────────────────────────────────────────── */}
        {/* Solo lo que RESUME (owner 2026-09-19): Objetivo semanal → Ganancia
            → Requiere atención → Finanzas. Lo del momento y los rankings
            viven en la grilla de la columna principal. */}
        <aside className="flex min-w-0 flex-col gap-4">
          {weeklyGoal && <WeeklyGoalCard goal={weeklyGoal} bootstrap={bootstrap} />}
          {/* KPIs del período, debajo del objetivo (owner): la
            Ganancia manda (cifra destacada, su comparativa como texto en la
            línea del título) y el resto va como filas de UNA línea
            (comparativa en texto antes del valor) directo sobre el gris de la
            card, sin pills ni caja interna (owner 2026-09-19). */}
          <TileCard title="Ganancia" delta={statsPending ? undefined : deltas.revenue}>
            <TileFigure
              value={formatMoney(stats.data?.revenue, bootstrap)}
              loading={statsPending}
            />
            <TileRows>
              <TileRow
                label="Margen"
                value={statsPending ? null : `${stats.data?.margin ?? 0}%`}
                delta={deltas.margin}
              />
              <TileRow
                label="Ventas"
                value={statsPending ? null : formatInt(stats.data?.count, bootstrap)}
                delta={deltas.count}
              />
              {/* Sin ventas no hay ticket que promediar: la fila no va. */}
              {(statsPending || Number(stats.data?.count ?? 0) > 0) && (
                <TileRow
                  label="Ticket promedio"
                  value={statsPending ? null : formatMoney(stats.data?.customerAverage ?? 0, bootstrap)}
                  delta={deltas.customerAverage}
                  emphasis
                />
              )}
            </TileRows>
          </TileCard>
          {/* Cajas abiertas vuelve a la columna derecha (owner): la mayoría
              de los comercios tiene UNA caja y una card ancha en la grilla
              quedaba casi vacía. */}
          {drawersTile && <NowDrawers tile={drawersTile} bootstrap={bootstrap} soft />}
          <AttentionCard data={attention.data} bootstrap={bootstrap} />
          {canManageFinance && (
            <FinanceCard summary={financeSummary} forecast={financeForecast} bootstrap={bootstrap} />
          )}
          {/* NPS oculto a pedido del owner — el módulo de satisfacción de
              clientes todavía no está desarrollado. Componente y helpers
              (SatisfactionCard, NpsTooltipRow) quedan dormidos: la feature
              vuelve más adelante. */}
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
        <div className="flex items-center justify-between gap-2">
          <div className="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground">
            {TrendIcon && <TrendIcon className={cn("size-3.5 shrink-0", trendColor)} />}
            <span className="truncate">{label}</span>
          </div>
          {/* Comparativa en la línea del título (owner), no junto al monto. */}
          {delta && !isLoading && (
            <span className="ml-auto">
              <DeltaLine {...delta} compact />
            </span>
          )}
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
          <span className="text-3xl font-bold tracking-tight tabular-nums">{value}</span>
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
        <Skeleton className="h-4 w-48 self-end" />
        <Skeleton className="h-[240px] w-full" />
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="flex flex-col gap-2">
        <div className="flex h-[240px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
          {error?.message || "No se pudieron cargar los datos del chart."}
        </div>
      </div>
    )
  }

  const hasData = data.data.some((p) => p.ingresos > 0 || p.egresos > 0)
  // Mejor período por ingresos; con un solo punto no hay "mejor" que decir.
  const best =
    data.data.length > 1
      ? data.data.reduce<IncomeChartPoint | null>(
          (acc, p) => (p.ingresos > 0 && (!acc || p.ingresos > acc.ingresos) ? p : acc),
          null,
        )
      : null
  return (
    // Sin eje de montos ni leyenda (owner): la portada es un vistazo. El título
    // no repite las series ("Margen, ingresos y egresos"): dice el RITMO del
    // gráfico ("Día a día") y el dato que más se busca, el mejor período.
    <div className="flex flex-col gap-3" role="figure" aria-label="Margen, ingresos y egresos">
      <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <div className="flex flex-col gap-1">
          <h2 className="text-xl font-semibold">{rhythmTitle(data.granularity)}</h2>
          {best && (
            <p className="text-sm text-muted-foreground">
              Tu mejor {granularityUnit(data.granularity)}:{" "}
              {formatBucketLabel(best.bucket, data.granularity, best.end)} ·{" "}
              {formatMoneyCompact(best.ingresos, bootstrap)}
            </p>
          )}
        </div>
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
              {/* Sin grilla ni eje de montos (owner): la portada es un vistazo,
                  los números exactos viven en el tooltip y en los reportes. */}
              <XAxis
                dataKey="bucket"
                tickFormatter={(v: string) => formatBucketTick(String(v), data.granularity)}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <YAxis hide />
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
 * tiene el permiso, la card no se monta y la página no dispara sus fetches
 * (las queries viven en la página para entrar en la primera carga).
 */
function FinanceCard({
  summary,
  forecast,
  bootstrap,
}: {
  summary: ReturnType<typeof useFinanceSummary>
  forecast: ReturnType<typeof useFinanceForecast>
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const obligations = [...(forecast.data?.obligations ?? [])].sort((a, b) => {
    const overdueA = isForecastOverdue(a.dueDate)
    const overdueB = isForecastOverdue(b.dueDate)
    if (overdueA !== overdueB) return overdueA ? -1 : 1
    return a.dueDate.localeCompare(b.dueDate)
  })
  const top3 = obligations.slice(0, 3)
  const totalToPay = obligations.reduce((s, r) => s + r.amount, 0)

  return (
    <TileCard title="Finanzas" href="/finanzas/prevision" linkLabel="Ver previsión">
      <TileFigure
        label="Saldo disponible"
        value={formatMoney(summary.data?.totalBalance ?? 0, bootstrap)}
        loading={summary.isLoading}
      />
      <TileRows>
        <TileRow
          label="Próximos 7 días"
          value={forecast.isLoading ? null : formatMoney(totalToPay, bootstrap)}
          emphasis
        />
        {!forecast.isLoading &&
          top3.map((row) => {
            const overdue = isForecastOverdue(row.dueDate)
            return (
              <TileRow
                key={`${row.type}-${row.id}`}
                label={`${FORECAST_TYPE_LABELS[row.type]} · ${formatDate(row.dueDate)}`}
                value={formatMoney(row.amount, bootstrap)}
                tone={overdue ? "destructive" : undefined}
              />
            )
          })}
      </TileRows>
      {!forecast.isLoading && top3.length === 0 && <TileNote>Sin vencimientos próximos.</TileNote>}
    </TileCard>
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

/**
 * Barra partida en dos. Solo se monta con las DOS partes en > 0
 * (`showSplitBar`): con una sola no informa y el bloque no existe. Bloque
 * compacto (media altura) en la grilla del período.
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
  const left = Number((isSaleType ? data?.contado : data?.cobrado) ?? 0)
  const right = Number((isSaleType ? data?.credito : data?.porcobrar) ?? 0)
  const leftCount = isSaleType ? data?.contadoCount : data?.cobradoCount
  const rightCount = isSaleType ? data?.creditoCount : data?.porcobrarCount
  const totalCount = Number(leftCount ?? 0) + Number(rightCount ?? 0)

  return (
    <TileCard
      title={title}
      variant="default"
      description={totalCount > 0 ? `${formatInt(totalCount, bootstrap)} ventas` : undefined}
    >
      <SplitBar
        parts={[
          {
            label: isSaleType ? "Al contado" : "Cobrado",
            value: left,
            display: formatMoneyCompact(left, bootstrap),
          },
          {
            label: isSaleType ? "A crédito" : "Por cobrar",
            value: right,
            display: formatMoneyCompact(right, bootstrap),
          },
        ]}
      />
    </TileCard>
  )
}

// ── Clientes ──────────────────────────────────────────────────────────────

function CustomersCard({
  data,
  bootstrap,
}: {
  data: CustomersWidget | undefined
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  // Mismos números que el reporte de clientes al que linkea (el backend delega
  // en el mismo servicio). "Total" = clientes ACTIVOS del período, no el padrón.
  // Una tasa `null` es "sin base de comparación" (el período anterior no tuvo
  // clientes): la fila no se muestra — un 0% ahí diría otra cosa.
  const t = data?.totales
  const tasas = data?.tasas

  const nuevos = Number(t?.nuevos ?? 0)
  const recurrentes = Number(t?.recurrentes ?? 0)
  const total = Number(t?.activos ?? nuevos + recurrentes)
  const slices = [
    { key: "Nuevos", value: nuevos, fill: "var(--chart-1)" },
    { key: "Recurrentes", value: recurrentes, fill: "var(--chart-3)" },
  ]
  // Tasas como grilla 2×2 de cifras chicas, sin barras (owner: el tablero ya
  // tiene demasiadas barras horizontales). Una tasa null no se muestra.
  const rates = [
    { label: "Retorno", percent: toPct(tasas?.retorno), bad: false },
    { label: "Retención", percent: toPct(tasas?.retencion), bad: false },
    { label: "Crecimiento", percent: toPct(tasas?.crecimiento), bad: false },
    { label: "Pérdida", percent: toPct(tasas?.perdida), bad: true },
  ].filter((r) => r.percent !== null)

  return (
    <TileCard title="Clientes" variant="default">
      {/* Donut nuevos/recurrentes con el total al centro, leyenda al lado. */}
      <div className="flex items-center gap-4">
        <ChartContainer
          config={{
            Nuevos: { label: "Nuevos", color: "var(--chart-1)" },
            Recurrentes: { label: "Recurrentes", color: "var(--chart-3)" },
          }}
          className="aspect-square h-[120px] shrink-0"
        >
          <PieChart>
            <ChartTooltip content={<ChartTooltipContent nameKey="key" hideLabel />} />
            <Pie
              data={slices.filter((d) => d.value > 0)}
              dataKey="value"
              nameKey="key"
              innerRadius="68%"
              outerRadius="100%"
              paddingAngle={2}
              strokeWidth={0}
            >
              {slices
                .filter((d) => d.value > 0)
                .map((d) => (
                  <Cell key={d.key} fill={d.fill} />
                ))}
              <Label
                content={({ viewBox }) => {
                  if (!viewBox || !("cx" in viewBox)) return null
                  return (
                    <text x={viewBox.cx} y={viewBox.cy} textAnchor="middle" dominantBaseline="middle">
                      <tspan x={viewBox.cx} dy="-0.35em" className="fill-foreground text-xl font-semibold tabular-nums">
                        {formatInt(total, bootstrap)}
                      </tspan>
                      <tspan x={viewBox.cx} dy="1.5em" className="fill-muted-foreground text-xs">
                        clientes
                      </tspan>
                    </text>
                  )
                }}
              />
            </Pie>
          </PieChart>
        </ChartContainer>
        <ul className="flex min-w-0 flex-1 flex-col gap-2 text-sm">
          {slices.map((d) => (
            <li key={d.key} className="flex items-center gap-2">
              <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: d.fill }} aria-hidden />
              <span className="min-w-0 flex-1 truncate text-muted-foreground">{d.key}</span>
              <span className="font-medium tabular-nums">{formatInt(d.value, bootstrap)}</span>
            </li>
          ))}
        </ul>
      </div>
      {rates.length > 0 && (
        <div className="grid grid-cols-2 gap-2">
          {rates.map((r) => (
            <div key={r.label} className="flex flex-col gap-0.5 rounded-lg bg-muted/50 px-3 py-2">
              <span className="text-xs text-muted-foreground">{r.label}</span>
              <span className={cn("text-sm font-semibold tabular-nums", r.bad && (r.percent ?? 0) > 0 && "text-destructive")}>
                {formatPercent(r.percent ?? 0, bootstrap)}%
              </span>
            </div>
          ))}
        </div>
      )}
    </TileCard>
  )
}

/** Porcentaje legible: sin decimales desde 10, uno por debajo (58,3 → 58). */
function formatPercent(n: number, bootstrap: ReturnType<typeof useBootstrap>["data"]): string {
  const digits = Math.abs(n) >= 10 ? 0 : 1
  return new Intl.NumberFormat(resolveNumberLocale(bootstrap), { maximumFractionDigits: digits }).format(n)
}

function toPct(v: unknown): number | null {
  if (v === undefined || v === null) return null
  const n = typeof v === "string" ? parseFloat(v) : typeof v === "number" ? v : NaN
  if (!Number.isFinite(n)) return null
  return n
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
}: {
  stats: IncomeOutcomeStatsWidget | undefined
  info: InfoWidget | undefined
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const keys = visibleInfoRows(stats, info)
  if (keys.length === 0) return null
  return (
    <TileCard title="Información general" variant="default">
      <TileRows>
        {keys.map((k) => {
          const r = INFO_ROW[k]
          return <TileRow key={k} label={r.label} href={r.href} value={r.value(stats, info, bootstrap)} />
        })}
      </TileRows>
    </TileCard>
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
    <TileCard title="Requiere atención">
      <TileRows>
        {rows.map((r) => {
          const isDebt = r.key === "receivables"
          return (
            <TileRow
              key={r.key}
              href={r.href}
              label={ATTENTION_LABEL[r.key]}
              note={
                isDebt ? (
                  <TileNote>
                    {formatInt(r.count, bootstrap)} {r.count === 1 ? "cliente" : "clientes"}
                  </TileNote>
                ) : undefined
              }
              value={isDebt ? formatMoney(r.amount ?? 0, bootstrap) : formatInt(r.count, bootstrap)}
            />
          )
        })}
      </TileRows>
    </TileCard>
  )
}


// ── Objetivo semanal ──────────────────────────────────────────────────────

/**
 * La semana en curso contra la mejor de las últimas 12 (`WeeklyGoalService`).
 * Única card oscura de la pantalla (`variant="inverse"`: el tema invertido,
 * en dark se invierte sola). La barra mide contra el TOTAL de la mejor
 * semana; la marca y la línea de estado, contra lo que esa semana llevaba a
 * esta misma altura. Solo se monta con historia suficiente
 * (`visibleWeeklyGoal`).
 */
function WeeklyGoalCard({ goal, bootstrap }: { goal: WeeklyGoal; bootstrap: Boot }) {
  const p = goalProgress(goal)
  return (
    <TileCard
      title="Objetivo semanal"
      variant="inverse"
      description={`Mejor semana: ${formatDateTime(goal.best.weekStart, "d MMM")} al ${formatDateTime(goal.best.weekEnd, "d MMM")}`}
    >
      <TileFigure
        value={formatMoney(goal.current, bootstrap)}
        note={
          p.remaining > 0 ? (
            <TileNote>Te faltan {formatMoneyCompact(p.remaining, bootstrap)} para igualarla</TileNote>
          ) : undefined
        }
      />
      <div className="flex flex-col gap-1.5">
        <TileBar
          percent={p.percent}
          marker={p.pace === "passed" ? null : p.marker}
          markerLabel="Ritmo de tu mejor semana"
        />
        <div className="flex items-baseline justify-between gap-3">
          <TileNote tone="strong">{goalPaceLabel(p)}</TileNote>
          <TileNote>{formatMoney(goal.best.total, bootstrap)}</TileNote>
        </div>
      </div>
    </TileCard>
  )
}

// ── Grilla de la columna principal ────────────────────────────────────────

/** Las cards del momento, una por tile de "Ahora" (`NOW_KEYS`). */
type NowBlockKey = `now-${NowTile["key"]}`

type DashboardBlockKey =
  | NowBlockKey
  | "topItems"
  | "topCategories"
  | "topHours"
  | "saleType"
  | "receivables"
  | "customers"
  | "salesByOutlet"
  | "info"

/**
 * Orden de pantalla y ancho natural de cada bloque (owner 2026-09-19): lo del
 * momento, rankings y horarios, composición, clientes y sucursales. Los
 * rankings son listas con barras (`BarList`) y entran en media fila; los
 * compactos (una cifra, una barra partida) solo se aparean entre sí;
 * `packGrid` estira el que quede solo.
 */
const DASHBOARD_BLOCKS: GridBlock<DashboardBlockKey>[] = [
  { key: "now-orders", full: false, short: true },
  { key: "now-spaces", full: false, short: true },
  { key: "now-drawers", full: false },
  { key: "now-dues", full: false },
  { key: "now-staff", full: false },
  { key: "now-agenda", full: false },
  { key: "topItems", full: false },
  { key: "topCategories", full: false },
  { key: "topHours", full: false, short: true },
  { key: "saleType", full: false, short: true },
  { key: "receivables", full: false, short: true },
  { key: "customers", full: false },
  { key: "salesByOutlet", full: false },
  { key: "info", full: false, short: true },
]

type DashboardBlocks = Partial<Record<DashboardBlockKey, React.ReactNode | false>>

/**
 * Grilla de 2 columnas con los bloques que tienen algo que mostrar. Un bloque
 * en `false` (o ausente) no existe; `packGrid` reacomoda el resto para que no
 * quede un hueco (una media fila sola sube a la próxima media fila o se
 * estira).
 */
function DashboardGrid({ blocks }: { blocks: DashboardBlocks }) {
  const visible = DASHBOARD_BLOCKS.filter((b) => blocks[b.key])
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

/**
 * Bloque compacto: la hora pico destacada y mini barras de las horas con más
 * ventas, en orden del día. El detalle (ventas y unidades) va en el tooltip de
 * cada barra. Solo se monta con ventas en el período (`showTopHours`).
 */
function TopHoursCard({
  data,
  bootstrap,
}: {
  data: TopHoursWidget
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const bars = React.useMemo(() => hourBars(data), [data])
  const peak = peakHour(bars)
  if (!peak) return null

  return (
    <TileCard title="Horarios pico" variant="default" contentClassName="flex-row items-end gap-6">
      <div className="shrink-0">
        <TileFigure
          value={hourRange(peak.hour)}
          note={<TileNote>{formatInt(peak.sales, bootstrap)} ventas</TileNote>}
        />
      </div>
      <MiniBars
        className="min-w-0 flex-1"
        items={bars.map((b) => {
          const sales = `${formatInt(b.sales, bootstrap)} ventas`
          const units = b.units === null ? null : `${formatIntCompact(b.units, bootstrap)} u.`
          return {
            key: String(b.hour),
            label: String(b.hour),
            value: b.sales,
            highlight: b.hour === peak.hour,
            ariaLabel: [hourRange(b.hour), sales, units].filter(Boolean).join(", "),
            detail: (
              <span className="flex flex-col gap-0.5 tabular-nums">
                <span className="font-medium">{hourRange(b.hour)}</span>
                <span>{units ? `${sales} · ${units}` : sales}</span>
              </span>
            ),
          }
        })}
      />
    </TileCard>
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
    <TileCard title="Top 5 artículos" variant="default">
      <BarList
        items={data.map((row, i) => ({
          key: `${row.name}-${i}`,
          label: row.name || "(sin nombre)",
          value: Number(row.count) || 0,
          display: formatMoneyCompact(row.total, bootstrap),
          meta: `${formatIntCompact(row.count, bootstrap)} u.`,
        }))}
      />
    </TileCard>
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
    <TileCard title="Top 5 categorías" variant="default">
      <BarList
        // 5, parejo con Top artículos (misma fila del grid).
        items={data.slice(0, 5).map((row, i) => ({
          key: `${row.title}-${i}`,
          label: row.title,
          value: Number(row.total) || 0,
          display: formatIntCompact(row.total, bootstrap),
        }))}
      />
    </TileCard>
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
    <TileCard title="Ventas por sucursal" variant="default">
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
    </TileCard>
  )
}

/** "33,3%" con el separador del tenant; el backend ya redondeó a un decimal. */
function formatShare(share: number, bootstrap: ReturnType<typeof useBootstrap>["data"]): string {
  return `${new Intl.NumberFormat(resolveNumberLocale(bootstrap), { maximumFractionDigits: 1 }).format(share)}%`
}

// ── Ahora ────────────────────────────────────────────────────────────────

type Boot = ReturnType<typeof useBootstrap>["data"]

/**
 * El estado del momento, independiente del rango: una card por tile, en la
 * grilla de la columna principal. El backend (`NowService`) ya mandó solo las
 * que tienen dato y que la persona puede abrir; un tile ausente no ocupa
 * lugar.
 */
function nowBlocks(tiles: NowTile[], bootstrap: Boot): DashboardBlocks {
  const out: DashboardBlocks = {}
  for (const t of tiles) {
    out[`now-${t.key}`] = <NowTileCard tile={t} bootstrap={bootstrap} />
  }
  return out
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
  soft,
  children,
}: {
  title: string
  href?: string
  /** En la columna derecha lleva su piel gris; en la grilla principal, blanca. */
  soft?: boolean
  children: React.ReactNode
}) {
  return (
    <TileCard title={title} href={href} variant={soft ? "soft" : "default"}>
      {children}
    </TileCard>
  )
}


/** "y 3 más" cuando la lista del tile viene recortada. */
function MoreLine({ shown, total, bootstrap }: { shown: number; total: number; bootstrap: Boot }) {
  if (total <= shown) return null
  return <TileNote>y {formatInt(total - shown, bootstrap)} más</TileNote>
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
      <TileFigure
        value={formatInt(tile.active, bootstrap)}
        unit={tile.active === 1 ? "activa" : "activas"}
        note={
          tile.late > 0 ? (
            <TileNote tone="destructive">
              {formatInt(tile.late, bootstrap)} con más de {tile.lateMinutes} min en cocina
            </TileNote>
          ) : undefined
        }
      />
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
  const note = details.length > 0 ? <TileNote>{details.join(" · ")}</TileNote> : undefined
  return (
    <NowCard title="Espacios" href={tile.href}>
      {busy > 0 ? (
        <TileFigure
          value={`${formatInt(busy, bootstrap)} de ${formatInt(tile.total, bootstrap)}`}
          unit={busy === 1 ? "ocupado" : "ocupados"}
          note={note}
        />
      ) : (
        <TileFigure
          value={formatInt(tile.free, bootstrap)}
          unit={tile.free === 1 ? "libre" : "libres"}
          note={note}
        />
      )}
    </NowCard>
  )
}

function NowDrawers({ tile, bootstrap, soft }: { tile: NowDrawersTile; bootstrap: Boot; soft?: boolean }) {
  // La sucursal solo suma cuando las cajas abiertas son de más de una.
  const manyOutlets = new Set(tile.rows.map((r) => r.outletName)).size > 1
  return (
    <NowCard title="Cajas abiertas" href={tile.href} soft={soft}>
      <TileRows>
        {tile.rows.map((r) => (
          <TileRow
            key={r.drawerId}
            label={`${r.registerName}${manyOutlets && r.outletName ? ` · ${r.outletName}` : ""}`}
            note={r.operator ? <TileNote>{r.operator}</TileNote> : undefined}
            value={`desde ${sinceLabel(r.openedAt)}`}
          />
        ))}
      </TileRows>
      <MoreLine shown={tile.rows.length} total={tile.count} bootstrap={bootstrap} />
    </NowCard>
  )
}

function NowStaff({ tile, bootstrap }: { tile: NowStaffTile; bootstrap: Boot }) {
  return (
    <NowCard title="Personal presente" href={tile.href}>
      <TileFigure
        value={formatInt(tile.count, bootstrap)}
        unit={tile.count === 1 ? "persona" : "personas"}
      />
      <TileRows>
        {tile.people.map((p) => (
          <TileRow key={p.employeeId} label={p.name} value={`desde ${formatTime(p.since)}`} />
        ))}
      </TileRows>
      <MoreLine shown={tile.people.length} total={tile.count} bootstrap={bootstrap} />
    </NowCard>
  )
}

function NowAgenda({ tile, bootstrap }: { tile: NowAgendaTile; bootstrap: Boot }) {
  return (
    <NowCard title="Agenda de hoy" href={tile.href}>
      <TileFigure
        value={formatInt(tile.count, bootstrap)}
        unit={tile.count === 1 ? "cita pendiente" : "citas pendientes"}
      />
      <TileRows>
        {tile.next.map((a) => (
          <TileRow key={a.id} label={formatTime(a.from)} value={a.customer || undefined} />
        ))}
      </TileRows>
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
      <TileRows>
        {parts.map(({ label, part, overdue }) => (
          <TileRow
            key={label}
            href={part.href}
            label={label}
            note={
              <TileNote>
                {part.count > 0 && (
                  <>
                    {formatInt(part.count, bootstrap)} esta semana
                    {part.next && ` · el próximo ${formatDate(part.next)}`}
                  </>
                )}
                {part.count > 0 && part.overdue > 0 && " · "}
                {part.overdue > 0 && (
                  <TileNote tone="destructive">
                    {formatInt(part.overdue, bootstrap)} {part.overdue === 1 ? overdue[0] : overdue[1]}
                  </TileNote>
                )}
              </TileNote>
            }
            value={part.count > 0 ? formatMoney(part.amount, bootstrap) : undefined}
          />
        ))}
      </TileRows>
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
