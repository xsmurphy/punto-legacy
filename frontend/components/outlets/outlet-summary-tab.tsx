"use client"

/**
 * Resumen de la ficha de una sucursal: cómo viene este mes.
 *
 * Referencia visual: el dashboard de Ventas (context/84 §2.1) — KPIs en
 * StatTile gris con comparación contra el período anterior y el gráfico en
 * una card blanca. Cuántas cajas y depósitos tiene NO va acá: son atributos
 * de la sucursal y se leen en el encabezado de la ficha.
 *
 * Los números salen del MISMO `/v1/reports/sales?dataset=series` que el
 * gráfico del dashboard de Ventas, con el alcance FIJO en esta sucursal
 * (`outletScope`). El servidor arma el calendario y elige el grano
 * (`lib/charts/granularity.ts`): con el mes en curso es por día, y el día 1
 * —rango de un solo día— por hora. El alcance es fijo porque el selector del
 * logo puede estar en otra sucursal o en "Todas", y la ficha de una sucursal
 * siempre habla de ella.
 *
 * El mes es FIJO (no el rango compartido del panel), igual que en la ficha de
 * persona: el bloque responde "cómo viene este mes".
 */

import * as React from "react"
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"
import { useReport, type SalesSeriesResponse } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { tenantNow } from "@/lib/format-date"
import {
  bucketTooltipLabel,
  formatBucketTick,
  perUnit,
  tooltipPoint,
} from "@/lib/charts/granularity"
import { pctDelta, shiftRangeBackwards } from "@/lib/reports/previous-range"

const chartConfig = { total: { label: "Vendido", color: "var(--chart-1)" } } satisfies ChartConfig

function totals(series: SalesSeriesResponse | undefined) {
  return (series?.sales ?? []).reduce(
    (acc, r) => ({
      total: acc.total + (Number(r.total) || 0),
      count: acc.count + (Number(r.count) || 0),
      discount: acc.discount + (Number(r.discount) || 0),
    }),
    { total: 0, count: 0, discount: 0 },
  )
}

export function OutletSummaryTab({ outletId }: { outletId: string }) {
  const { data: bootstrap } = useBootstrap()
  const canViewSales = usePermission("reports.sales.view")

  // Mes EN LA ZONA DEL COMERCIO, no del navegador.
  const today = tenantNow(bootstrap?.timezone).slice(0, 10)
  const range = React.useMemo(
    () => ({ from: `${today.slice(0, 8)}01 00:00:00`, to: `${today} 23:59:59` }),
    [today],
  )
  const prevRange = React.useMemo(() => shiftRangeBackwards(range.from, range.to), [range])

  const curr = useReport<SalesSeriesResponse>("sales", {
    ...range,
    params: { dataset: "series" },
    outletScope: outletId,
    enabled: canViewSales && !!bootstrap,
  })
  const prev = useReport<SalesSeriesResponse>("sales", {
    ...prevRange,
    params: { dataset: "series" },
    outletScope: outletId,
    enabled: canViewSales && !!bootstrap,
  })

  // Un punto por bucket del calendario que arma el servidor, con los vacíos
  // en cero: un hueco en el eje se lee como "no hay dato", no como "no se vendió".
  const granularity = curr.data?.granularity ?? "day"
  const series = React.useMemo(() => {
    const byBucket = new Map((curr.data?.sales ?? []).map((r) => [String(r.bucket), Number(r.total) || 0]))
    return (curr.data?.buckets ?? []).map((b) => ({ ...b, total: byBucket.get(String(b.bucket)) ?? 0 }))
  }, [curr.data])

  if (!canViewSales) {
    return <p className="text-sm text-muted-foreground">Sin permiso para ver las ventas de la sucursal.</p>
  }

  const c = totals(curr.data)
  const p = totals(prev.data)
  const avg = c.count > 0 ? c.total / c.count : 0
  const avgPrev = p.count > 0 ? p.total / p.count : 0
  const loading = curr.isLoading || prev.isLoading || !bootstrap

  return (
    <div className="flex flex-col gap-6">
      <StatsRow>
        <StatTile
          label="Vendido este mes"
          value={formatMoney(c.total, bootstrap)}
          emphasis
          delta={{ pct: pctDelta(c.total, p.total) }}
          isLoading={loading}
        />
        <StatTile
          label="Ventas"
          value={formatInt(c.count, bootstrap)}
          delta={{ pct: pctDelta(c.count, p.count) }}
          isLoading={loading}
        />
        <StatTile
          label="Ticket promedio"
          value={formatMoney(avg, bootstrap)}
          delta={{ pct: pctDelta(avg, avgPrev) }}
          isLoading={loading}
        />
        <StatTile
          label="Descuentos"
          value={formatMoney(c.discount, bootstrap)}
          delta={{ pct: pctDelta(c.discount, p.discount), higherIsBetter: false }}
          isLoading={loading}
        />
      </StatsRow>

      <Card>
        <CardHeader>
          <CardTitle>{perUnit("Ventas", granularity)}</CardTitle>
        </CardHeader>
        <CardContent>
          {loading ? (
            <Skeleton className="h-[240px] w-full" />
          ) : (
            <ChartContainer config={chartConfig} className="h-[240px] w-full">
              <BarChart data={series} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
                <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                <XAxis
                  dataKey="bucket"
                  tickFormatter={(v: string) => formatBucketTick(String(v), granularity)}
                  tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                  tickLine={false}
                  axisLine={false}
                />
                <YAxis tick={{ fontSize: 10, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} tickFormatter={compact} />
                <ChartTooltip
                  cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                  content={
                    <ChartTooltipContent
                      labelFormatter={(_, payload) => bucketTooltipLabel(tooltipPoint(payload), granularity)}
                      formatter={(value) => (
                        <span className="font-medium tabular-nums">{formatMoney(Number(value) || 0, bootstrap)}</span>
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
    </div>
  )
}

function compact(v: number): string {
  if (Math.abs(v) >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (Math.abs(v) >= 1_000) return `${(v / 1_000).toFixed(0)}K`
  return String(v)
}
