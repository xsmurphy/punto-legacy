"use client"

/**
 * Mermas — lo que se perdió en el período, por motivo y por artículo.
 *
 * Sale de `view=waste`, que el backend devolvía desde antes y la pantalla no
 * usaba (context/76 F1). Mezcla dos orígenes que conviene no confundir:
 * `production` son unidades falladas de una orden, `manual` es merma suelta
 * (rotura, vencimiento). La columna Origen los distingue.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, Trash2 } from "lucide-react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { CompositionDonutChart } from "@/components/domain/reports/composition-donut-chart"
import { RankingBarChart } from "@/components/domain/reports/ranking-bar-chart"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type ProductionWasteResponse, type ProductionWasteRow } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { formatQty } from "@/lib/format-qty"
import { formatDateTime } from "@/lib/format-date"

export function ProductionWasteTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(() => ({ ...rangeToBackend(range), params: { view: "waste" } }), [range])
  const { data, isLoading, error } = useReport<ProductionWasteResponse>("production", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  // Merma valuada en 0: pasa cuando el artículo no tenía stock al registrarla
  // (`ProductionService::registerWaste`). Se cuenta aparte para que el costo
  // total no se lea como completo cuando no lo es.
  const sinCosto = React.useMemo(() => rows.filter((r) => r.cost === 0).length, [rows])

  const porArticulo = React.useMemo(() => {
    const acc = new Map<string, number>()
    for (const r of rows) acc.set(r.name || "(sin nombre)", (acc.get(r.name || "(sin nombre)") ?? 0) + r.cost)
    return [...acc.entries()].map(([label, value]) => ({ label, value }))
  }, [rows])

  const money = React.useCallback((v: number) => formatMoney(v, bootstrap), [bootstrap])

  const columns = React.useMemo<ColumnDef<ProductionWasteRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => <span className="tabular-nums">{formatDateTime(String(getValue()), "d MMM yyyy, HH:mm")}</span>,
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "name",
        header: "Artículo",
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span className="font-medium">{row.original.name || "(sin nombre)"}</span>
            {row.original.sku && <span className="text-xs text-muted-foreground">{row.original.sku}</span>}
          </div>
        ),
        meta: { label: "Artículo" },
      },
      { accessorKey: "reasonName", header: "Motivo", meta: { label: "Motivo" } },
      {
        accessorKey: "source",
        header: "Origen",
        cell: ({ getValue }) => (
          <Badge variant="outline">{getValue() === "production" ? "Producción" : "Manual"}</Badge>
        ),
        meta: { label: "Origen" },
      },
      {
        accessorKey: "qty",
        header: "Cantidad",
        cell: ({ getValue }) => <span className="tabular-nums">{formatQty(Number(getValue()), bootstrap)}</span>,
        meta: { label: "Cantidad", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "cost",
        header: "Costo",
        cell: ({ getValue }) => <span className="tabular-nums">{formatMoney(Number(getValue()), bootstrap)}</span>,
        meta: { label: "Costo", className: "tabular-nums text-right" },
      },
      { accessorKey: "userName", header: "Usuario", meta: { label: "Usuario" } },
    ],
    [bootstrap],
  )

  if (error) return <ErrorBox message={error.message} />

  return (
    <div className="flex flex-col gap-6">
      {!isLoading && rows.length > 0 && (
        <>
          <StatsRow>
            <StatTile label="Eventos de merma" value={formatInt(rows.length, bootstrap)} />
            <StatTile label="Costo de la merma" value={money(data?.totals.cost ?? 0)} emphasis />
            <StatTile
              label="Sin costo registrado"
              value={formatInt(sinCosto, bootstrap)}
              tone={sinCosto > 0 ? "negative" : "neutral"}
            />
          </StatsRow>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold tracking-tight">Por motivo</CardTitle>
                <CardDescription className="text-xs">Qué porción del costo de merma se lleva cada motivo.</CardDescription>
              </CardHeader>
              <CardContent>
                <CompositionDonutChart
                  data={Object.entries(data?.byReason ?? {}).map(([label, value]) => ({ label: label || "Sin motivo", value }))}
                  formatValue={money}
                  restLabel="Otros motivos"
                />
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold tracking-tight">Por artículo</CardTitle>
                <CardDescription className="text-xs">Los artículos cuya merma más cuesta.</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                <RankingBarChart data={porArticulo} valueLabel="Costo de merma" formatValue={money} />
                {sinCosto > 0 && (
                  <p className="text-xs text-muted-foreground">
                    {sinCosto} eventos no tienen costo porque el artículo no tenía stock al registrarlos: el total está por debajo de lo real.
                  </p>
                )}
              </CardContent>
            </Card>
          </div>
        </>
      )}

      <DataTable
        tableId="report-production-waste"
        data={rows}
        columns={columns}
        getRowId={(r) => `${r.itemId}-${r.date}-${r.qty}`}
        isLoading={isLoading}
        searchPlaceholder="Buscar por artículo, motivo…"
        exportFileName="mermas"
        emptyMessage={<EmptyState icon={Trash2} title="Sin mermas en el período" description="Ajustá el rango de fechas y volvé a consultar." />}
      />
    </div>
  )
}

function ErrorBox({ message }: { message: string }) {
  return (
    <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
      <AlertCircle className="mt-0.5 size-4 text-destructive" />
      <div>
        <p className="font-medium">No se pudo cargar el reporte</p>
        <p className="text-xs text-muted-foreground">{message}</p>
      </div>
    </div>
  )
}
