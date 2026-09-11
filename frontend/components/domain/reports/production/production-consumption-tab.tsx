"use client"

/**
 * Consumos — cuánto insumo se usó para producir en el período.
 *
 * Es el consumo REAL que se descontó del stock, no el de receta. El teórico no
 * se guarda cuando el operador ajusta lo que usó, así que el desvío contra
 * receta no se puede mostrar todavía (F2 de context/76, requiere F0). La
 * pantalla lo dice, junto con el otro límite: los insumos sin control de stock
 * (agua, sal) no dejan movimiento y no aparecen.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, Info, Package } from "lucide-react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { RankingBarChart } from "@/components/domain/reports/ranking-bar-chart"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type ProductionConsumptionResponse,
  type ProductionConsumptionRow,
} from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { formatQty } from "@/lib/format-qty"

export function ProductionConsumptionTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(() => ({ ...rangeToBackend(range), params: { view: "consumption" } }), [range])
  const { data, isLoading, error } = useReport<ProductionConsumptionResponse>("production", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])
  const money = React.useCallback((v: number) => formatMoney(v, bootstrap), [bootstrap])

  const columns = React.useMemo<ColumnDef<ProductionConsumptionRow>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Insumo",
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span className="font-medium">{row.original.name || "(sin nombre)"}</span>
            {row.original.sku && <span className="text-xs text-muted-foreground">{row.original.sku}</span>}
          </div>
        ),
        meta: { label: "Insumo" },
      },
      {
        accessorKey: "qty",
        header: "Cantidad consumida",
        cell: ({ getValue }) => <span className="tabular-nums">{formatQty(Number(getValue()), bootstrap)}</span>,
        meta: { label: "Cantidad consumida", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "cost",
        header: "Costo",
        cell: ({ getValue }) => <span className="tabular-nums font-medium">{formatMoney(Number(getValue()), bootstrap)}</span>,
        meta: { label: "Costo", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "moves",
        header: "Movimientos",
        cell: ({ getValue }) => <span className="tabular-nums text-muted-foreground">{formatInt(Number(getValue()), bootstrap)}</span>,
        meta: { label: "Movimientos", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

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

  return (
    <div className="flex flex-col gap-6">
      <Card variant="soft">
        <CardContent className="flex gap-3">
          <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">
            Es lo que efectivamente se descontó del stock al producir. Todavía no se puede comparar contra lo que
            indicaba la receta, y los insumos que no llevan control de stock no aparecen.
          </p>
        </CardContent>
      </Card>

      {!isLoading && rows.length > 0 && (
        <>
          <StatsRow>
            <StatTile label="Insumos consumidos" value={formatInt(data?.totals.items ?? 0, bootstrap)} />
            <StatTile label="Costo de lo consumido" value={money(data?.totals.cost ?? 0)} emphasis />
          </StatsRow>
          <Card>
            <CardHeader>
              <CardTitle className="text-base font-semibold tracking-tight">Insumos de mayor costo</CardTitle>
              <CardDescription className="text-xs">Dónde se va la plata de la producción.</CardDescription>
            </CardHeader>
            <CardContent>
              <RankingBarChart
                data={rows.map((r) => ({ label: r.name || "(sin nombre)", value: r.cost }))}
                valueLabel="Costo consumido"
                formatValue={money}
              />
            </CardContent>
          </Card>
        </>
      )}

      <DataTable
        tableId="report-production-consumption"
        data={rows}
        columns={columns}
        getRowId={(r) => r.itemId}
        isLoading={isLoading}
        searchPlaceholder="Buscar insumo…"
        exportFileName="consumo_insumos"
        emptyMessage={<EmptyState icon={Package} title="Sin consumos en el período" description="No hubo producción que descontara insumos en estas fechas." />}
      />
    </div>
  )
}
