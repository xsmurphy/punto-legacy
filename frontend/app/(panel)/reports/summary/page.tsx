"use client"

/**
 * Reporte Resumen de Ventas — paridad con panel/reports/summary.html.
 *
 * Layout (top → bottom):
 *  1. Header: DateRangePicker
 *  2. Chart comparativo: bars Ingreso Actual / Ingreso Anterior / Egresos + line Margen
 *  3. KPI row: 4 cards (Total Bruto / Devoluciones / Descuentos / Total Neto) con delta %
 *  4. Charts secundarios: Día de la semana + Ventas por Hora
 *  5. Tabs Resumen / Por Día:
 *     - Resumen: grid 2x2 (VENTAS / MEDIOS DE PAGO / TIPOS / GIFT CARDS)
 *     - Por Día: DataTable con bucket diario
 *
 * Backends consumidos:
 *  - /v1/reports/sales?dataset=summary  → totals, returns, byType, giftcards, payments, nonAddingToSales
 *  - /v1/reports/sales?dataset=hours    → ventas por hora
 *  - /v1/reports/sales?dataset=byday    → buckets diarios
 *  - BFF /api/dashboard/income-chart    → series con ingresos+egresos+margen, llamado 2 veces
 *    (rango actual y rango shifted hacia atrás del mismo length) para comparativa.
 */

import * as React from "react"
import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Line,
  LineChart,
  BarChart,
  ReferenceLine,
  XAxis,
  YAxis,
} from "recharts"
import { AlertCircle, ArrowDownRight, ArrowUpRight } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { DataTable } from "@/components/data-table/data-table"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type SalesSummaryResponse,
} from "@/hooks/use-reports"
import { useIncomeChart } from "@/hooks/use-dashboard-widget"
import { formatInt, formatMoney } from "@/lib/format"
import { cn } from "@/lib/utils"

// ── Tipos locales ────────────────────────────────────────────────────────────

interface ByDayRow {
  date: string
  usold: number
  count: number
  discount: number
  tax: number
  total: number
}

interface HoursRow {
  /** Hora del día 0-23 (mismo nombre de bucket que el resto de los datasets). */
  bucket: number
  /** Cantidad de ventas en esa hora — lo que grafica "Ventas por Hora". */
  count: number
  /** Monto vendido en esa hora (hoy no se grafica). */
  total: number
  units?: number
}

// ── Helpers de rango "período anterior" ─────────────────────────────────────

/** Formatea Date → 'YYYY-MM-DD HH:mm:ss' (mismo formato que rangeToBackend). */
function toBackendFormat(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0")
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ` +
    `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
  )
}

/**
 * Dado un rango "actual" como strings backend, calcula el rango anterior
 * (mismo length, termina 1 segundo antes del 'from' actual).
 */
function shiftRangeBackwards(from: string, to: string): { from: string; to: string } {
  // 'YYYY-MM-DD HH:mm:ss' es compatible con `new Date` en navegador y server.
  const f = new Date(from.replace(" ", "T"))
  const t = new Date(to.replace(" ", "T"))
  const lenMs = t.getTime() - f.getTime()
  const prevTo = new Date(f.getTime() - 1000)
  const prevFrom = new Date(prevTo.getTime() - lenMs)
  return {
    from: toBackendFormat(prevFrom),
    to: toBackendFormat(prevTo),
  }
}

// ── Page ────────────────────────────────────────────────────────────────────

export default function SummaryReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const current = React.useMemo(() => rangeToBackend(range), [range])
  const previous = React.useMemo(
    () => shiftRangeBackwards(current.from, current.to),
    [current],
  )

  // Summary actual + anterior (para deltas % de los KPIs).
  const summary = useReport<SalesSummaryResponse>("sales", {
    from: current.from,
    to: current.to,
    params: { dataset: "summary" },
  })
  const summaryPrev = useReport<SalesSummaryResponse>("sales", {
    from: previous.from,
    to: previous.to,
    params: { dataset: "summary" },
  })

  // Chart comparativo (BFF) — actual y anterior.
  const incomeCurr = useIncomeChart(current)
  const incomePrev = useIncomeChart(previous)

  // Datasets para los charts secundarios + tab "Por Día".
  const hours = useReport<HoursRow[]>("sales", {
    from: current.from,
    to: current.to,
    params: { dataset: "hours" },
  })
  const byDay = useReport<ByDayRow[]>("sales", {
    from: current.from,
    to: current.to,
    params: { dataset: "byday" },
  })

  // KPIs derivados (actual y anterior).
  const totalCurr = num(summary.data?.totals?.total)
  const taxCurr = num(summary.data?.totals?.tax)
  const discountCurr = num(summary.data?.totals?.discount)
  const returnsCurr = num(summary.data?.returns?.total)
  // `totals.total` es SUM(transactionTotal) y transactionTotal se persiste
  // BRUTO: SaleService lo escribe desde `subtotal`, que en create-sale.ts es
  // la suma de `allocations[].gross` (qty × unitPrice, antes del descuento).
  // El neto por lo tanto resta descuentos ADEMÁS de devoluciones — sin eso
  // las ventas netas quedaban infladas por todo el descuento del período.
  const netCurr = totalCurr - returnsCurr - discountCurr

  const totalPrev = num(summaryPrev.data?.totals?.total)
  const discountPrev = num(summaryPrev.data?.totals?.discount)
  const returnsPrev = num(summaryPrev.data?.returns?.total)
  const netPrev = totalPrev - returnsPrev - discountPrev

  // ── Chart comparativo: combinar las dos series en un solo dataset ────────
  const composedData = React.useMemo(() => {
    const curr = incomeCurr.data?.data ?? []
    const prev = incomePrev.data?.data ?? []
    return curr.map((p, i) => ({
      bucket: p.bucket,
      ingresoActual: p.ingresos,
      ingresoAnterior: prev[i]?.ingresos ?? 0,
      egresos: p.egresos,
      margen: p.margen,
    }))
  }, [incomeCurr.data, incomePrev.data])

  // ── Día de la semana (agg client-side desde byday) ───────────────────────
  const weekdayData = React.useMemo(() => {
    const labels = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"]
    const totals: number[] = [0, 0, 0, 0, 0, 0, 0]
    for (const r of byDay.data ?? []) {
      const d = new Date(r.date)
      if (!Number.isFinite(d.getTime())) continue
      const wd = (d.getDay() + 6) % 7 // Lun=0 … Dom=6
      totals[wd] += num(r.total)
    }
    return labels.map((label, i) => ({ day: label, total: totals[i] }))
  }, [byDay.data])

  // ── Ventas por hora ──────────────────────────────────────────────────────
  const hoursData = React.useMemo(() => {
    const map = new Map<number, number>()
    for (const r of hours.data ?? []) map.set(Number(r.bucket), num(r.count))
    return Array.from({ length: 24 }, (_, h) => ({
      hour: String(h).padStart(2, "0") + "h",
      count: map.get(h) ?? 0,
    }))
  }, [hours.data])

  // ── Render ───────────────────────────────────────────────────────────────

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Resumen</h1>
          <p className="text-sm text-muted-foreground">
            Vista panorámica del período con comparativa al período anterior.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {summary.error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el resumen</p>
            <p className="text-xs text-muted-foreground">{summary.error.message}</p>
          </div>
        </div>
      )}

      {/* 1. Chart comparativo principal */}
      <ComparativeChart
        data={composedData}
        isDay={incomeCurr.data?.isDay ?? false}
        average={incomeCurr.data?.totals.average ?? 0}
        isLoading={incomeCurr.isLoading || incomePrev.isLoading}
        error={incomeCurr.error}
        bootstrap={bootstrap}
      />

      {/* 2. KPI row */}
      <section className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard
          label="Total (Bruto)"
          value={formatMoney(totalCurr, bootstrap)}
          delta={pctDelta(totalCurr, totalPrev)}
          trend="up"
          isLoading={summary.isLoading}
        />
        <KpiCard
          label="Devoluciones"
          value={formatMoney(returnsCurr, bootstrap)}
          delta={pctDelta(returnsCurr, returnsPrev)}
          trend="down"
          negative={returnsCurr > 0}
          isLoading={summary.isLoading}
        />
        <KpiCard
          label="Descuentos"
          value={formatMoney(discountCurr, bootstrap)}
          delta={pctDelta(discountCurr, discountPrev)}
          trend="neutral"
          isLoading={summary.isLoading}
        />
        <KpiCard
          label="Total (Neto)"
          value={formatMoney(netCurr, bootstrap)}
          delta={pctDelta(netCurr, netPrev)}
          trend="up"
          isLoading={summary.isLoading}
        />
      </section>

      {/* 3. Charts secundarios */}
      <section className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <WeekdayChart
          data={weekdayData}
          isLoading={byDay.isLoading}
          bootstrap={bootstrap}
        />
        <HoursChart data={hoursData} isLoading={hours.isLoading} />
      </section>

      {/* 4. Tabs Resumen / Por Día */}
      <Tabs defaultValue="resumen" className="flex flex-col gap-4">
        <TabsList>
          <TabsTrigger value="resumen">Resumen</TabsTrigger>
          <TabsTrigger value="byday">Por Día</TabsTrigger>
        </TabsList>

        <TabsContent value="resumen" className="m-0">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <SalesBreakdownCard
              data={summary.data}
              netCurr={netCurr}
              tax={taxCurr}
              isLoading={summary.isLoading}
              bootstrap={bootstrap}
            />
            <PaymentsBreakdownCard
              payments={summary.data?.payments ?? []}
              isLoading={summary.isLoading}
              bootstrap={bootstrap}
            />
            <TypesBreakdownCard
              raw={summary.data?.byType}
              isLoading={summary.isLoading}
              bootstrap={bootstrap}
            />
            <GiftCardsBreakdownCard
              raw={summary.data?.giftcards}
              nonAddingGiftCards={num(summary.data?.nonAddingToSales?.totalGiftCards)}
              isLoading={summary.isLoading}
              bootstrap={bootstrap}
            />
          </div>
        </TabsContent>

        <TabsContent value="byday" className="m-0">
          <ByDayTable
            rows={byDay.data ?? []}
            isLoading={byDay.isLoading}
            bootstrap={bootstrap}
          />
        </TabsContent>
      </Tabs>
    </div>
  )
}

// ── Comparative chart ──────────────────────────────────────────────────────

const comparativeChartConfig = {
  ingresoActual: { label: "Ingreso Actual", color: "var(--chart-1)" },
  ingresoAnterior: { label: "Ingreso Anterior", color: "var(--muted-foreground)" },
  egresos: { label: "Egresos", color: "var(--chart-3)" },
  margen: { label: "Margen", color: "var(--brand)" },
} satisfies ChartConfig

function ComparativeChart({
  data,
  isDay,
  average,
  isLoading,
  error,
  bootstrap,
}: {
  data: Array<{
    bucket: string
    ingresoActual: number
    ingresoAnterior: number
    egresos: number
    margen: number
  }>
  isDay: boolean
  average: number
  isLoading: boolean
  error: Error | null
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  if (isLoading) {
    return (
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm font-medium">Margen, Ingresos y Egresos</CardTitle>
        </CardHeader>
        <CardContent>
          <Skeleton className="h-[280px] w-full" />
        </CardContent>
      </Card>
    )
  }

  if (error || data.length === 0) {
    return (
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm font-medium">Margen, Ingresos y Egresos</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex h-[280px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
            {error?.message ?? "Sin datos para el período seleccionado."}
          </div>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center justify-between text-sm font-medium">
          <span>Margen, Ingresos y Egresos</span>
          <span className="text-xs font-normal text-muted-foreground">
            Promedio: {formatMoney(average, bootstrap)}
          </span>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <ChartContainer config={comparativeChartConfig} className="h-[280px] w-full">
          <ComposedChart data={data} margin={{ top: 10, right: 12, left: -10, bottom: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
            <XAxis
              dataKey="bucket"
              tickFormatter={(v: string) => formatBucketLabel(v, isDay)}
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
                  labelFormatter={(label) => formatBucketLabel(String(label), isDay)}
                  formatter={(value, name) => (
                    <div className="flex w-full items-center justify-between gap-3">
                      <span className="text-muted-foreground">
                        {comparativeChartConfig[name as keyof typeof comparativeChartConfig]
                          ?.label ?? name}
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
            <ReferenceLine
              y={average}
              stroke="var(--muted-foreground)"
              strokeDasharray="4 4"
              strokeWidth={1}
            />
            <Bar
              dataKey="ingresoActual"
              fill="var(--color-ingresoActual)"
              radius={[4, 4, 0, 0]}
              maxBarSize={28}
            />
            <Bar
              dataKey="ingresoAnterior"
              fill="var(--color-ingresoAnterior)"
              radius={[4, 4, 0, 0]}
              maxBarSize={28}
              fillOpacity={0.6}
            />
            <Bar
              dataKey="egresos"
              fill="var(--color-egresos)"
              radius={[4, 4, 0, 0]}
              maxBarSize={28}
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
      </CardContent>
    </Card>
  )
}

// ── KPI Cards ───────────────────────────────────────────────────────────────

function KpiCard({
  label,
  value,
  delta,
  trend,
  negative,
  isLoading,
}: {
  label: string
  value: string
  delta: number | null
  trend: "up" | "down" | "neutral"
  negative?: boolean
  isLoading: boolean
}) {
  const TrendIcon = trend === "up" ? ArrowUpRight : trend === "down" ? ArrowDownRight : null
  const trendColor =
    negative
      ? "text-destructive"
      : trend === "up"
        ? "text-[var(--chart-1)]"
        : "text-muted-foreground"

  return (
    <Card>
      <CardContent className="flex flex-col gap-2">
        <div className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
          {TrendIcon && <TrendIcon className={cn("size-3.5 shrink-0", trendColor)} />}
          <span className="truncate">{label}</span>
        </div>
        {isLoading ? (
          <Skeleton className="h-9 w-32" />
        ) : (
          <span className="text-3xl font-bold tabular-nums tracking-tight">{value}</span>
        )}
        {isLoading ? (
          <Skeleton className="h-3 w-20" />
        ) : (
          <DeltaBadge delta={delta} positiveIsGood={trend === "up"} />
        )}
      </CardContent>
    </Card>
  )
}

function DeltaBadge({
  delta,
  positiveIsGood,
}: {
  delta: number | null
  positiveIsGood: boolean
}) {
  if (delta === null) {
    return <span className="text-xs text-muted-foreground">— vs período anterior</span>
  }
  const isPositive = delta >= 0
  const good = positiveIsGood ? isPositive : !isPositive
  const color = good ? "text-[var(--chart-1)]" : "text-destructive"
  const sign = isPositive ? "+" : ""
  return (
    <span className={cn("text-xs tabular-nums", color)}>
      {sign}
      {delta.toFixed(1)}% vs período anterior
    </span>
  )
}

function pctDelta(curr: number, prev: number): number | null {
  if (prev === 0) {
    if (curr === 0) return 0
    return null
  }
  return ((curr - prev) / Math.abs(prev)) * 100
}

// ── Weekday chart ──────────────────────────────────────────────────────────

const weekdayChartConfig = {
  total: { label: "Total", color: "var(--chart-1)" },
} satisfies ChartConfig

function WeekdayChart({
  data,
  isLoading,
  bootstrap,
}: {
  data: Array<{ day: string; total: number }>
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium">Día de la semana</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-[220px] w-full" />
        ) : (
          <ChartContainer config={weekdayChartConfig} className="h-[220px] w-full">
            <BarChart data={data} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
              <XAxis
                dataKey="day"
                tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                tick={{ fontSize: 10, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
                tickFormatter={(v: number) => compactNumber(v)}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={
                  <ChartTooltipContent
                    formatter={(value) => (
                      <span className="font-medium tabular-nums">
                        {formatMoney(Number(value) || 0, bootstrap)}
                      </span>
                    )}
                  />
                }
              />
              <Bar dataKey="total" fill="var(--color-total)" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ChartContainer>
        )}
      </CardContent>
    </Card>
  )
}

// ── Hours chart ────────────────────────────────────────────────────────────

const hoursChartConfig = {
  count: { label: "Ventas", color: "var(--chart-1)" },
} satisfies ChartConfig

function HoursChart({
  data,
  isLoading,
}: {
  data: Array<{ hour: string; count: number }>
  isLoading: boolean
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium">Ventas por Hora</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-[220px] w-full" />
        ) : (
          <ChartContainer config={hoursChartConfig} className="h-[220px] w-full">
            <LineChart data={data} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
              <XAxis
                dataKey="hour"
                tick={{ fontSize: 10, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
                interval={2}
              />
              <YAxis
                allowDecimals={false}
                tick={{ fontSize: 10, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
                width={28}
              />
              <ChartTooltip
                cursor={{ stroke: "var(--accent)", strokeWidth: 1 }}
                content={<ChartTooltipContent />}
              />
              <Line
                type="monotone"
                dataKey="count"
                stroke="var(--color-count)"
                strokeWidth={2}
                dot={false}
                activeDot={{ r: 4 }}
              />
            </LineChart>
          </ChartContainer>
        )}
      </CardContent>
    </Card>
  )
}

// ── Breakdown cards (Resumen tab) ──────────────────────────────────────────

function CardHeaderWithExcel({ title }: { title: string }) {
  return (
    <CardHeader className="flex flex-row items-center justify-between gap-2 pb-2">
      <CardTitle className="text-sm font-medium uppercase tracking-wide">
        {title}
      </CardTitle>
      {/* TODO: implementar export por sección */}
      <Button variant="outline" size="sm" disabled>
        Excel
      </Button>
    </CardHeader>
  )
}

function BreakRow({
  left,
  right,
  highlight,
  muted,
  negative,
}: {
  left: React.ReactNode
  right: React.ReactNode
  highlight?: boolean
  muted?: boolean
  negative?: boolean
}) {
  return (
    <div
      className={cn(
        "flex items-center justify-between gap-2 px-3 py-2 text-sm",
        highlight && "rounded-md bg-muted/50 font-semibold",
        muted && "text-muted-foreground",
      )}
    >
      <span className={cn("truncate", muted && "text-xs")}>{left}</span>
      <span
        className={cn(
          "tabular-nums font-medium",
          muted && "text-xs",
          negative && "text-destructive",
        )}
      >
        {right}
      </span>
    </div>
  )
}

function SalesBreakdownCard({
  data,
  netCurr,
  tax,
  isLoading,
  bootstrap,
}: {
  data: SalesSummaryResponse | undefined
  netCurr: number
  tax: number
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const total = num(data?.totals?.total)
  const discount = num(data?.totals?.discount)
  const returns = num(data?.returns?.total)
  // `total` YA es el bruto (SUM de transactionTotal, que se persiste antes de
  // descuento — ver netCurr arriba). Sumarle el descuento lo contaba dos veces
  // y las Ventas Brutas salían infladas (reporte del tester 2026-08-04).
  const grossSales = total

  // Pagos con créditos (storeCredit) — opcional según shape de payments.
  const creditPayment = (data?.payments ?? []).find(
    (p) => p.type === "storeCredit" || /credit/i.test(p.type),
  )
  const creditAbsorbed = num(creditPayment?.price)

  return (
    <Card>
      <CardHeaderWithExcel title="Ventas" />
      <CardContent className="flex flex-col gap-1">
        {isLoading ? (
          <SkeletonRows count={6} />
        ) : (
          <>
            <BreakRow
              left="Ventas Brutas"
              right={formatMoney(grossSales, bootstrap)}
              highlight
            />
            {creditAbsorbed > 0 && (
              <BreakRow
                left="Pagos con créditos"
                right={`− ${formatMoney(creditAbsorbed, bootstrap)}`}
                negative
              />
            )}
            <BreakRow
              left="Devoluciones"
              right={`− ${formatMoney(returns, bootstrap)}`}
              negative={returns > 0}
            />
            <BreakRow
              left="Descuentos"
              right={`− ${formatMoney(discount, bootstrap)}`}
              negative={discount > 0}
            />
            <BreakRow
              left="Ventas Netas"
              right={formatMoney(netCurr, bootstrap)}
              highlight
            />
            <BreakRow left="IVA" right={formatMoney(tax, bootstrap)} muted />
          </>
        )}
      </CardContent>
    </Card>
  )
}

function PaymentsBreakdownCard({
  payments,
  isLoading,
  bootstrap,
}: {
  payments: Array<{ type: string; name: string; price: number }>
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const total = payments.reduce((s, p) => s + num(p.price), 0)
  return (
    <Card>
      <CardHeaderWithExcel title="Medios de Pago" />
      <CardContent className="flex flex-col gap-1">
        {isLoading ? (
          <SkeletonRows count={4} />
        ) : payments.length === 0 ? (
          <p className="px-3 py-2 text-xs text-muted-foreground">Sin cobros registrados.</p>
        ) : (
          <>
            {payments.map((p, i) => (
              <BreakRow
                key={`${p.type}-${i}`}
                left={p.name || p.type}
                right={formatMoney(num(p.price), bootstrap)}
              />
            ))}
            <BreakRow
              left="TOTAL"
              right={formatMoney(total, bootstrap)}
              highlight
            />
          </>
        )}
      </CardContent>
    </Card>
  )
}

function TypesBreakdownCard({
  raw,
  isLoading,
  bootstrap,
}: {
  raw: SalesSummaryResponse["byType"] | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  // El backend devuelve `byType` como objeto {cash:{total}, credit:{total}}; el
  // type del hook indica array — normalizamos a un par de números.
  const { cash, credit } = React.useMemo(() => {
    let cash = 0
    let credit = 0
    const r = raw as unknown
    if (Array.isArray(r)) {
      for (const it of r) {
        const t = it.type
        if (t === "cash") cash += num(it.total)
        else if (t === "credit") credit += num(it.total)
      }
    } else if (r && typeof r === "object") {
      const o = r as Record<string, { total?: number }>
      cash = num(o.cash?.total)
      credit = num(o.credit?.total)
    }
    return { cash, credit }
  }, [raw])

  const total = cash + credit

  return (
    <Card>
      <CardHeaderWithExcel title="Tipos" />
      <CardContent className="flex flex-col gap-1">
        {isLoading ? (
          <SkeletonRows count={3} />
        ) : (
          <>
            <BreakRow
              left="Ventas al Contado"
              right={formatMoney(cash, bootstrap)}
            />
            <BreakRow
              left="Ventas a Crédito"
              right={formatMoney(credit, bootstrap)}
            />
            <BreakRow
              left="TOTAL BRUTO"
              right={formatMoney(total, bootstrap)}
              highlight
            />
          </>
        )}
      </CardContent>
    </Card>
  )
}

function GiftCardsBreakdownCard({
  raw,
  nonAddingGiftCards,
  isLoading,
  bootstrap,
}: {
  raw: SalesSummaryResponse["giftcards"] | undefined
  nonAddingGiftCards: number
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const { sold, count } = React.useMemo(() => {
    let sold = 0
    let count = 0
    const r = raw as unknown
    if (Array.isArray(r)) {
      for (const it of r) {
        sold += num(it.total)
        count += num(it.count)
      }
    } else if (r && typeof r === "object") {
      const o = r as { total?: number; count?: number }
      sold = num(o.total)
      count = num(o.count)
    }
    return { sold, count }
  }, [raw])

  return (
    <Card>
      <CardHeaderWithExcel title="Gift Cards" />
      <CardContent className="flex flex-col gap-1">
        {isLoading ? (
          <SkeletonRows count={3} />
        ) : (
          <>
            <BreakRow left="Vendido" right={formatMoney(sold, bootstrap)} />
            <BreakRow left="Cantidad" right={String(count)} />
            <BreakRow
              left="Canjeado"
              right={formatMoney(nonAddingGiftCards, bootstrap)}
              muted
            />
          </>
        )}
      </CardContent>
    </Card>
  )
}

// ── Tab "Por Día" (DataTable) ───────────────────────────────────────────────

function ByDayTable({
  rows,
  isLoading,
  bootstrap,
}: {
  rows: ByDayRow[]
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const columns = React.useMemo<ColumnDef<ByDayRow, unknown>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ row }) => {
          const d = String(row.original.date)
          if (/^\d{4}-\d{2}-\d{2}/.test(d)) {
            return `${d.slice(8, 10)}/${d.slice(5, 7)}`
          }
          return d
        },
        sortingFn: (a, b) =>
          String(a.original.date).localeCompare(String(b.original.date)),
      },
      {
        accessorKey: "count",
        header: "Operaciones",
        cell: ({ row }) => (
          <span className="tabular-nums">{formatInt(row.original.count, bootstrap)}</span>
        ),
      },
      {
        accessorKey: "usold",
        header: "Unidades",
        cell: ({ row }) => (
          <span className="tabular-nums">{formatInt(row.original.usold, bootstrap)}</span>
        ),
      },
      {
        accessorKey: "discount",
        header: "Descuento",
        cell: ({ row }) => (
          <span className="tabular-nums">{formatMoney(row.original.discount, bootstrap)}</span>
        ),
      },
      {
        accessorKey: "tax",
        header: "IVA",
        cell: ({ row }) => (
          <span className="tabular-nums">{formatMoney(row.original.tax, bootstrap)}</span>
        ),
      },
      {
        accessorKey: "total",
        header: "Total",
        cell: ({ row }) => (
          <span className="font-medium tabular-nums">
            {formatMoney(row.original.total, bootstrap)}
          </span>
        ),
      },
    ],
    [bootstrap],
  )

  return (
    <DataTable<ByDayRow>
      tableId="summary-byday"
      data={rows}
      columns={columns}
      isLoading={isLoading}
      exportFileName="resumen-por-dia"
      getRowId={(r) => r.date}
      emptyMessage="Sin ventas en el período."
    />
  )
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function SkeletonRows({ count = 3 }: { count?: number }) {
  return (
    <div className="flex flex-col gap-2 px-3 py-2">
      {Array.from({ length: count }).map((_, i) => (
        <Skeleton key={i} className="h-5 w-full" />
      ))}
    </div>
  )
}

function num(v: unknown): number {
  if (typeof v === "number" && Number.isFinite(v)) return v
  if (typeof v === "string") {
    const n = Number(v)
    return Number.isFinite(n) ? n : 0
  }
  return 0
}

function formatBucketLabel(b: string, isDay: boolean): string {
  if (isDay) return String(b).padStart(2, "0") + "h"
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
