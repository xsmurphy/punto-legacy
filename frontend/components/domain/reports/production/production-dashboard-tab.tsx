"use client"

/**
 * Dashboard de producción — el resumen del período en una pantalla.
 *
 * Junta tres vistas que el backend ya devuelve (general, orders, waste) sin
 * pedir nada nuevo. Lo que NO hace, a propósito: sumar el costo de producción
 * con el de la merma. El costo de las unidades falladas de una orden ya está
 * repartido dentro del costo unitario de las buenas, y la merma lo vuelve a
 * valuar — sumarlos lo cuenta dos veces (context/76 §5, D4 abierta). Por eso
 * se muestran lado a lado y la card lo aclara.
 */

import * as React from "react"
import { AlertCircle } from "lucide-react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { CompositionDonutChart } from "@/components/domain/reports/composition-donut-chart"
import { RankingBarChart } from "@/components/domain/reports/ranking-bar-chart"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type ProductionOrdersResponse,
  type ProductionReportResponse,
  type ProductionWasteResponse,
} from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { formatMinutes } from "./production-orders-tab"

export function ProductionDashboardTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const base = React.useMemo(() => rangeToBackend(range), [range])

  const general = useReport<ProductionReportResponse>("production", React.useMemo(() => ({ ...base, params: { view: "general" } }), [base]))
  const orders = useReport<ProductionOrdersResponse>("production", React.useMemo(() => ({ ...base, params: { view: "orders" } }), [base]))
  const waste = useReport<ProductionWasteResponse>("production", React.useMemo(() => ({ ...base, params: { view: "waste" } }), [base]))

  const money = React.useCallback((v: number) => formatMoney(v, bootstrap), [bootstrap])
  const units = React.useCallback((v: number) => formatInt(v, bootstrap), [bootstrap])

  const isLoading = general.isLoading || orders.isLoading || waste.isLoading
  const error = general.error ?? orders.error ?? waste.error

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

  const g = general.data?.totals
  const o = orders.data?.totals
  const cov = orders.data?.coverage
  const w = waste.data

  return (
    <div className="flex flex-col gap-6">
      <StatsRow>
        <StatTile label="Unidades producidas" value={units(g?.qty ?? 0)} />
        <StatTile label="Costo de producción" value={money(g?.cogs ?? 0)} />
        <StatTile
          label="Rendimiento"
          value={o?.yieldPct === null || o?.yieldPct === undefined ? "—" : `${o.yieldPct.toFixed(1)}%`}
          emphasis
        />
        <StatTile label="Costo de la merma" value={money(w?.totals.cost ?? 0)} />
        <StatTile label="Duración promedio" value={formatMinutes(o?.avgDurationMin)} />
      </StatsRow>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold tracking-tight">Más producido</CardTitle>
            <CardDescription className="text-xs">Unidades producidas en el período.</CardDescription>
          </CardHeader>
          <CardContent>
            <RankingBarChart
              data={(general.data?.rows ?? []).map((r) => ({ label: r.name || "(sin nombre)", value: r.units }))}
              valueLabel="Unidades"
              formatValue={units}
            />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold tracking-tight">Merma por motivo</CardTitle>
            <CardDescription className="text-xs">
              No se suma al costo de producción: el de las unidades falladas ya está incluido ahí.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <CompositionDonutChart
              data={Object.entries(w?.byReason ?? {}).map(([label, value]) => ({ label: label || "Sin motivo", value }))}
              formatValue={money}
              restLabel="Otros motivos"
            />
          </CardContent>
        </Card>
      </div>

      {cov && cov.completed > cov.timed && (
        <p className="text-xs text-muted-foreground">
          La duración promedio sale de {cov.timed} de {cov.completed} órdenes completadas; el resto se produjo sin registrar el inicio.
        </p>
      )}
    </div>
  )
}
