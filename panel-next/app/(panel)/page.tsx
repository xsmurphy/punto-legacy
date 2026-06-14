"use client"

import * as React from "react"
import Link from "next/link"
import {
  ArrowDownRight,
  ArrowUpRight,
  ChevronRight,
  CreditCard,
  Wallet,
  Users as UsersIcon,
  TrendingUp,
  TrendingDown,
  PackageCheck,
  Layers,
  Smile,
  Meh,
  Frown,
  Receipt,
  ShoppingBag,
  Gift,
  LayoutDashboard,
} from "lucide-react"
import { EmptyState } from "@/components/empty-state"
import { Hero115 } from "@/components/hero115"

import {
  Area,
  AreaChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  Pie,
  PieChart,
  Bar,
  BarChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Badge } from "@/components/ui/badge"
import { Progress } from "@/components/ui/progress"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import {
  useDashboardWidget,
  useIncomeChart,
  type CustomersWidget,
  type IncomeChartData,
  type IncomeChartPoint,
  type IncomeOutcomeStatsWidget,
  type InfoWidget,
  type OrdersWidget,
  type PaymentStatusWidget,
  type SatisfactionWidget,
  type TopHoursWidget,
  type TopItemRow,
  type TopTaxonomyRow,
} from "@/hooks/use-dashboard-widget"
import { formatInt, formatMoney } from "@/lib/format"
import { cn } from "@/lib/utils"

/**
 * Dashboard — espejo del panel legacy con widgets agrupados.
 * Cada sección consulta su propio widget. Se siguen las shapes de
 * api/lib/Reports/DashboardService.php.
 */
export default function DashboardPage() {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const stats = useDashboardWidget<IncomeOutcomeStatsWidget>("incomeOutcomeStats", opts)
  const info = useDashboardWidget<InfoWidget>("info", opts)
  const incomeChart = useIncomeChart(opts)
  const paymentStatus = useDashboardWidget<PaymentStatusWidget>("paymentStatus", opts)
  const customers = useDashboardWidget<CustomersWidget>("customers", opts)
  const topItems = useDashboardWidget<TopItemRow[]>("topItems", opts)
  const topCategories = useDashboardWidget<TopTaxonomyRow[]>("topCategories", opts)
  const topHours = useDashboardWidget<TopHoursWidget>("topHours", opts)
  const satisfaction = useDashboardWidget<SatisfactionWidget>("satisfaction", opts)
  const orders = useDashboardWidget<OrdersWidget>("orders", opts)

  // "Negocio sin actividad" = cero transacciones (el dashboard es de ventas).
  // No gateamos por itemsCount/clientes: al crear la cuenta se seedean
  // artículos y contactos, así que esos nunca son 0. La señal real de "no hay
  // nada para mostrar" es que todavía no se vendió. transactionsCount viene del
  // mes actual → para una cuenta nueva (nunca vendió) es 0 → mostramos el hero.
  const isEmptyState =
    !info.isLoading &&
    !info.error &&
    (info.data?.transactionsCount ?? 1) === 0

  if (isEmptyState) {
    return (
      <Hero115
        className="py-12"
        icon={<LayoutDashboard className="size-7" />}
        heading="Tu panel cobra vida con la primera venta"
        description="Acá vas a ver ingresos, márgenes, clientes y tus productos más vendidos, en tiempo real. Registrá una venta en la caja o cargá tu catálogo para empezar."
        buttons={{ primary: { text: "Ir a la caja", url: "/pos" } }}
        byline="Tu resumen se actualiza solo a medida que vendés."
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
        {/* ── MAIN COLUMN (8/12 legacy) ─────────────────────────────────── */}
        <div className="flex min-w-0 flex-col gap-4">
          {/* KPI ROW — 4 cards compactos. Los 2 monetarios (Ingresos/Egresos)
              llevan sparkline reusando la serie de incomeChart (mismo endpoint
              que el chart grande de abajo — sin fetch extra). Tickets y Ticket
              promedio van sin sparkline porque no hay serie temporal disponible
              hoy (requeriría endpoint nuevo). */}
          <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <MetricCard
              label="Ingresos"
              href="/reports/summary"
              currency={bootstrap?.currency ?? ""}
              value={fmtMoney(stats.data?.total, bootstrap, stats.isLoading)}
              isLoading={stats.isLoading}
              sparkline={incomeChart.data?.data.map((p) => p.ingresos)}
              sparklineColor="var(--brand)"
              trend="up"
            />
            <MetricCard
              label="Egresos"
              href="/purchase"
              currency={bootstrap?.currency ?? ""}
              value={fmtMoney(stats.data?.expenses, bootstrap, stats.isLoading)}
              isLoading={stats.isLoading}
              sparkline={incomeChart.data?.data.map((p) => p.egresos)}
              sparklineColor="var(--destructive)"
              trend="down"
            />
            <MetricCard
              label="Tickets"
              value={stats.isLoading ? null : formatInt(stats.data?.count, bootstrap)}
              isLoading={stats.isLoading}
              hint="emitidos en el período"
            />
            <MetricCard
              label="Ticket promedio"
              currency={bootstrap?.currency ?? ""}
              value={fmtMoney(stats.data?.customerAverage, bootstrap, stats.isLoading)}
              isLoading={stats.isLoading}
              hint={stats.isLoading ? "" : `Margen ${stats.data?.margin ?? 0}%`}
            />
          </section>

          {/* Chart full-width: Margen / Ingresos / Egresos. Antes ocupaba 2/3
              del row con Ganancias al lado — Ganancias salió porque ya está
              implícito en Ingresos vs Egresos del row 1. */}
          <section>
            <IncomeAreaChart
              data={incomeChart.data}
              isLoading={incomeChart.isLoading}
              error={incomeChart.error}
              bootstrap={bootstrap}
            />
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

          {/* Clientes + Top 5 Artículos */}
          <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <CustomersCard data={customers.data} isLoading={customers.isLoading} />
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

        {/* ── SIDEBAR (4/12 legacy) ─────────────────────────────────────── */}
        <aside className="flex min-w-0 flex-col gap-4">
          <SatisfactionCard
            data={satisfaction.data}
            isLoading={satisfaction.isLoading}
          />
          <OrdersCard
            orders={orders.data}
            isLoading={orders.isLoading}
            bootstrap={bootstrap}
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
 * MetricCard — KPI compacto con sparkline opcional al pie. Patrón del shadcn
 * dashboard reference: label arriba, valor grande, hint sutil, sparkline.
 * Pasar `sparkline` (array de números) para los KPIs que tengan serie temporal
 * disponible (Ingresos/Egresos vía useIncomeChart); omitirlo para KPIs sin
 * serie (Tickets, Ticket promedio — requeriría endpoint nuevo).
 *
 * `trend` solo afecta el ícono del label (ArrowUp/Down). El delta % vs período
 * anterior NO se calcula hoy porque el backend no devuelve la comparación;
 * queda como follow-up cuando se quiera replicar el "+X% from last month" del
 * mockup de referencia.
 */
function MetricCard({
  label,
  href,
  currency,
  value,
  isLoading,
  hint,
  sparkline,
  sparklineColor,
  trend,
}: {
  label: string
  href?: string
  currency?: string
  value: React.ReactNode
  isLoading: boolean
  hint?: string
  sparkline?: number[]
  sparklineColor?: string
  trend?: "up" | "down"
}) {
  const TrendIcon = trend === "up" ? ArrowUpRight : trend === "down" ? ArrowDownRight : null
  const trendColor = trend === "up" ? "text-[var(--brand)]" : trend === "down" ? "text-destructive" : ""

  return (
    <Card className="relative overflow-hidden">
      <CardContent className="flex flex-col gap-2">
        <div className="flex items-start justify-between gap-2">
          <div className="flex min-w-0 items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
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
          <Skeleton className="h-8 w-32" />
        ) : (
          <div className="flex items-baseline gap-1.5">
            {currency && (
              <span className="text-xs font-normal text-muted-foreground">{currency}</span>
            )}
            <span className="text-2xl font-bold tracking-tight tabular-nums">{value}</span>
          </div>
        )}

        {hint && !isLoading && (
          <span className="text-xs text-muted-foreground">{hint}</span>
        )}

        {sparkline && sparkline.length > 1 && (
          <Sparkline values={sparkline} color={sparklineColor ?? "var(--brand)"} />
        )}
      </CardContent>
    </Card>
  )
}

/**
 * Sparkline — mini line+area chart sin ejes ni labels. Recharts ResponsiveContainer
 * adentro de un wrapper de altura fija para que se ancle al ancho del card.
 */
function Sparkline({ values, color }: { values: number[]; color: string }) {
  const data = React.useMemo(
    () => values.map((v, i) => ({ i, v })),
    [values],
  )
  return (
    <div className="-mx-1 mt-1 h-10">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 2, right: 2, bottom: 2, left: 2 }}>
          <defs>
            <linearGradient id={`spark-${color}`} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={color} stopOpacity={0.25} />
              <stop offset="100%" stopColor={color} stopOpacity={0} />
            </linearGradient>
          </defs>
          <Area
            type="monotone"
            dataKey="v"
            stroke={color}
            strokeWidth={1.5}
            fill={`url(#spark-${color})`}
            isAnimationActive={false}
            dot={false}
            activeDot={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}

// ── Income chart (line/area) ──────────────────────────────────────────────

function IncomeAreaChart({
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
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">Margen, Ingresos y Egresos</CardTitle>
        </CardHeader>
        <CardContent>
          <Skeleton className="h-[220px] w-full" />
        </CardContent>
      </Card>
    )
  }

  if (error || !data) {
    return (
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">Margen, Ingresos y Egresos</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex h-[220px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
            {error?.message || "No se pudieron cargar los datos del chart."}
          </div>
        </CardContent>
      </Card>
    )
  }

  const hasData = data.data.some((p) => p.ingresos > 0 || p.egresos > 0)
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center justify-between text-sm font-medium">
          <span>Margen, Ingresos y Egresos</span>
          <span className="text-xs font-normal text-muted-foreground">
            Promedio: {formatMoney(data.totals.average, bootstrap)}
          </span>
        </CardTitle>
      </CardHeader>
      <CardContent>
        {!hasData ? (
          <div className="flex h-[220px] items-center justify-center">
            <EmptyState
              icon={TrendingUp}
              title="Sin movimientos en este período"
              showMarquee={false}
              className="border-0 p-0"
            />
          </div>
        ) : (
          <ResponsiveContainer width="100%" height={220}>
            <AreaChart data={data.data} margin={{ top: 10, right: 12, left: -10, bottom: 0 }}>
              {/* IMPORTANTE: usamos `var(--token)` directo (no `hsl(var(--token))`)
                  porque las tokens de color están en oklch — hsl() las descarta. */}
              <defs>
                <linearGradient id="grIng" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="var(--chart-1)" stopOpacity={0.4} />
                  <stop offset="100%" stopColor="var(--chart-1)" stopOpacity={0} />
                </linearGradient>
                <linearGradient id="grEgr" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="var(--destructive)" stopOpacity={0.3} />
                  <stop offset="100%" stopColor="var(--destructive)" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey="bucket"
                tickFormatter={(v: string) => formatBucketLabel(v, data.isDay)}
                fontSize={10}
                stroke="var(--muted-foreground)"
              />
              <YAxis
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickFormatter={(v: number) => compactNumber(v)}
              />
              <Tooltip content={<ChartTooltip bootstrap={bootstrap} isDay={data.isDay} />} />
              <Legend
                wrapperStyle={{ fontSize: 11 }}
                iconType="circle"
                formatter={(value: string) => <span className="text-muted-foreground">{value}</span>}
              />
              <Area
                type="monotone"
                dataKey="ingresos"
                name="Ingresos"
                stroke="var(--chart-1)"
                strokeWidth={2}
                fillOpacity={1}
                fill="url(#grIng)"
              />
              <Area
                type="monotone"
                dataKey="egresos"
                name="Egresos"
                stroke="var(--destructive)"
                strokeWidth={2}
                fillOpacity={1}
                fill="url(#grEgr)"
              />
              <Line
                type="monotone"
                dataKey="margen"
                name="Margen"
                stroke="var(--chart-3)"
                strokeWidth={2}
                dot={false}
              />
            </AreaChart>
          </ResponsiveContainer>
        )}
      </CardContent>
    </Card>
  )
}

function ChartTooltip({
  active,
  payload,
  label,
  bootstrap,
  isDay,
}: {
  active?: boolean
  payload?: Array<{ name?: string; value?: number; color?: string }>
  label?: string
  bootstrap?: ReturnType<typeof useBootstrap>["data"]
  isDay?: boolean
}) {
  if (!active || !payload || payload.length === 0) return null
  return (
    <div className="rounded-md border bg-popover px-3 py-2 text-xs shadow">
      <div className="mb-1 font-medium">{label ? formatBucketLabel(label, !!isDay) : ""}</div>
      {payload.map((p, i) => (
        <div key={i} className="flex items-center gap-2 tabular-nums">
          <span className="size-2 rounded-full" style={{ backgroundColor: p.color }} />
          <span className="text-muted-foreground">{p.name}:</span>
          <span className="font-medium">{formatMoney(p.value ?? 0, bootstrap)}</span>
        </div>
      ))}
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

// ── Órdenes + Satisfacción ─────────────────────────────────────────────────

function OrdersCard({
  orders,
  isLoading,
  bootstrap,
}: {
  orders: OrdersWidget | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  // Si el módulo orders está apagado, el endpoint devuelve [] (vacío).
  if (!isLoading && !orders) return <ModuleOffCard title="Órdenes" />
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <ShoppingBag className="size-4 text-muted-foreground" />
          Órdenes en curso
        </CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-2 gap-4">
        <Stat
          label="Total"
          value={isLoading ? null : formatInt(orders?.ordersCount, bootstrap)}
        />
        <Stat
          label="Online"
          value={isLoading ? null : formatInt(orders?.onlineCount, bootstrap)}
        />
      </CardContent>
    </Card>
  )
}

function SatisfactionCard({
  data,
  isLoading,
}: {
  data: SatisfactionWidget | undefined
  isLoading: boolean
}) {
  if (!isLoading && !data) return <ModuleOffCard title="Satisfacción (NPS)" />
  const det = data?.detractors.percent ?? 0
  const pas = data?.passives.percent ?? 0
  const pro = data?.promoters.percent ?? 0
  const totalResp = (data?.detractors.count ?? 0) + (data?.passives.count ?? 0) + (data?.promoters.count ?? 0)
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <Smile className="size-4 text-muted-foreground" />
          Satisfacción de clientes (NPS)
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {/* Barra apilada con los 3 segmentos (espejo del legacy) */}
        {isLoading ? (
          <Skeleton className="h-3 w-full rounded-full" />
        ) : (
          <div className="flex h-3 w-full overflow-hidden rounded-full bg-muted">
            {det > 0 && (
              <div
                className="bg-destructive transition-all"
                style={{ width: `${det}%` }}
                title={`Detractores · ${det}%`}
              />
            )}
            {pas > 0 && (
              <div
                className="bg-amber-500 transition-all"
                style={{ width: `${pas}%` }}
                title={`Pasivos · ${pas}%`}
              />
            )}
            {pro > 0 && (
              <div
                className="bg-emerald-500 transition-all"
                style={{ width: `${pro}%` }}
                title={`Promotores · ${pro}%`}
              />
            )}
          </div>
        )}

        <div className="grid grid-cols-3 gap-3">
          <NpsLegend
            icon={<Frown className="size-3.5 text-destructive" />}
            label="Detractores"
            percent={data?.detractors.percent ?? null}
            count={data?.detractors.count ?? null}
            isLoading={isLoading}
          />
          <NpsLegend
            icon={<Meh className="size-3.5 text-amber-500" />}
            label="Pasivos"
            percent={data?.passives.percent ?? null}
            count={data?.passives.count ?? null}
            isLoading={isLoading}
          />
          <NpsLegend
            icon={<Smile className="size-3.5 text-emerald-500" />}
            label="Promotores"
            percent={data?.promoters.percent ?? null}
            count={data?.promoters.count ?? null}
            isLoading={isLoading}
          />
        </div>

        {!isLoading && (
          <div className="text-center text-[10px] text-muted-foreground tabular-nums">
            {totalResp} respuestas en total
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function NpsLegend({
  icon,
  label,
  percent,
  count,
  isLoading,
}: {
  icon: React.ReactNode
  label: string
  percent: number | null
  count: number | null
  isLoading: boolean
}) {
  return (
    <div className="flex flex-col gap-0.5 text-center">
      <div className="flex items-center justify-center gap-1 text-[10px] uppercase tracking-wide text-muted-foreground">
        {icon}
        {label}
      </div>
      {isLoading ? (
        <Skeleton className="mx-auto h-5 w-10" />
      ) : (
        <>
          <span className="text-base font-semibold tabular-nums">{percent ?? 0}%</span>
          <span className="text-[10px] text-muted-foreground tabular-nums">{count ?? 0} resp.</span>
        </>
      )}
    </div>
  )
}

// ── Tipos de ventas + Cuentas por cobrar ──────────────────────────────────

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
  // incluso cuando todo es 0 usamos un placeholder gris.
  const pieData =
    total > 0
      ? [
          // Color principal = verde Punto (chart-1), segundo segment = chart-3
          // (verde oscuro) — paleta monocromática como en Sleep Report.
          { name: leftLabel, value: left, color: "var(--chart-1)" },
          { name: rightLabel, value: right, color: "var(--chart-3)" },
        ]
      : [{ name: "Sin datos", value: 1, color: "var(--muted)" }]

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          {isSaleType ? <Wallet className="size-4 text-muted-foreground" /> : <CreditCard className="size-4 text-muted-foreground" />}
          {title}
        </CardTitle>
      </CardHeader>
      <CardContent className="flex items-center gap-4">
        <div className="relative size-32 shrink-0">
          {isLoading ? (
            <Skeleton className="size-full rounded-full" />
          ) : (
            <>
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={pieData}
                    dataKey="value"
                    cx="50%"
                    cy="50%"
                    innerRadius="62%"
                    outerRadius="100%"
                    paddingAngle={total > 0 ? 2 : 0}
                    strokeWidth={0}
                  >
                    {pieData.map((entry, i) => (
                      <Cell key={i} fill={entry.color} />
                    ))}
                  </Pie>
                  {total > 0 && <Tooltip content={<DonutTooltip bootstrap={bootstrap} />} />}
                </PieChart>
              </ResponsiveContainer>
              <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-base font-bold tabular-nums">{totalCount}</span>
                <span className="text-[9px] uppercase tracking-wide text-muted-foreground">
                  ventas
                </span>
              </div>
            </>
          )}
        </div>
        <div className="flex flex-1 flex-col gap-2">
          <SplitRow
            dot="bg-primary"
            label={leftLabel}
            amount={isLoading ? null : formatMoney(left, bootstrap)}
            count={isLoading ? null : formatInt(leftCount, bootstrap)}
          />
          <SplitRow
            dot="bg-muted-foreground/40"
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
  dot,
  label,
  amount,
  count,
}: {
  dot: string
  label: string
  amount: string | null
  count: string | null
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <div className="flex items-center gap-2 text-[10px] uppercase tracking-wide text-muted-foreground">
        <span className={cn("size-2 rounded-full", dot)} />
        {label}
      </div>
      {amount === null ? (
        <Skeleton className="h-6 w-20" />
      ) : (
        <>
          <span className="text-base font-semibold tabular-nums">{amount}</span>
          <span className="text-[10px] text-muted-foreground tabular-nums">{count} ventas</span>
        </>
      )}
    </div>
  )
}

function DonutTooltip({
  active,
  payload,
  bootstrap,
}: {
  active?: boolean
  payload?: Array<{ name?: string; value?: number; payload?: { name?: string } }>
  bootstrap?: ReturnType<typeof useBootstrap>["data"]
}) {
  if (!active || !payload || payload.length === 0) return null
  const p = payload[0]
  return (
    <div className="rounded-md border bg-popover px-2 py-1 text-xs shadow">
      <div className="font-medium">{p.payload?.name ?? p.name}</div>
      <div className="tabular-nums text-muted-foreground">
        {formatMoney(p.value ?? 0, bootstrap)}
      </div>
    </div>
  )
}

// ── Clientes ──────────────────────────────────────────────────────────────

function CustomersCard({
  data,
  isLoading,
}: {
  data: CustomersWidget | undefined
  isLoading: boolean
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <UsersIcon className="size-4 text-muted-foreground" />
          Clientes
        </CardTitle>
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
            icon={<TrendingUp className="size-3.5 text-emerald-500" />}
          />
          <Stat
            label="Recurrentes"
            value={isLoading ? null : formatInt(data?.old, undefined)}
            icon={<TrendingDown className="size-3.5 text-amber-500" />}
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <div className="flex items-center justify-between text-xs">
            <span className="text-muted-foreground">Tasa de retorno</span>
            <span className="font-medium tabular-nums">
              {isLoading ? "…" : `${data?.returnRate ?? 0}%`}
            </span>
          </div>
          <Progress value={Math.min(100, Math.max(0, data?.returnRate ?? 0))} className="h-1.5" />
        </div>
      </CardContent>
    </Card>
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
      value: loading
        ? null
        : `${bootstrap?.currency ?? ""} ${fmtMoney(stats?.customerAverage, bootstrap, false)}`,
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
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <Receipt className="size-4 text-muted-foreground" />
          Información general
        </CardTitle>
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
    <Card>
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

/**
 * Horarios Pico — bar chart vertical mostrando hasta 6 horas top.
 * Las etiquetas del backend vienen como "14:00 Ventas" → recortamos a "14:00"
 * para el eje X. Se muestra empty state cuando no hay datos.
 */
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
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <TrendingUp className="size-4 text-muted-foreground" />
          Horarios pico
        </CardTitle>
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
          <ResponsiveContainer width="100%" height={200}>
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
              <Tooltip
                cursor={{ fill: "var(--accent)", opacity: 0.5 }}
                contentStyle={{
                  background: "var(--popover)",
                  border: "1px solid var(--border)",
                  borderRadius: 8,
                  fontSize: 12,
                }}
                labelStyle={{ color: "var(--foreground)" }}
              />
              <Bar dataKey="total" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
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
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <PackageCheck className="size-4 text-muted-foreground" />
          Top 5 Artículos
        </CardTitle>
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
            icon={PackageCheck}
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

// ── Top 10 Categorías ────────────────────────────────────────────────────

function TopCategoriesCard({
  data,
  isLoading,
}: {
  data: TopTaxonomyRow[]
  isLoading: boolean
}) {
  const max = data.reduce((m, r) => Math.max(m, r.total), 0)
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <Layers className="size-4 text-muted-foreground" />
          Top 10 Categorías
        </CardTitle>
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
            icon={Layers}
            title="Sin ventas en este período"
            showMarquee={false}
            className="border-0 py-6"
          />
        ) : (
          <div className="flex flex-col gap-2">
            {data.map((row, i) => (
              <div key={`${row.title}-${i}`} className="flex flex-col gap-1">
                <div className="flex items-center justify-between text-xs">
                  <span className="font-medium">{row.title}</span>
                  <span className="tabular-nums text-muted-foreground">
                    {row.total.toFixed(0)}
                  </span>
                </div>
                <Progress
                  value={max > 0 ? (row.total / max) * 100 : 0}
                  className="h-1"
                />
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

// ── Pequeños helpers ─────────────────────────────────────────────────────

function Stat({
  label,
  value,
  icon,
}: {
  label: string
  value: string | null
  icon?: React.ReactNode
}) {
  return (
    <div className="flex flex-col gap-1">
      <span className="flex items-center gap-1.5 text-[10px] uppercase tracking-wide text-muted-foreground">
        {icon}
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

function PlanStat({
  label,
  value,
  max,
  bootstrap,
}: {
  label: string
  value: string | null
  max?: number
  bootstrap?: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <div className="flex flex-col gap-1 rounded-md border bg-muted/30 p-3">
      <span className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</span>
      {value === null ? (
        <Skeleton className="h-6 w-16" />
      ) : (
        <>
          <span className="text-base font-semibold tabular-nums">{value}</span>
          {max ? (
            <span className="text-[10px] text-muted-foreground tabular-nums">
              / {formatInt(max, bootstrap)} permitidos
            </span>
          ) : null}
        </>
      )}
    </div>
  )
}

function ModuleOffCard({ title }: { title: string }) {
  return (
    <Card className="opacity-60">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
          <Gift className="size-4" />
          {title}
        </CardTitle>
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
