"use client"

/**
 * Resumen de la ficha de una persona: cómo viene este mes.
 *
 * Referencia visual: el dashboard de Ventas (context/84 §2.1) — números en
 * StatTile gris con comparación contra el período anterior, gráficos en cards
 * blancas. Lo que NO va acá (owner 2026-09-18, captura de este mismo Resumen
 * como anti-patrón): el puesto, "en el equipo desde", cómo marca y la última
 * marcación son ATRIBUTOS de la persona y viven en el encabezado de la ficha;
 * la remuneración se ve y se edita en Datos.
 *
 * El mes es FIJO y no el rango compartido del panel: este bloque responde "cómo
 * viene este mes", y si siguiera al rango que quedó puesto en un reporte diría
 * otra cosa cada vez sin que el título cambie. El rango elegible está en la
 * pestaña Asistencia.
 *
 * ── De dónde salen las ventas ──────────────────────────────────────────────
 *
 * Del MISMO `/v1/reports/users` que Reportes › Equipo, vista `summary`. No hay
 * un cálculo propio acá: la atribución de una venta a una persona es
 * `COALESCE(itemSold.userId, transaction.userId)` y vive en `UsersService`.
 * Ese endpoint no filtra por usuario —devuelve el ranking del equipo y la
 * serie diaria de cada uno— así que la fila y la serie se buscan acá. Va
 * detrás de `reports.sales.view`: quien no puede ver los montos del equipo
 * tampoco los ve por esta puerta.
 *
 * Las horas salen de `/v1/attendance` acotado a la persona, ya agregadas por
 * período en el servidor (`series`): cada par suma en el período de la marca
 * que lo CIERRA, así que un turno nocturno no se cuenta dos veces.
 *
 * Las COMISIONES todavía no existen como dato (son el tarifario de la F4): no
 * se muestra un número inventado.
 */

import * as React from "react"
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts"
import { startOfWeek, subWeeks } from "date-fns"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { useAttendanceReport } from "@/hooks/use-attendance"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type UsersSummaryResponse } from "@/hooks/use-reports"
import { usePermission } from "@/hooks/use-permissions"
import { formatMinutes } from "@/components/domain/reports/attendance/attendance-format"
import type { Employee } from "@/hooks/use-employees"
import { formatMoney } from "@/lib/format-money"
import { pctDelta, shiftRangeBackwards } from "@/lib/reports/previous-range"
import {
  bucketTooltipLabel,
  formatBucketTick,
  perUnit,
  tooltipPoint,
} from "@/lib/charts/granularity"
import { partialBarCells } from "@/components/domain/reports/partial-bar-cells"

const WEEKS = 8
const pad = (n: number) => String(n).padStart(2, "0")
const day = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

/** Primer día del mes en curso y hoy, en el formato que espera el backend. */
function monthRange(): { from: string; to: string } {
  const now = new Date()
  return { from: `${day(new Date(now.getFullYear(), now.getMonth(), 1))} 00:00:00`, to: `${day(now)} 23:59:59` }
}

/**
 * Las últimas {WEEKS} semanas (del lunes de hace {WEEKS-1} semanas a hoy), para
 * los gráficos. Con más de 31 días el servidor agrupa por semana ISO
 * (`TimeBuckets`): el rango arranca un lunes para que la primera semana venga
 * entera, y la en curso llega marcada como incompleta.
 */
function weeksRange(): { from: string; to: string } {
  const now = new Date()
  const first = startOfWeek(subWeeks(now, WEEKS - 1), { weekStartsOn: 1 })
  return { from: `${day(first)} 00:00:00`, to: `${day(now)} 23:59:59` }
}

/**
 * Marcaciones del mes de la persona — el Resumen y el encabezado de la ficha
 * (última marcación) leen la MISMA consulta; react-query la pide una vez.
 */
export function useEmployeeMonthAttendance(employeeId: string, enabled: boolean) {
  const range = React.useMemo(monthRange, [])
  return useAttendanceReport({ ...range, employeeId }, enabled)
}

const salesChartConfig = { total: { label: "Vendido", color: "var(--chart-1)" } } satisfies ChartConfig
const hoursChartConfig = { hours: { label: "Horas", color: "var(--chart-2)" } } satisfies ChartConfig

export function EmployeeSummaryTab({
  employeeId,
  employee,
  isLoading,
  canViewAttendance,
}: {
  employeeId: string
  employee: Employee | null
  isLoading: boolean
  canViewAttendance: boolean
}) {
  const canViewSales = usePermission("reports.sales.view")
  const { data: bootstrap } = useBootstrap()
  const money = React.useCallback((v: number) => formatMoney(v, bootstrap ?? null), [bootstrap])
  const range = React.useMemo(monthRange, [])
  // Mismo largo, inmediatamente antes: la comparación de los reportes.
  const prevRange = React.useMemo(() => shiftRangeBackwards(range.from, range.to), [range])
  const weeks = React.useMemo(weeksRange, [])
  const withAttendance = canViewAttendance && employee !== null

  const attendance = useEmployeeMonthAttendance(employeeId, withAttendance)
  const attendancePrev = useAttendanceReport({ ...prevRange, employeeId }, withAttendance)
  const attendanceWeeks = useAttendanceReport({ from: weeks.from, to: weeks.to, employeeId }, withAttendance)

  const sales = useReport<UsersSummaryResponse>("users", { ...range, params: { view: "summary" }, enabled: canViewSales })
  const salesPrev = useReport<UsersSummaryResponse>("users", { ...prevRange, params: { view: "summary" }, enabled: canViewSales })
  const salesWeeks = useReport<UsersSummaryResponse>("users", {
    from: weeks.from,
    to: weeks.to,
    params: { view: "summary" },
    enabled: canViewSales,
  })

  // El ranking solo trae a quien vendió: la ausencia de la fila ES el cero.
  const mine = sales.data?.ranking?.find((r) => r.userId === employeeId) ?? null
  const minePrev = salesPrev.data?.ranking?.find((r) => r.userId === employeeId) ?? null
  const summary = attendance.data?.employees?.[0]
  const summaryPrev = attendancePrev.data?.employees?.[0]

  // Los períodos y el agregado salen del servidor: la serie del equipo trae lo
  // de cada vendedor por período, la de asistencia los minutos de la persona.
  // Las dos se piden con el MISMO rango, así que comparten calendario.
  const granularity = salesWeeks.data?.series?.granularity ?? attendanceWeeks.data?.series?.granularity ?? "week"
  const series = React.useMemo(() => {
    const sold = new Map<string, number>()
    for (const p of salesWeeks.data?.series?.points ?? []) {
      if (p.userId !== employeeId) continue
      sold.set(p.bucket, (sold.get(p.bucket) ?? 0) + (Number(p.total) || 0))
    }
    const minutes = new Map(
      (attendanceWeeks.data?.series?.points ?? []).map((p) => [p.bucket, p.workedMinutes] as const),
    )
    const calendar = salesWeeks.data?.series?.buckets ?? attendanceWeeks.data?.series?.points ?? []
    return calendar.map((b) => ({
      bucket: b.bucket,
      end: b.end,
      partial: b.partial,
      total: sold.get(b.bucket) ?? 0,
      hours: Math.round(((minutes.get(b.bucket) ?? 0) / 60) * 10) / 10,
    }))
  }, [salesWeeks.data, attendanceWeeks.data, employeeId])

  if (isLoading) {
    return (
      <div className="flex flex-col gap-3">
        <Skeleton className="h-20 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  if (!canViewSales && !withAttendance) {
    return <p className="text-sm text-muted-foreground">Sin datos de ventas ni de asistencia para mostrar.</p>
  }

  const tickets = mine?.tickets ?? 0
  const total = mine?.total ?? 0
  const worked = summary?.workedMinutes ?? 0
  const late = summary?.lateCount ?? 0

  return (
    <div className="flex flex-col gap-6">
      <StatsRow>
        {canViewSales && (
          <>
            <StatTile
              label="Vendido este mes"
              value={money(total)}
              emphasis
              delta={{ pct: pctDelta(total, minePrev?.total ?? 0) }}
              isLoading={sales.isLoading || salesPrev.isLoading}
            />
            <StatTile
              label="Ventas"
              value={tickets}
              delta={{ pct: pctDelta(tickets, minePrev?.tickets ?? 0) }}
              isLoading={sales.isLoading || salesPrev.isLoading}
            />
          </>
        )}
        {withAttendance && (
          <>
            <StatTile
              label="Horas trabajadas"
              value={formatMinutes(worked)}
              delta={{ pct: pctDelta(worked, summaryPrev?.workedMinutes ?? 0) }}
              isLoading={attendance.isLoading || attendancePrev.isLoading}
            />
            <StatTile
              label="Llegadas tarde"
              value={late}
              delta={{ pct: pctDelta(late, summaryPrev?.lateCount ?? 0), higherIsBetter: false }}
              isLoading={attendance.isLoading || attendancePrev.isLoading}
            />
          </>
        )}
      </StatsRow>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        {canViewSales && (
          <Card>
            <CardHeader>
              <CardTitle>{perUnit("Ventas", granularity)}</CardTitle>
            </CardHeader>
            <CardContent>
              {salesWeeks.isLoading ? (
                <Skeleton className="h-[220px] w-full" />
              ) : (
                <ChartContainer config={salesChartConfig} className="h-[220px] w-full">
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
                          formatter={(value) => <span className="font-medium tabular-nums">{money(Number(value) || 0)}</span>}
                        />
                      }
                    />
                    <Bar dataKey="total" fill="var(--color-total)" radius={[4, 4, 0, 0]}>
                      {partialBarCells(series)}
                    </Bar>
                  </BarChart>
                </ChartContainer>
              )}
            </CardContent>
          </Card>
        )}
        {withAttendance && (
          <Card>
            <CardHeader>
              <CardTitle>{perUnit("Horas", granularity)}</CardTitle>
            </CardHeader>
            <CardContent>
              {attendanceWeeks.isLoading ? (
                <Skeleton className="h-[220px] w-full" />
              ) : (
                <ChartContainer config={hoursChartConfig} className="h-[220px] w-full">
                  <BarChart data={series} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
                    <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                    <XAxis
                      dataKey="bucket"
                      tickFormatter={(v: string) => formatBucketTick(String(v), granularity)}
                      tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                      tickLine={false}
                      axisLine={false}
                    />
                    <YAxis allowDecimals={false} tick={{ fontSize: 10, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} />
                    <ChartTooltip
                      cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                      content={
                        <ChartTooltipContent
                          labelFormatter={(_, payload) => bucketTooltipLabel(tooltipPoint(payload), granularity)}
                        />
                      }
                    />
                    <Bar dataKey="hours" fill="var(--color-hours)" radius={[4, 4, 0, 0]}>
                      {partialBarCells(series)}
                    </Bar>
                  </BarChart>
                </ChartContainer>
              )}
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  )
}

function compact(v: number): string {
  if (Math.abs(v) >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (Math.abs(v) >= 1_000) return `${(v / 1_000).toFixed(0)}K`
  return String(v)
}
