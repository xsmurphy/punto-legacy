"use client"

/**
 * Tab "Dashboard" del reporte de Análisis de Clientes.
 *
 * ── Composición pensada para escritorio (owner, 2026-08-31) ──────────────────
 * El primer armado apilaba un bloque debajo del otro, que en una pantalla ancha
 * desperdicia la mitad del ancho y obliga a scrollear para cruzar datos que se
 * leen juntos. Ahora cada fila junta lo que el dueño compara mentalmente:
 *
 *   Fila 1 · Composición de la cartera (dona) + serie diaria de nuevos vs
 *            recurrentes. La dona dice CUÁNTOS son de cada grupo en el período;
 *            la serie, CUÁNDO aparecieron. Es la misma pregunta en dos vistas.
 *   Fila 2 · Tasas del período, pegadas debajo de la serie: retención, pérdida
 *            y crecimiento se leen contra esa curva, no aisladas.
 *   Fila 3 · Ranking por monto + comportamiento de compra. El ranking dice
 *            quién gasta; las métricas de consumo, cuánto y cada cuánto gasta
 *            el promedio — el ranking sin esa referencia no se puede juzgar.
 *
 * Bajo `lg` todo vuelve a apilarse en ese mismo orden de lectura.
 *
 * REGLA DE HONESTIDAD — las tasas vienen `null` cuando el período anterior no
 * tuvo clientes activos, y eso NO se pinta como 0%. Un 0% de retención dice
 * "los perdiste a todos"; la verdad es "no hay con qué comparar". Cada tile
 * que no puede calcularse muestra el motivo en vez de un número inventado.
 * La dona sigue la misma regla: sin clientes activos no se dibuja un anillo
 * vacío ni un 0 en el centro — se dice que el período no tuvo movimiento.
 */

import * as React from "react"
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
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

function share(part: number, total: number): number {
  return total > 0 ? (part / total) * 100 : 0
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

/**
 * Fila del desglose que va debajo de la dona: punto del color de la porción,
 * nombre del grupo, cantidad y participación. La dona sola da la proporción a
 * ojo; el número exacto lo pone esta lista.
 */
function CompositionRow({
  label,
  color,
  value,
  total,
  bootstrap,
}: {
  label: string
  color: string
  value: number
  total: number
  bootstrap: Bootstrap | undefined
}) {
  return (
    <div className="flex items-center justify-between gap-2">
      <span className="flex items-center gap-2 text-sm text-muted-foreground">
        <span
          className="size-2.5 shrink-0 rounded-full"
          style={{ background: color }}
        />
        {label}
      </span>
      <span className="flex items-baseline gap-2">
        <span className="text-base font-semibold tabular-nums">
          {formatInt(value, bootstrap)}
        </span>
        <span className="text-sm text-muted-foreground tabular-nums">
          {formatPct(share(value, total))}
        </span>
      </span>
    </div>
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

  const activos = totales?.activos ?? 0
  const nuevos = totales?.nuevos ?? 0
  const recurrentes = totales?.recurrentes ?? 0
  const registrados = totales?.registrados ?? 0

  // Una porción de valor 0 no se dibuja pero sí deja un tooltip fantasma: se
  // saca del dataset y queda solo en el desglose de abajo, donde el 0 sí es
  // información legible.
  const donut = React.useMemo(
    () =>
      [
        { key: "nuevos", value: nuevos, fill: "var(--color-nuevos)" },
        {
          key: "recurrentes",
          value: recurrentes,
          fill: "var(--color-recurrentes)",
        },
      ].filter((d) => d.value > 0),
    [nuevos, recurrentes],
  )

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
  const carteraVacia = !isLoading && activos === 0

  return (
    <div className="flex flex-col gap-4">
      <StatsRow>
        <StatTile
          label="Clientes registrados"
          value={formatInt(registrados, bootstrap)}
          isLoading={isLoading}
        />
        <StatTile
          label="Clientes activos"
          value={formatInt(activos, bootstrap)}
          isLoading={isLoading}
          emphasis
        />
        <StatTile
          label="Compras"
          value={formatInt(totales?.compras ?? 0, bootstrap)}
          isLoading={isLoading}
        />
        <StatTile
          label="Total facturado"
          value={formatMoney(totales?.facturado ?? 0, bootstrap)}
          isLoading={isLoading}
        />
      </StatsRow>

      {/* Fila 1 — la dona (cuántos de cada grupo) al lado de la serie (cuándo
          aparecieron). 1/3 y 2/3: la dona es un número y dos líneas, la serie
          necesita ancho para que cada día siga siendo una barra distinguible. */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle>Composición de la cartera</CardTitle>
            <p className="text-sm text-muted-foreground">
              Clientes que compraron en el período, partidos entre los que
              compraron por primera vez y los que ya eran clientes.
            </p>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="flex flex-col gap-3">
                <Skeleton className="mx-auto size-[180px] rounded-full" />
                <Skeleton className="h-5 w-full" />
                <Skeleton className="h-5 w-full" />
              </div>
            ) : carteraVacia ? (
              <EmptyState
                icon={Users}
                title="Sin clientes activos en el período"
                description="Ajustá el rango de fechas y volvé a consultar."
                showMarquee={false}
                className="border-0 p-0"
              />
            ) : (
              <div className="flex flex-col gap-4">
                <div className="relative mx-auto w-full max-w-[220px]">
                  <ChartContainer
                    config={composicionChartConfig}
                    className="aspect-square h-[200px] w-full"
                  >
                    <PieChart>
                      <ChartTooltip
                        content={
                          <ChartTooltipContent nameKey="key" hideLabel />
                        }
                      />
                      <Pie
                        data={donut}
                        dataKey="value"
                        nameKey="key"
                        innerRadius="62%"
                        outerRadius="100%"
                        paddingAngle={2}
                        strokeWidth={0}
                      >
                        {donut.map((d) => (
                          <Cell key={d.key} fill={d.fill} />
                        ))}
                      </Pie>
                    </PieChart>
                  </ChartContainer>
                  {/* El total va en el centro del anillo. Es un rótulo, no un
                      control: `pointer-events-none` deja que el hover siga
                      llegando a las porciones de abajo. */}
                  <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-2xl font-bold tabular-nums">
                      {formatInt(activos, bootstrap)}
                    </span>
                    <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Clientes
                    </span>
                  </div>
                </div>

                <div className="flex flex-col gap-2">
                  <CompositionRow
                    label="Nuevos"
                    color="var(--chart-1)"
                    value={nuevos}
                    total={activos}
                    bootstrap={bootstrap}
                  />
                  <CompositionRow
                    label="Recurrentes"
                    color="var(--chart-2)"
                    value={recurrentes}
                    total={activos}
                    bootstrap={bootstrap}
                  />
                </div>

                {registrados > 0 ? (
                  <p className="text-sm text-muted-foreground">
                    Compraron {formatInt(activos, bootstrap)} de los{" "}
                    {formatInt(registrados, bootstrap)} clientes registrados en
                    el comercio ({formatPct(share(activos, registrados))} de la
                    cartera).
                  </p>
                ) : null}
              </div>
            )}
          </CardContent>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader className="pb-2">
            <CardTitle>Nuevos vs recurrentes</CardTitle>
            <p className="text-sm text-muted-foreground">
              Un cliente cuenta como nuevo el día de su primera compra; en
              cualquier otro día que compre cuenta como recurrente.
            </p>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-[280px] w-full" />
            ) : serieVacia ? (
              <div className="flex h-[280px] items-center justify-center">
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
                className="h-[280px] w-full"
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
      </div>

      {/* Fila 2 — las cuatro tasas juntas y pegadas a la serie de arriba: se
          leen contra esa curva. Van a ancho completo porque cada tile lleva un
          motivo de una línea cuando no hay base de comparación. */}
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

      {/* Fila 3 — quién gasta (ranking) al lado de cuánto y cada cuánto gasta
          el promedio. 2/3 y 1/3: las barras necesitan ancho para que el nombre
          entre en el eje; las métricas son tres números apilados. */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-2">
            <CardTitle>Clientes por monto</CardTitle>
            <p className="text-sm text-muted-foreground">
              Los {RANKING_TOP} que más gastaron en el período. El listado
              completo está en el tab Listado.
            </p>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-[320px] w-full" />
            ) : ranking.length === 0 ? (
              <div className="flex h-[320px] items-center justify-center">
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
                className="h-[320px] w-full"
              >
                {/* Barras acostadas a propósito: el eje de categoría son
                    nombres de personas, que no entran debajo de una columna.
                    (En Geográfico las localidades sí van de pie — ahí el
                    ranking es corto y el nombre se recorta sin perder sentido.) */}
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
          <StatTile
            className="flex-none"
            label="Promedio por cliente"
            value={
              comp?.promedioPorCliente != null
                ? formatMoney(comp.promedioPorCliente, bootstrap)
                : "—"
            }
            isLoading={isLoading}
          />
          <StatTile
            className="flex-none"
            label="Frecuencia de compra"
            value={
              comp?.frecuenciaCompra != null
                ? `${comp.frecuenciaCompra.toFixed(1)} compras`
                : "—"
            }
            isLoading={isLoading}
          />
          <StatTile
            className="flex-none"
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
    </div>
  )
}
