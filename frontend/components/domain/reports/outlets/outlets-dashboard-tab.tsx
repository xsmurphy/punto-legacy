"use client"

/**
 * Pestaña Dashboard del reporte de Sucursales.
 *
 *  1. Tabla comparativa: una fila por sucursal + fila total en el pie. Los
 *     números son los del KPI del dashboard (`PeriodStats` en el backend): el
 *     pie ES el KPI del Inicio para el mismo período, y las filas suman eso.
 *     El pie no se suma en el navegador —margen, ticket y clientes activos no
 *     son aditivos (un cliente que compró en dos sucursales es uno)—, viene
 *     calculado del servidor (`meta.footer` del DataTable).
 *  2. Participación en ventas y en ganancia (solo con 2+ porciones).
 *  3. Evolución: una línea por sucursal con el grano automático compartido.
 *
 * Sin fila de KPIs arriba a propósito: serían los mismos números del pie de
 * la tabla, y un número va una sola vez por pantalla (`context/84` T10).
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, Store } from "lucide-react"
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from "recharts"

import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { Skeleton } from "@/components/ui/skeleton"
import { BarList } from "@/components/charts/bar-list"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { DeltaLine } from "@/components/stat-tile"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport } from "@/hooks/use-reports"
import { formatInt, formatMoney, formatMoneyCompact, formatPercent } from "@/lib/format"
import { partialLineDot } from "@/components/domain/reports/partial-bar-cells"
import {
  bucketTooltipLabel,
  formatBucketTick,
  perUnit,
  tooltipPoint,
} from "@/lib/charts/granularity"
import { evolutionChart, outletDelta, shareOf, type ShareItem } from "@/lib/reports/outlets-comparison"
import type {
  OutletPeriodStats,
  OutletsSeriesResponse,
  OutletsSummaryResponse,
  OutletSummaryRow,
} from "@/lib/types/outlets-report"
import type { Bootstrap } from "@/lib/types/bootstrap"

export function OutletsDashboardTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const { from, to } = React.useMemo(() => rangeToBackend(range), [range])

  const summary = useReport<OutletsSummaryResponse>("outlets", {
    from,
    to,
    params: { dataset: "summary" },
  })
  const series = useReport<OutletsSeriesResponse>("outlets", {
    from,
    to,
    params: { dataset: "series" },
  })

  if (summary.error) {
    return <LoadError message={summary.error.message} />
  }

  const rows = summary.data?.rows ?? []
  const salesShare = shareOf(rows, "total")
  const revenueShare = shareOf(rows, "revenue")

  return (
    <div className="flex flex-col gap-4">
      <ComparisonTable
        rows={rows}
        total={summary.data?.total}
        isLoading={summary.isLoading}
        bootstrap={bootstrap}
      />

      {(salesShare.length > 0 || revenueShare.length > 0) && (
        <div className="grid gap-4 md:grid-cols-2">
          {salesShare.length > 0 && (
            <ShareCard title="Participación en ventas" items={salesShare} bootstrap={bootstrap} />
          )}
          {revenueShare.length > 0 && (
            <ShareCard title="Participación en ganancia" items={revenueShare} bootstrap={bootstrap} />
          )}
        </div>
      )}

      {series.isLoading ? (
        <Skeleton className="h-[340px] w-full rounded-xl" />
      ) : series.data ? (
        <EvolutionCard
          res={series.data}
          order={rows.map((r) => r.outletId)}
          bootstrap={bootstrap}
        />
      ) : null}
    </div>
  )
}

/* ───────────────────────── tabla comparativa ───────────────────────── */

function DeltaCell({ pct }: { pct: number | null | undefined }) {
  if (pct === undefined) return <span className="text-muted-foreground">—</span>
  return <DeltaLine pct={pct} compact />
}

function ComparisonTable({
  rows,
  total,
  isLoading,
  bootstrap,
}: {
  rows: OutletSummaryRow[]
  total: OutletPeriodStats | undefined
  isLoading: boolean
  bootstrap: Bootstrap | undefined
}) {
  const router = useRouter()

  const columns = React.useMemo<ColumnDef<OutletSummaryRow>[]>(() => {
    const money = (v: number) => formatMoney(v, bootstrap)
    const right = (node: React.ReactNode) => <div className="text-right tabular-nums">{node}</div>
    const foot = (node: React.ReactNode) =>
      total ? <div className="text-right font-semibold tabular-nums">{node}</div> : undefined

    return [
      {
        accessorKey: "name",
        header: "Sucursal",
        meta: {
          label: "Sucursal",
          footer: total ? <span className="font-semibold">Total</span> : undefined,
        },
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            <span className="font-medium">{row.original.name}</span>
            {!row.original.active && <Badge variant="secondary">Inactiva</Badge>}
          </div>
        ),
      },
      {
        accessorKey: "total",
        header: () => <div className="text-right">Ventas</div>,
        meta: { label: "Ventas", className: "text-right", footer: foot(total && money(total.total)) },
        cell: ({ row }) => right(money(row.original.total)),
      },
      {
        id: "salesDelta",
        accessorFn: (r) => outletDelta(r, "total") ?? null,
        header: () => <div className="text-right">Var. ventas</div>,
        meta: {
          label: "Var. ventas",
          className: "text-right",
          footer: total ? right(<DeltaCell pct={outletDelta(total, "total")} />) : undefined,
        },
        sortUndefined: "last",
        cell: ({ row }) => right(<DeltaCell pct={outletDelta(row.original, "total")} />),
      },
      {
        accessorKey: "revenue",
        header: () => <div className="text-right">Ganancia</div>,
        meta: { label: "Ganancia", className: "text-right", footer: foot(total && money(total.revenue)) },
        cell: ({ row }) => right(money(row.original.revenue)),
      },
      {
        id: "revenueDelta",
        accessorFn: (r) => outletDelta(r, "revenue") ?? null,
        header: () => <div className="text-right">Var. ganancia</div>,
        meta: {
          label: "Var. ganancia",
          className: "text-right",
          footer: total ? right(<DeltaCell pct={outletDelta(total, "revenue")} />) : undefined,
        },
        sortUndefined: "last",
        cell: ({ row }) => right(<DeltaCell pct={outletDelta(row.original, "revenue")} />),
      },
      {
        accessorKey: "margin",
        header: () => <div className="text-right">Margen</div>,
        meta: { label: "Margen", className: "text-right", footer: foot(total && `${formatInt(total.margin, bootstrap)} %`) },
        cell: ({ row }) => right(`${formatInt(row.original.margin, bootstrap)} %`),
      },
      {
        accessorKey: "count",
        header: () => <div className="text-right">Cant. ventas</div>,
        meta: { label: "Cant. ventas", className: "text-right", footer: foot(total && formatInt(total.count, bootstrap)) },
        cell: ({ row }) => right(formatInt(row.original.count, bootstrap)),
      },
      {
        accessorKey: "customerAverage",
        header: () => <div className="text-right">Ticket promedio</div>,
        meta: { label: "Ticket promedio", className: "text-right", footer: foot(total && money(total.customerAverage)) },
        cell: ({ row }) => right(money(row.original.customerAverage)),
      },
      {
        accessorKey: "activeCustomers",
        header: () => <div className="text-right">Clientes activos</div>,
        meta: {
          label: "Clientes activos",
          className: "text-right",
          footer: foot(total && formatInt(total.activeCustomers, bootstrap)),
        },
        cell: ({ row }) => right(formatInt(row.original.activeCustomers, bootstrap)),
      },
    ]
  }, [bootstrap, total])

  return (
    <DataTable<OutletSummaryRow>
      tableId="report-outlets-comparison"
      data={rows}
      columns={columns}
      getRowId={(r) => r.outletId}
      isLoading={isLoading}
      onRowClick={(r) => router.push(`/outlets/${r.outletId}`)}
      searchPlaceholder="Buscar sucursal…"
      stickyFirstColumn
      emptyMessage={
        <EmptyState
          icon={Store}
          title="Sin sucursales en este período"
          description="Ajustá el rango de fechas."
        />
      }
      exportFileName="sucursales"
    />
  )
}

/* ───────────────────────── participación ───────────────────────── */

function ShareCard({
  title,
  items,
  bootstrap,
}: {
  title: string
  items: ShareItem[]
  bootstrap: Bootstrap | undefined
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <BarList
          items={items.map((it) => ({
            key: it.outletId,
            label: it.name,
            value: it.value,
            display: formatPercent(it.pct, bootstrap),
            meta: formatMoneyCompact(it.value, bootstrap),
            href: `/outlets/${it.outletId}`,
          }))}
        />
      </CardContent>
    </Card>
  )
}

/* ───────────────────────── evolución ───────────────────────── */

function EvolutionCard({
  res,
  order,
  bootstrap,
}: {
  res: OutletsSeriesResponse
  order: string[]
  bootstrap: Bootstrap | undefined
}) {
  const granularity = res.granularity
  const { series, data, truncated } = React.useMemo(() => evolutionChart(res, order), [res, order])

  const config = React.useMemo<ChartConfig>(() => {
    const cfg: ChartConfig = {}
    series.forEach((s, i) => {
      cfg[s.key] = { label: s.name, color: `var(--chart-${(i % 5) + 1})` }
    })
    return cfg
  }, [series])

  // Una sola sucursal con ventas: su línea es la de Ventas del reporte de
  // ventas, no una comparación.
  if (series.length < 2) return null

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          {perUnit("Ventas", granularity)}
          {truncated ? ` · ${series.length} sucursales con más ventas` : ""}
        </CardTitle>
      </CardHeader>
      <CardContent>
        <ChartContainer config={config} className="h-[280px] w-full">
          <LineChart data={data} margin={{ top: 10, right: 12, left: 12, bottom: 0 }}>
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
              fontSize={10}
              stroke="var(--muted-foreground)"
              tickLine={false}
              axisLine={false}
              width={80}
              tickFormatter={(v: number) => formatMoneyCompact(v, bootstrap)}
            />
            <ChartTooltip
              content={
                <ChartTooltipContent
                  labelFormatter={(label, payload) =>
                    bucketTooltipLabel(tooltipPoint(payload), granularity) ||
                    formatBucketTick(String(label), granularity)
                  }
                  formatter={(value, name) => (
                    <div className="flex w-full items-center justify-between gap-3">
                      <span className="text-muted-foreground">
                        {config[name as string]?.label ?? name}
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
            {series.map((s) => (
              <Line
                key={s.key}
                type="monotone"
                dataKey={s.key}
                stroke={`var(--color-${s.key})`}
                strokeWidth={2}
                // Períodos recortados por el rango: punto atenuado.
                dot={partialLineDot(`var(--color-${s.key})`)}
              />
            ))}
          </LineChart>
        </ChartContainer>
      </CardContent>
    </Card>
  )
}

function LoadError({ message }: { message: string }) {
  return (
    <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
      <AlertCircle className="mt-0.5 size-4 text-destructive" />
      <div>
        <p className="font-medium">No se pudo cargar el reporte</p>
        <p className="text-sm text-muted-foreground">{message}</p>
      </div>
    </div>
  )
}
