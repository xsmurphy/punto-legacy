"use client"

import * as React from "react"
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Line,
  LineChart,
  Pie,
  PieChart,
  XAxis,
  YAxis,
} from "recharts"
import { AlertCircle } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatMoney, formatInt } from "@/lib/format"
import { chartSpecSchema, type ChartSpec } from "@/lib/agent/chart-spec"

/**
 * Render de la tool `render_chart` del agente (generative UI). El `input`
 * viene de un LLM — SIEMPRE re-validado acá con el mismo schema del server
 * (chart-spec.ts) antes de tocar recharts. Input inválido → card de error,
 * nunca un crash de render.
 */

const CHART_COLORS = [
  "var(--chart-1)",
  "var(--chart-2)",
  "var(--chart-3)",
  "var(--chart-4)",
] as const

function compactNumber(v: number): string {
  if (Math.abs(v) >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (Math.abs(v) >= 1_000) return `${(v / 1_000).toFixed(0)}k`
  return String(Math.round(v))
}

export function AgentChartSkeleton() {
  return (
    <Card variant="soft" size="sm" className="w-full max-w-[95%] min-w-0 overflow-hidden">
      <CardHeader>
        <Skeleton className="h-4 w-32" />
      </CardHeader>
      <CardContent>
        <Skeleton className="h-[220px] w-full" />
      </CardContent>
    </Card>
  )
}

function ChartError({ message }: { message: string }) {
  return (
    <Card size="sm" className="w-full max-w-[95%] border-destructive/30">
      <CardContent className="flex items-start gap-2 text-sm text-destructive">
        <AlertCircle className="mt-0.5 size-4 shrink-0" />
        <span>{message}</span>
      </CardContent>
    </Card>
  )
}

export function AgentChart({ input }: { input: unknown }) {
  const bootstrap = useBootstrap().data

  const parsed = chartSpecSchema.safeParse(input)
  if (!parsed.success) {
    return <ChartError message="No se pudo generar el gráfico." />
  }
  const spec = parsed.data

  // Filas cuyo valor no sea number para TODAS las series se descartan — un
  // valor faltante/string en una serie rompe el trazo de recharts.
  const seriesKeys = spec.series.map((s) => s.key)
  const rows = spec.data.filter((row) =>
    seriesKeys.every((k) => typeof row[k] === "number" && Number.isFinite(row[k] as number))
  )

  if (rows.length === 0) {
    return (
      <Card variant="soft" size="sm" className="w-full max-w-[95%] min-w-0 overflow-hidden">
        <CardHeader>
          <CardTitle className="text-sm font-medium">{spec.title}</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex h-[220px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
            Sin datos para graficar.
          </div>
        </CardContent>
      </Card>
    )
  }

  const format = spec.valueFormat ?? "number"
  const formatValue = (v: number): string => {
    if (format === "money") return formatMoney(v, bootstrap)
    if (format === "percent") return `${Number.isInteger(v) ? v : v.toFixed(1)}%`
    return formatInt(v, bootstrap)
  }
  const formatAxisValue = (v: number): string => {
    if (format === "percent") return `${v}%`
    return compactNumber(v)
  }

  const chartConfig = spec.series.reduce((acc, s, i) => {
    acc[s.key] = { label: s.label, color: CHART_COLORS[i % CHART_COLORS.length] }
    return acc
  }, {} as ChartConfig)

  return (
    <Card variant="soft" size="sm" className="w-full max-w-[95%] min-w-0 overflow-hidden">
      <CardHeader>
        <CardTitle className="text-sm font-medium">{spec.title}</CardTitle>
      </CardHeader>
      <CardContent className="min-w-0 overflow-hidden">
        <ChartContainer config={chartConfig} className="h-[220px] w-full">
          {spec.kind === "donut" ? (
            <DonutChart spec={spec} rows={rows} formatValue={formatValue} />
          ) : spec.kind === "bar" ? (
            <BarChart data={rows} margin={{ top: 10, right: 8, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey={spec.xKey}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickFormatter={formatAxisValue}
                tickLine={false}
                axisLine={false}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={<ChartTooltipContent formatter={(v, name) => tooltipRow(chartConfig, name, v, formatValue)} />}
              />
              {spec.series.length > 1 && <ChartLegend content={<ChartLegendContent />} />}
              {spec.series.map((s) => (
                <Bar key={s.key} dataKey={s.key} fill={`var(--color-${s.key})`} radius={[4, 4, 0, 0]} maxBarSize={32} />
              ))}
            </BarChart>
          ) : spec.kind === "area" ? (
            <AreaChart data={rows} margin={{ top: 10, right: 8, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey={spec.xKey}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickFormatter={formatAxisValue}
                tickLine={false}
                axisLine={false}
              />
              <ChartTooltip
                cursor={{ stroke: "var(--muted-foreground)", opacity: 0.3 }}
                content={<ChartTooltipContent formatter={(v, name) => tooltipRow(chartConfig, name, v, formatValue)} />}
              />
              {spec.series.length > 1 && <ChartLegend content={<ChartLegendContent />} />}
              {spec.series.map((s) => (
                <Area
                  key={s.key}
                  type="monotone"
                  dataKey={s.key}
                  stroke={`var(--color-${s.key})`}
                  fill={`var(--color-${s.key})`}
                  fillOpacity={0.2}
                  strokeWidth={2}
                />
              ))}
            </AreaChart>
          ) : (
            <LineChart data={rows} margin={{ top: 10, right: 8, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey={spec.xKey}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickFormatter={formatAxisValue}
                tickLine={false}
                axisLine={false}
              />
              <ChartTooltip
                cursor={{ stroke: "var(--muted-foreground)", opacity: 0.3 }}
                content={<ChartTooltipContent formatter={(v, name) => tooltipRow(chartConfig, name, v, formatValue)} />}
              />
              {spec.series.length > 1 && <ChartLegend content={<ChartLegendContent />} />}
              {spec.series.map((s) => (
                <Line
                  key={s.key}
                  type="monotone"
                  dataKey={s.key}
                  stroke={`var(--color-${s.key})`}
                  strokeWidth={2}
                  dot={false}
                />
              ))}
            </LineChart>
          )}
        </ChartContainer>
      </CardContent>
    </Card>
  )
}

function tooltipRow(
  config: ChartConfig,
  name: React.ReactNode,
  value: unknown,
  formatValue: (v: number) => string,
) {
  const label = config[String(name)]?.label ?? name
  return (
    <div className="flex w-full items-center justify-between gap-3">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium tabular-nums">{formatValue(Number(value) || 0)}</span>
    </div>
  )
}

function DonutChart({
  spec,
  rows,
  formatValue,
}: {
  spec: ChartSpec
  rows: Record<string, string | number | null>[]
  formatValue: (v: number) => string
}) {
  const key = spec.series[0]?.key
  const pieData = rows.map((row, i) => ({
    name: String(row[spec.xKey] ?? ""),
    value: Number(row[key]) || 0,
    color: CHART_COLORS[i % CHART_COLORS.length],
  }))

  return (
    <PieChart>
      <Pie data={pieData} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={60} outerRadius={90} paddingAngle={2} strokeWidth={0}>
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
                <span className="font-medium tabular-nums">{formatValue(Number(value) || 0)}</span>
              </div>
            )}
          />
        }
      />
      <ChartLegend content={<ChartLegendContent nameKey="name" />} />
    </PieChart>
  )
}
