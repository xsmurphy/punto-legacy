"use client"

/**
 * Tab "Dashboard" del reporte de Análisis de Clientes.
 *
 * Bloques: composición de la cartera (nuevos vs recurrentes, con serie
 * diaria), tasas del período, ranking por monto y métricas de comportamiento.
 *
 * REGLA DE HONESTIDAD — las tasas vienen `null` cuando el período anterior no
 * tuvo clientes activos, y eso NO se pinta como 0%. Un 0% de retención dice
 * "los perdiste a todos"; la verdad es "no hay con qué comparar". Cada tile
 * que no puede calcularse muestra el motivo en vez de un número inventado.
 */

import * as React from "react"
import {
  Bar,
  BarChart,
  CartesianGrid,
  XAxis,
  YAxis,
} from "recharts"
import { Users } from "lucide-react"

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
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"
import { formatInt, formatMoney } from "@/lib/format"
import { formatDate, formatDateTime } from "@/lib/format-date"
import type { CustomerRow, CustomersDashboard } from "@/hooks/use-reports"
import type { Bootstrap } from "@/lib/types/bootstrap"

/** Cuántos clientes entran al gráfico de ranking — más no se leen de un vistazo. */
const RANKING_TOP = 10

const composicionChartConfig = {
  nuevos: { label: "Nuevos", color: "var(--chart-1)" },
  recurrentes: { label: "Recurrentes", color: "var(--chart-2)" },
} satisfies ChartConfig

const rankingChartConfig = {
  grossTotal: { label: "Total gastado", color: "var(--chart-1)" },
} satisfies ChartConfig

/** Porcentaje con un decimal. `null` no se formatea — lo maneja el tile. */
function formatPct(v: number): string {
  return `${v.toFixed(1)}%`
}

/**
 * Tile de una tasa. Cuando el backend manda `null` no hay número que mostrar:
 * se pinta un guion y el motivo debajo, para que nadie lea "sin datos" como
 * "cero".
 */
function RateTile({
  label,
  value,
  reason,
  tone,
  isLoading,
}: {
  label: string
  value: number | null
  reason: string
  tone?: (v: number) => "positive" | "negative" | "neutral"
  isLoading: boolean
}) {
  if (isLoading) {
    return <StatTile label={label} value="" isLoading />
  }
  if (value === null) {
    return (
      <StatTile
        label={label}
        value={
          <span className="flex flex-col gap-0.5">
            <span className="text-muted-foreground">—</span>
            <span className="text-sm font-normal text-muted-foreground">
              {reason}
            </span>
          </span>
        }
      />
    )
  }
  return (
    <StatTile
      label={label}
      value={formatPct(value)}
      tone={tone ? tone(value) : "neutral"}
    />
  )
}

export function CustomersDashboardTab({
  dashboard,
  rows,
  isLoading,
  bootstrap,
}: {
  dashboard: CustomersDashboard | undefined
  rows: CustomerRow[]
  isLoading: boolean
  bootstrap: Bootstrap | undefined
}) {
  const serie = dashboard?.serie ?? []
  const totales = dashboard?.totales
  const tasas = dashboard?.tasas
  const comp = dashboard?.comportamiento
  const periodo = dashboard?.periodo

  const ranking = React.useMemo(
    () =>
      rows
        .slice(0, RANKING_TOP)
        .map((r) => ({
          name: r.displayName || r.name || "(sin nombre)",
          grossTotal: r.grossTotal,
        }))
        .reverse(), // recharts pinta de abajo hacia arriba en layout vertical
    [rows],
  )

  // Sin base de comparación, las tres tasas que dependen del período anterior
  // comparten el mismo motivo. Se arma una sola vez.
  const sinBase = periodo
    ? `Sin clientes entre el ${formatDate(periodo.prevFrom)} y el ${formatDate(periodo.prevTo)}`
    : "Sin período anterior con datos"

  const serieVacia = !isLoading && serie.length === 0

  return (
    <div className="flex flex-col gap-4">
      <StatsRow>
        <StatTile
          label="Clientes activos"
          value={formatInt(totales?.activos ?? 0, bootstrap)}
          isLoading={isLoading}
          emphasis
        />
        <StatTile
          label="Nuevos"
          value={formatInt(totales?.nuevos ?? 0, bootstrap)}
          isLoading={isLoading}
        />
        <StatTile
          label="Recurrentes"
          value={formatInt(totales?.recurrentes ?? 0, bootstrap)}
          isLoading={isLoading}
        />
        <StatTile
          label="Total facturado"
          value={formatMoney(totales?.facturado ?? 0, bootstrap)}
          isLoading={isLoading}
        />
      </StatsRow>

      <Card>
        <CardHeader className="pb-2">
          <CardTitle>Nuevos vs recurrentes</CardTitle>
          <p className="text-sm text-muted-foreground">
            Un cliente cuenta como nuevo el día de su primera compra; en
            cualquier otro día que compre cuenta como recurrente.
          </p>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-[260px] w-full" />
          ) : serieVacia ? (
            <div className="flex h-[260px] items-center justify-center">
              <EmptyState
                icon={Users}
                title="Sin ventas a clientes en el período"
                description="Ajustá el rango de fechas y volvé a consultar."
                showMarquee={false}
                className="border-0 p-0"
              />
            </div>
          ) : (
            <ChartContainer
              config={composicionChartConfig}
              className="h-[260px] w-full"
            >
              <BarChart
                data={serie}
                margin={{ top: 10, right: 12, left: -10, bottom: 0 }}
              >
                <CartesianGrid
                  strokeDasharray="3 3"
                  stroke="var(--border)"
                  vertical={false}
                />
                <XAxis
                  dataKey="date"
                  tickFormatter={(v: string) => formatDateTime(v, "d MMM")}
                  fontSize={10}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
                />
                <YAxis
                  allowDecimals={false}
                  fontSize={10}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
                />
                <ChartTooltip
                  cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                  content={
                    <ChartTooltipContent
                      labelFormatter={(label) => formatDate(String(label))}
                    />
                  }
                />
                <ChartLegend content={<ChartLegendContent />} />
                <Bar
                  dataKey="nuevos"
                  stackId="clientes"
                  fill="var(--color-nuevos)"
                  maxBarSize={32}
                />
                <Bar
                  dataKey="recurrentes"
                  stackId="clientes"
                  fill="var(--color-recurrentes)"
                  radius={[4, 4, 0, 0]}
                  maxBarSize={32}
                />
              </BarChart>
            </ChartContainer>
          )}
        </CardContent>
      </Card>

      <div className="flex flex-col gap-2">
        <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Tasas del período
        </p>
        <StatsRow>
          <RateTile
            label="Retorno"
            value={tasas?.retorno ?? null}
            reason="Sin clientes activos en el período"
            isLoading={isLoading}
          />
          <RateTile
            label="Retención"
            value={tasas?.retencion ?? null}
            reason={sinBase}
            tone={(v) => (v >= 50 ? "positive" : "neutral")}
            isLoading={isLoading}
          />
          <RateTile
            label="Crecimiento"
            value={tasas?.crecimiento ?? null}
            reason={sinBase}
            tone={(v) => (v >= 0 ? "positive" : "negative")}
            isLoading={isLoading}
          />
          <RateTile
            label="Pérdida"
            value={tasas?.perdida ?? null}
            reason={sinBase}
            tone={(v) => (v > 50 ? "negative" : "neutral")}
            isLoading={isLoading}
          />
        </StatsRow>
        {!isLoading && periodo ? (
          <p className="text-sm text-muted-foreground">
            Retención, crecimiento y pérdida se comparan contra el{" "}
            {formatDate(periodo.prevFrom)} – {formatDate(periodo.prevTo)}, que
            tuvo {formatInt(periodo.prevActivos, bootstrap)} cliente
            {periodo.prevActivos === 1 ? "" : "s"}. Retorno no depende de ese
            período: es cuántos de los activos compraron dos o más veces dentro
            del rango elegido.
          </p>
        ) : null}
      </div>

      <Card>
        <CardHeader className="pb-2">
          <CardTitle>Clientes por monto</CardTitle>
          <p className="text-sm text-muted-foreground">
            Los {RANKING_TOP} que más gastaron en el período. El listado
            completo está en el tab Listado.
          </p>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-[280px] w-full" />
          ) : ranking.length === 0 ? (
            <div className="flex h-[280px] items-center justify-center">
              <EmptyState
                icon={Users}
                title="Sin clientes con ventas"
                description="Ajustá el rango de fechas y volvé a consultar."
                showMarquee={false}
                className="border-0 p-0"
              />
            </div>
          ) : (
            <ChartContainer
              config={rankingChartConfig}
              className="h-[280px] w-full"
            >
              <BarChart
                data={ranking}
                layout="vertical"
                margin={{ top: 4, right: 16, left: 8, bottom: 4 }}
              >
                <CartesianGrid
                  strokeDasharray="3 3"
                  stroke="var(--border)"
                  horizontal={false}
                />
                <XAxis
                  type="number"
                  fontSize={10}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
                  tickFormatter={(v: number) => formatInt(v, bootstrap)}
                />
                <YAxis
                  type="category"
                  dataKey="name"
                  width={140}
                  fontSize={10}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
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
                <Bar
                  dataKey="grossTotal"
                  fill="var(--color-grossTotal)"
                  radius={[0, 4, 4, 0]}
                  maxBarSize={22}
                />
              </BarChart>
            </ChartContainer>
          )}
        </CardContent>
      </Card>

      <div className="flex flex-col gap-2">
        <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Comportamiento de compra
        </p>
        <StatsRow>
          <StatTile
            label="Promedio por cliente"
            value={
              comp?.promedioPorCliente != null
                ? formatMoney(comp.promedioPorCliente, bootstrap)
                : "—"
            }
            isLoading={isLoading}
          />
          <StatTile
            label="Frecuencia de compra"
            value={
              comp?.frecuenciaCompra != null
                ? `${comp.frecuenciaCompra.toFixed(1)} compras`
                : "—"
            }
            isLoading={isLoading}
          />
          <StatTile
            label="Intervalo entre compras"
            value={
              comp?.intervaloDias != null ? (
                `${comp.intervaloDias.toFixed(1)} días`
              ) : (
                <span className="flex flex-col gap-0.5">
                  <span className="text-muted-foreground">—</span>
                  <span className="text-sm font-normal text-muted-foreground">
                    Ningún cliente compró dos veces en el período
                  </span>
                </span>
              )
            }
            isLoading={isLoading}
          />
        </StatsRow>
        {!isLoading && comp?.intervaloDias != null ? (
          <p className="text-sm text-muted-foreground">
            El intervalo se promedia sobre los{" "}
            {formatInt(comp.intervaloBase, bootstrap)} clientes que compraron
            dos o más veces en el período — los de una sola compra no aportan
            intervalo.
          </p>
        ) : null}
      </div>
    </div>
  )
}
