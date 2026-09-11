"use client"

/**
 * Dashboard de Equipo — cómo vendió cada persona en el período.
 *
 * Sale de `GET /v1/reports/users?view=summary`, que trae los KPIs, el ranking
 * por vendedor y la serie diaria en una sola respuesta.
 *
 * Dos cosas que la pantalla dice en voz alta porque si no se malinterpretan:
 *
 * - El **ticket promedio global** se calcula con las transacciones DISTINTAS
 *   del comercio, no sumando las de cada vendedor. Una venta con líneas de dos
 *   vendedores es un ticket de cada uno y uno solo del negocio, así que las dos
 *   columnas no cierran entre sí a propósito.
 * - La **comisión** es la que se congeló al vender, nunca la que saldría hoy
 *   del porcentaje vigente.
 */

import * as React from "react"
import { AlertCircle } from "lucide-react"
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { CompositionDonutChart } from "@/components/domain/reports/composition-donut-chart"
import { RankingBarChart } from "@/components/domain/reports/ranking-bar-chart"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type UserDailyPoint,
  type UserRankingRow,
  type UsersSummaryResponse,
} from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"

/** Verde de charts, del vendedor más grande al más chico; la cola en gris. */
const SERIES_COLORS = [
  "var(--chart-1)",
  "var(--chart-2)",
  "var(--chart-3)",
  "var(--chart-4)",
  "var(--chart-5)",
]
const REST_COLOR = "var(--border)"
const REST_KEY = "__otros"

export interface DailyStack {
  days: Array<Record<string, string | number>>
  series: Array<{ key: string; label: string; color: string }>
}

/**
 * Pivotea la serie plana `(día, vendedor, total)` a una fila por día con una
 * columna por vendedor, que es lo que come Recharts.
 *
 * Exportada y pura por el mismo motivo que `buildRankingSlices`: es donde vive
 * la aritmética del gráfico, y un chart que se dibuja vacío no tira ningún
 * error que avise.
 *
 * Solo entran los `limit` primeros del ranking; el resto se suma en una serie
 * "Otros". Con quince vendedores apilados no se distingue ninguno, y recortar
 * sin agrupar haría que la barra de un día valiera menos que las ventas de ese
 * día.
 */
export function buildDailyStack(
  daily: UserDailyPoint[],
  ranking: UserRankingRow[],
  limit = 5,
): DailyStack {
  const top = ranking.slice(0, limit)
  const topIds = new Set(top.map((r) => r.userId))
  const hasRest = ranking.length > top.length

  const byDate = new Map<string, Record<string, string | number>>()
  daily.forEach((p) => {
    let row = byDate.get(p.date)
    if (!row) {
      row = { date: p.date }
      top.forEach((t) => { row![t.userId] = 0 })
      if (hasRest) row[REST_KEY] = 0
      byDate.set(p.date, row)
    }
    const key = topIds.has(p.userId) ? p.userId : REST_KEY
    if (key === REST_KEY && !hasRest) return
    row[key] = (Number(row[key]) || 0) + p.total
  })

  const series = top.map((t, i) => ({
    key: t.userId,
    label: t.name || "(sin nombre)",
    color: SERIES_COLORS[i % SERIES_COLORS.length],
  }))
  if (hasRest) series.push({ key: REST_KEY, label: "Otros", color: REST_COLOR })

  return {
    days: [...byDate.values()].sort((a, b) => String(a.date).localeCompare(String(b.date))),
    series,
  }
}

/** "2026-03-05" → "05/03". Sin `parseNaive`: una fecha pelada se correría un día. */
function dayLabel(v: string): string {
  return /^\d{4}-\d{2}-\d{2}$/.test(v) ? `${v.slice(8)}/${v.slice(5, 7)}` : v
}

export function UsersDashboardTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "summary" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<UsersSummaryResponse>("users", opts)

  const money = React.useCallback((v: number) => formatMoney(v, bootstrap), [bootstrap])
  const units = React.useCallback((v: number) => formatInt(v, bootstrap), [bootstrap])

  const ranking = React.useMemo(() => data?.ranking ?? [], [data])
  const stack = React.useMemo(
    () => buildDailyStack(data?.daily ?? [], ranking),
    [data, ranking],
  )
  const chartConfig = React.useMemo<ChartConfig>(() => {
    const c: ChartConfig = {}
    stack.series.forEach((s) => { c[s.key] = { label: s.label, color: s.color } })
    return c
  }, [stack])

  if (error) {
    return (
      <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
        <AlertCircle className="mt-0.5 size-4 text-destructive" />
        <div>
          <p className="font-medium">No se pudo cargar el reporte</p>
          <p className="text-xs text-muted-foreground">{error.message}</p>
        </div>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="grid gap-4 lg:grid-cols-2">
        {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-[280px] w-full" />)}
      </div>
    )
  }

  const t = data?.totals

  return (
    <div className="flex flex-col gap-6">
      <StatsRow>
        <StatTile label="Ventas del equipo" value={money(t?.total ?? 0)} emphasis />
        <StatTile label="Transacciones" value={units(t?.tickets ?? 0)} />
        <StatTile label="Ticket promedio" value={money(t?.avgTicket ?? 0)} />
        <StatTile label="Comisiones" value={money(t?.comission ?? 0)} />
        <StatTile label="Descuentos" value={money(t?.discount ?? 0)} />
        <StatTile label="Unidades" value={units(t?.usold ?? 0)} />
      </StatsRow>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold tracking-tight">Quién vendió más</CardTitle>
            <CardDescription className="text-xs">
              Total vendido en el período, con la comisión resaltada dentro de la barra.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <RankingBarChart
              data={ranking.map((r) => ({
                label: r.name || "(sin nombre)",
                value: r.total,
                overlayValue: r.comission,
              }))}
              valueLabel="Ventas"
              overlay={{ label: "Comisión", restLabel: "Resto de la venta" }}
              formatValue={money}
              emptyMessage="Nadie registró ventas en este período."
            />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold tracking-tight">Participación</CardTitle>
            <CardDescription className="text-xs">
              Qué porción de lo vendido se lleva cada persona.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <CompositionDonutChart
              data={ranking.map((r) => ({ label: r.name || "(sin nombre)", value: r.total }))}
              formatValue={money}
              restLabel="Otros vendedores"
              emptyMessage="Nadie registró ventas en este período."
            />
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">Evolución diaria</CardTitle>
          <CardDescription className="text-xs">
            Ventas por día, apiladas por vendedor. Los días sin ventas no aparecen.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {stack.days.length === 0 ? (
            <p className="text-sm text-muted-foreground">Sin ventas para graficar en este período.</p>
          ) : (
            <ChartContainer config={chartConfig} className="h-[280px] w-full">
              <BarChart data={stack.days} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis
                  dataKey="date"
                  tickFormatter={dayLabel}
                  fontSize={10}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
                />
                <YAxis
                  tickFormatter={money}
                  fontSize={10}
                  width={72}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
                />
                <ChartTooltip
                  cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                  content={
                    <ChartTooltipContent
                      labelFormatter={(v) => dayLabel(String(v))}
                      formatter={(value, name) => {
                        const label = chartConfig[String(name)]?.label ?? name
                        return `${label}: ${money(Number(value) || 0)}`
                      }}
                    />
                  }
                />
                <ChartLegend content={<ChartLegendContent />} />
                {stack.series.map((s) => (
                  // `fill` directo y no `var(--color-<key>)`: las claves de la
                  // config son UUIDs de vendedor, y el color lo toma también
                  // la leyenda desde el payload de la barra.
                  <Bar key={s.key} dataKey={s.key} stackId="v" fill={s.color} radius={0} />
                ))}
              </BarChart>
            </ChartContainer>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
