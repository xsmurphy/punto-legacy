"use client"

/**
 * Dashboard de Equipo — cómo vendió cada persona en el período.
 *
 * Sale de `GET /v1/reports/users?view=summary`, que trae los KPIs, el ranking
 * por vendedor y la serie en una sola respuesta. El grano de la serie (día,
 * semana o mes según el largo del rango) lo decide el servidor y se muestra
 * con `lib/charts/granularity.ts`.
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
  type UsersSeries,
  type UserRankingRow,
  type UsersSummaryResponse,
} from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import {
  bucketTooltipLabel,
  formatBucketTick,
  perUnit,
  tooltipPoint,
  type Granularity,
} from "@/lib/charts/granularity"
import { partialBarCells } from "@/components/domain/reports/partial-bar-cells"

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

export interface SeriesStack {
  /** Una fila por período del calendario (con `bucket`/`end`/`partial`) y una columna por vendedor. */
  rows: Array<Record<string, string | number | boolean>>
  series: Array<{ key: string; label: string; color: string }>
}

/**
 * Pivotea la serie plana `(período, vendedor, total)` a una fila por período
 * con una columna por vendedor, que es lo que come Recharts. Las filas salen
 * del calendario que manda el servidor: un período sin ventas queda en cero en
 * vez de desaparecer del eje.
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
export function buildSeriesStack(
  data: UsersSeries | undefined,
  ranking: UserRankingRow[],
  limit = 5,
): SeriesStack {
  const top = ranking.slice(0, limit)
  const topIds = new Set(top.map((r) => r.userId))
  const hasRest = ranking.length > top.length

  const byBucket = new Map<string, Record<string, string | number | boolean>>()
  for (const b of data?.buckets ?? []) {
    const row: Record<string, string | number | boolean> = { bucket: b.bucket, end: b.end, partial: b.partial }
    top.forEach((t) => { row[t.userId] = 0 })
    if (hasRest) row[REST_KEY] = 0
    byBucket.set(b.bucket, row)
  }
  for (const p of data?.points ?? []) {
    const row = byBucket.get(p.bucket)
    if (!row) continue
    const key = topIds.has(p.userId) ? p.userId : REST_KEY
    if (key === REST_KEY && !hasRest) continue
    row[key] = (Number(row[key]) || 0) + p.total
  }

  const series = top.map((t, i) => ({
    key: t.userId,
    label: t.name || "(sin nombre)",
    color: SERIES_COLORS[i % SERIES_COLORS.length],
  }))
  if (hasRest) series.push({ key: REST_KEY, label: "Otros", color: REST_COLOR })

  // Sin ventas en todo el período no hay nada que apilar.
  const rows = data?.points?.length ? [...byBucket.values()] : []
  return { rows, series }
}

/** "Evolución diaria" / "semanal" / "mensual". */
function evolutionTitle(g: Granularity): string {
  if (g === "week") return "Evolución semanal"
  if (g === "month") return "Evolución mensual"
  return "Evolución diaria"
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
  const granularity = data?.series?.granularity ?? "day"
  const stack = React.useMemo(
    () => buildSeriesStack(data?.series, ranking),
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
            <CardTitle>Quién vendió más</CardTitle>
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
            <CardTitle>Participación</CardTitle>
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
          <CardTitle>{evolutionTitle(granularity)}</CardTitle>
          <CardDescription className="text-xs">
            {perUnit("Ventas", granularity)}, apiladas por vendedor.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {stack.rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">Sin ventas para graficar en este período.</p>
          ) : (
            <ChartContainer config={chartConfig} className="h-[280px] w-full">
              <BarChart data={stack.rows} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis
                  dataKey="bucket"
                  tickFormatter={(v: string) => formatBucketTick(String(v), granularity)}
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
                      labelFormatter={(v, payload) =>
                        bucketTooltipLabel(tooltipPoint(payload), granularity) ||
                        formatBucketTick(String(v), granularity)
                      }
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
                  <Bar key={s.key} dataKey={s.key} stackId="v" fill={s.color} radius={0}>
                    {partialBarCells(stack.rows)}
                  </Bar>
                ))}
              </BarChart>
            </ChartContainer>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
