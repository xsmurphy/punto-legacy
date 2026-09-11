"use client"

/**
 * Órdenes — cada orden de producción con lo planeado, lo producido y lo fallado.
 *
 * Rendimiento = producido / planeado, solo en completadas. La duración sale de
 * inicio a fin y SOLO de las órdenes que registraron inicio: "producir ahora"
 * crea y completa en un mismo paso, sin inicio, y promediarlas daría un tiempo
 * de producción inventado. La cobertura se declara arriba.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ClipboardList } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type ProductionOrderRow, type ProductionOrdersResponse } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { formatQty } from "@/lib/format-qty"
import { formatDateTime } from "@/lib/format-date"

const STATUS_LABEL: Record<string, string> = {
  draft: "Borrador",
  in_progress: "En curso",
  completed: "Completada",
  cancelled: "Cancelada",
}

/** Minutos a texto corto: "45 min", "2 h 10 min". */
export function formatMinutes(min: number | null | undefined): string {
  if (min === null || min === undefined || !Number.isFinite(min)) return "—"
  if (min < 60) return `${Math.round(min)} min`
  const h = Math.floor(min / 60)
  const m = Math.round(min % 60)
  return m === 0 ? `${h} h` : `${h} h ${m} min`
}

export function ProductionOrdersTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(() => ({ ...rangeToBackend(range), params: { view: "orders" } }), [range])
  const { data, isLoading, error } = useReport<ProductionOrdersResponse>("production", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])
  const totals = data?.totals
  const coverage = data?.coverage

  const columns = React.useMemo<ColumnDef<ProductionOrderRow>[]>(
    () => [
      {
        accessorKey: "docNumber",
        header: "Nº",
        cell: ({ getValue }) => <span className="tabular-nums">{String(getValue() || "—")}</span>,
        meta: { label: "Nº" },
      },
      {
        accessorKey: "name",
        header: "Producto",
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span className="font-medium">{row.original.name || "(sin nombre)"}</span>
            {row.original.fromBatch && <span className="text-xs text-muted-foreground">De un lote</span>}
          </div>
        ),
        meta: { label: "Producto" },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ getValue }) => <Badge variant="outline">{STATUS_LABEL[String(getValue())] ?? String(getValue())}</Badge>,
        meta: { label: "Estado" },
      },
      {
        accessorKey: "qtyPlanned",
        header: "Planeado",
        cell: ({ getValue }) => <span className="tabular-nums">{formatQty(Number(getValue()), bootstrap)}</span>,
        meta: { label: "Planeado", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "qtyProduced",
        header: "Producido",
        cell: ({ getValue }) => {
          const v = getValue() as number | null
          return <span className="tabular-nums">{v === null ? "—" : formatQty(v, bootstrap)}</span>
        },
        meta: { label: "Producido", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "qtyWaste",
        header: "Fallado",
        cell: ({ getValue }) => <span className="tabular-nums">{formatQty(Number(getValue()), bootstrap)}</span>,
        meta: { label: "Fallado", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "yieldPct",
        header: "Rendimiento",
        cell: ({ getValue }) => {
          const v = getValue() as number | null
          return <span className="tabular-nums">{v === null ? "—" : `${v.toFixed(1)}%`}</span>
        },
        meta: { label: "Rendimiento", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "unitCogs",
        header: "Costo unitario",
        cell: ({ getValue }) => {
          const v = getValue() as number | null
          return <span className="tabular-nums">{v === null ? "—" : formatMoney(v, bootstrap)}</span>
        },
        meta: { label: "Costo unitario", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "durationMin",
        header: "Duración",
        cell: ({ getValue }) => <span className="tabular-nums text-muted-foreground">{formatMinutes(getValue() as number | null)}</span>,
        meta: { label: "Duración", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "createdAt",
        header: "Fecha",
        cell: ({ getValue }) => <span className="tabular-nums">{formatDateTime(String(getValue()), "d MMM yyyy")}</span>,
        meta: { label: "Fecha" },
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

  const sinTiempo = coverage ? coverage.completed - coverage.timed : 0

  return (
    <div className="flex flex-col gap-6">
      {!isLoading && totals && totals.orders > 0 && (
        <div className="flex flex-col gap-2">
          <StatsRow>
            <StatTile label="Órdenes" value={formatInt(totals.orders, bootstrap)} />
            <StatTile label="Completadas" value={formatInt(totals.completed, bootstrap)} />
            <StatTile
              label="Canceladas"
              value={formatInt(totals.cancelled, bootstrap)}
              tone={totals.cancelled > 0 ? "negative" : "neutral"}
            />
            <StatTile
              label="Rendimiento"
              value={totals.yieldPct === null ? "—" : `${totals.yieldPct.toFixed(1)}%`}
              emphasis
            />
            <StatTile label="Duración promedio" value={formatMinutes(totals.avgDurationMin)} />
          </StatsRow>
          {sinTiempo > 0 && coverage && (
            <p className="text-xs text-muted-foreground">
              La duración promedio sale de {coverage.timed} de {coverage.completed} órdenes completadas: las otras{" "}
              {sinTiempo} se produjeron en un solo paso, sin registrar el inicio.
            </p>
          )}
        </div>
      )}

      <DataTable
        tableId="report-production-orders"
        data={rows}
        columns={columns}
        getRowId={(r) => r.orderId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por producto, número…"
        exportFileName="ordenes_produccion"
        emptyMessage={<EmptyState icon={ClipboardList} title="Sin órdenes de producción" description="No se crearon órdenes en este período." />}
      />
    </div>
  )
}
