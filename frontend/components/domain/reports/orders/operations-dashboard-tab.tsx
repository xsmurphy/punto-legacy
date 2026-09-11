"use client"

/**
 * Tab "Dashboard" del reporte de Órdenes — volumen, demora entre etapas,
 * re-trabajo y demanda por hora y por día.
 *
 * ── Vocabulario genérico ─────────────────────────────────────────────────────
 * El módulo de órdenes lo usa un taller, una óptica o un restaurante (regla
 * del owner 2026-09-10). Las etapas se nombran por lo que son en cualquier
 * rubro: espera, proceso, entrega.
 *
 * ── La cobertura va arriba de cada bloque ───────────────────────────────────
 * Los tiempos existen solo si alguien marcó los estados, y la máquina permite
 * saltar de enviada a entregada. Por eso el bloque de etapas declara primero
 * cuántas órdenes tenían las marcas y cuántas saltaron, y recién después
 * dibuja — el gráfico se ve igual, con la advertencia encima si la cobertura
 * es baja. Los números salen del backend (`OperationsService`), nunca se
 * recalculan acá (context/62, arquitectura rechazada 5).
 *
 * ── Comparativa ─────────────────────────────────────────────────────────────
 * Los KPIs de volumen comparan contra el período anterior de igual duración
 * (`shiftRangeBackwards`). "En curso" no lleva delta: es una foto de lo que
 * sigue abierto, no algo que se acumule en el período.
 */

import * as React from "react"
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts"
import { ClipboardList, Timer } from "lucide-react"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { formatInt } from "@/lib/format"
import { formatMinutes } from "@/lib/orders/order-stage-marks"
import { pctDelta } from "@/lib/reports/previous-range"
import type { OperationsReport, OperationsStages } from "@/hooks/use-reports"
import type { Bootstrap } from "@/lib/types/bootstrap"

import { CoverageNote, coveragePct } from "./coverage-note"

/**
 * Debajo de este porcentaje de órdenes marcadas de punta a punta, los tiempos
 * dejan de describir al negocio y describen a quienes marcan. Más alto que el
 * 30% del mapa de clientes a propósito: acá el número se usa para decidir
 * cuánta gente poner, y una muestra de la mitad ya es optimista.
 */
const COBERTURA_MINIMA_PCT = 50

/** ISODOW: el índice 0 es el bucket 1 (lunes). */
const WEEKDAY_LABELS = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"]

const minutesChartConfig = {
  minutes: { label: "Minutos", color: "var(--chart-1)" },
} satisfies ChartConfig

const ordersChartConfig = {
  orders: { label: "Órdenes", color: "var(--chart-1)" },
} satisfies ChartConfig

function pctLabel(part: number, total: number): string {
  return `${coveragePct(part, total).toFixed(0)}%`
}

export function OperationsDashboardTab({
  report,
  previous,
  isLoading,
  bootstrap,
}: {
  report: OperationsReport | undefined
  /** El mismo reporte en el período anterior de igual duración (solo volume + stages). */
  previous: OperationsReport | undefined
  isLoading: boolean
  bootstrap: Bootstrap | undefined
}) {
  const volume = report?.volume
  const stages = report?.stages
  const demand = report?.demand
  const prevVolume = previous?.volume
  const prevStages = previous?.stages

  const hourData = React.useMemo(() => {
    const byHour = new Map((demand?.byHour ?? []).map((b) => [b.bucket, b.orders]))
    return Array.from({ length: 24 }, (_, h) => ({
      label: String(h).padStart(2, "0"),
      orders: byHour.get(h) ?? 0,
    }))
  }, [demand])

  const weekdayData = React.useMemo(() => {
    const byDay = new Map((demand?.byWeekday ?? []).map((b) => [b.bucket, b.orders]))
    return WEEKDAY_LABELS.map((label, i) => ({ label, orders: byDay.get(i + 1) ?? 0 }))
  }, [demand])

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-3 md:flex-row">
          {Array.from({ length: 5 }, (_, i) => (
            <Skeleton key={i} className="h-20 flex-1" />
          ))}
        </div>
        <Skeleton className="h-[340px] w-full" />
        <div className="grid gap-4 lg:grid-cols-2">
          <Skeleton className="h-[300px] w-full" />
          <Skeleton className="h-[300px] w-full" />
        </div>
      </div>
    )
  }

  if (!volume || volume.total === 0) {
    return (
      <EmptyState
        icon={ClipboardList}
        title="Sin órdenes en este período"
        description="Ajustá el rango de fechas y volvé a consultar."
      />
    )
  }

  const worked = stages?.ordersTotal ?? 0
  const withRework = stages?.ordersWithRework ?? 0

  return (
    <div className="flex flex-col gap-4">
      <StatsRow>
        <StatTile
          label="Órdenes"
          value={formatInt(volume.total, bootstrap)}
          emphasis
          delta={prevVolume ? { pct: pctDelta(volume.total, prevVolume.total) } : undefined}
        />
        <StatTile
          label="Terminadas"
          value={`${formatInt(volume.completed, bootstrap)} (${pctLabel(volume.completed, volume.total)})`}
          delta={prevVolume ? { pct: pctDelta(volume.completed, prevVolume.completed) } : undefined}
        />
        <StatTile
          label="Canceladas"
          value={`${formatInt(volume.cancelled, bootstrap)} (${pctLabel(volume.cancelled, volume.total)})`}
          tone={volume.cancelled > 0 ? "negative" : "neutral"}
          delta={
            prevVolume
              ? { pct: pctDelta(volume.cancelled, prevVolume.cancelled), higherIsBetter: false }
              : undefined
          }
        />
        <StatTile label="En curso" value={formatInt(volume.open, bootstrap)} />
        <StatTile
          label="Con re-trabajo"
          value={`${formatInt(withRework, bootstrap)} (${pctLabel(withRework, worked)})`}
          tone={withRework > 0 ? "negative" : "neutral"}
          delta={
            prevStages
              ? { pct: pctDelta(withRework, prevStages.ordersWithRework), higherIsBetter: false }
              : undefined
          }
        />
      </StatsRow>

      {stages && <StageTimesCard stages={stages} bootstrap={bootstrap} />}

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle>Órdenes por hora</CardTitle>
            <p className="text-sm text-muted-foreground">
              Hora en que entró cada orden, en la zona horaria del comercio. Sin
              las canceladas.
            </p>
          </CardHeader>
          <CardContent>
            <ChartContainer config={ordersChartConfig} className="h-[260px] w-full">
              <BarChart data={hourData} margin={{ top: 4, right: 8, left: 0, bottom: 4 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis
                  dataKey="label"
                  fontSize={10}
                  interval={1}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
                />
                <YAxis
                  allowDecimals={false}
                  fontSize={10}
                  width={32}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
                />
                <ChartTooltip
                  cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                  content={
                    <ChartTooltipContent
                      labelFormatter={(_, payload) => `${payload?.[0]?.payload?.label ?? ""} h`}
                    />
                  }
                />
                <Bar dataKey="orders" fill="var(--color-orders)" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ChartContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle>Órdenes por día de la semana</CardTitle>
            <p className="text-sm text-muted-foreground">
              Suma del período: si el rango tiene dos lunes, la barra del lunes
              junta los dos.
            </p>
          </CardHeader>
          <CardContent>
            <ChartContainer config={ordersChartConfig} className="h-[260px] w-full">
              <BarChart data={weekdayData} margin={{ top: 4, right: 8, left: 0, bottom: 4 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis
                  dataKey="label"
                  fontSize={11}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
                />
                <YAxis
                  allowDecimals={false}
                  fontSize={10}
                  width={32}
                  stroke="var(--muted-foreground)"
                  tickLine={false}
                  axisLine={false}
                />
                <ChartTooltip
                  cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                  content={<ChartTooltipContent />}
                />
                <Bar dataKey="orders" fill="var(--color-orders)" radius={[4, 4, 0, 0]} maxBarSize={56} />
              </BarChart>
            </ChartContainer>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

/**
 * Demora entre etapas. Cobertura arriba (marcadas de punta a punta y cuántas
 * saltaron), el gráfico debajo con el denominador de cada barra, y el
 * re-trabajo al pie como lo que es: una señal de proceso, no una demora.
 */
function StageTimesCard({
  stages,
  bootstrap,
}: {
  stages: OperationsStages
  bootstrap: Bootstrap | undefined
}) {
  const cov = stages.coverage
  const samples = cov.stageSamples
  const trackedPct = coveragePct(cov.fullyTracked, cov.total)

  const data = [
    { label: "Espera", detail: "Enviada → En proceso", minutes: stages.avgToProgress, n: samples.toProgress },
    { label: "Proceso", detail: "En proceso → Lista", minutes: stages.avgProgressToReady, n: samples.progressToReady },
    { label: "Entrega", detail: "Lista → Entregada", minutes: stages.avgReadyToDelivered, n: samples.readyToDelivered },
  ].map((d) => ({ ...d, value: d.minutes ?? 0 }))

  const anyStage = data.some((d) => d.minutes !== null)

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle>Demora entre etapas</CardTitle>
        <p className="text-sm text-muted-foreground">
          Minutos promedio entre una marca de estado y la siguiente, leídos del
          historial de cada orden.
        </p>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <CoverageNote
          low={trackedPct < COBERTURA_MINIMA_PCT}
          lowTitle="Pocas órdenes con las etapas marcadas"
          lowDescription="Estos tiempos describen solo a las órdenes en las que alguien marcó cada etapa mientras trabajaba. Con menos de la mitad marcadas no son el promedio del negocio: son una muestra de quienes marcan."
        >
          Marcadas de punta a punta: {formatInt(cov.fullyTracked, bootstrap)} de{" "}
          {formatInt(cov.total, bootstrap)} órdenes ({trackedPct.toFixed(0)}%).{" "}
          {cov.skipped > 0 && (
            <>
              {formatInt(cov.skipped, bootstrap)} pasaron a Entregada sin marcar
              las etapas intermedias y no aportan a las barras.{" "}
            </>
          )}
          Cada barra se calcula sobre las órdenes que tienen sus dos marcas: espera{" "}
          {formatInt(samples.toProgress, bootstrap)}, proceso{" "}
          {formatInt(samples.progressToReady, bootstrap)}, entrega{" "}
          {formatInt(samples.readyToDelivered, bootstrap)}.
        </CoverageNote>

        {anyStage ? (
          <ChartContainer config={minutesChartConfig} className="h-[180px] w-full">
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 16, left: 8, bottom: 4 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" horizontal={false} />
              <XAxis
                type="number"
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
                tickFormatter={(v: number) => formatMinutes(v, bootstrap)}
              />
              <YAxis
                type="category"
                dataKey="label"
                width={72}
                fontSize={12}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={
                  <ChartTooltipContent
                    labelFormatter={(_, payload) => String(payload?.[0]?.payload?.detail ?? "")}
                    formatter={(_value, _name, item) => {
                      const p = item?.payload as { minutes: number | null; n: number } | undefined
                      if (!p || p.minutes === null) return "Sin órdenes con las dos marcas"
                      return `${formatMinutes(p.minutes, bootstrap)} · sobre ${formatInt(p.n, bootstrap)} órdenes`
                    }}
                  />
                }
              />
              <Bar dataKey="value" fill="var(--color-minutes)" radius={[0, 4, 4, 0]} maxBarSize={32} />
            </BarChart>
          </ChartContainer>
        ) : (
          <EmptyState
            icon={Timer}
            title="Ninguna orden tiene las etapas marcadas"
            description="Los tiempos aparecen cuando las órdenes se pasan a En proceso y a Lista mientras se trabajan, no todas juntas al final."
            showMarquee={false}
            className="border-0 p-0"
          />
        )}

        <StatsRow>
          <StatTile
            label="Total promedio"
            value={stages.avgTotal === null ? "—" : formatMinutes(stages.avgTotal, bootstrap)}
          />
          <StatTile
            label="Total mediana"
            value={stages.medianTotal === null ? "—" : formatMinutes(stages.medianTotal, bootstrap)}
          />
          <StatTile
            label="Vueltas a proceso"
            value={formatInt(stages.reworks, bootstrap)}
            tone={stages.reworks > 0 ? "negative" : "neutral"}
          />
          <StatTile
            label="Ítems devueltos"
            value={formatInt(stages.reworkItems, bootstrap)}
            tone={stages.reworkItems > 0 ? "negative" : "neutral"}
          />
        </StatsRow>
        <p className="text-sm text-muted-foreground">
          El total va de Enviada a Entregada sobre {formatInt(samples.total, bootstrap)} órdenes
          entregadas; la mediana no se mueve por una orden olvidada abierta. Las
          vueltas a proceso son órdenes que estaban Listas y volvieron a
          trabajarse: se cuentan aparte y no inflan los promedios de arriba.
        </p>
      </CardContent>
    </Card>
  )
}
