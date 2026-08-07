"use client"

/**
 * Reporte Niveles de Stock — espejo de panel/reports/stock.html.
 *
 * Backend: GET /v1/reports/stock
 * → { needsOutlet: bool, rows: [{ itemId, name, sku, onHand, cogs, depots, principal }] }
 *
 * Reporte SNAPSHOT — NO date-scoped. Muestra el stock actual por producto.
 * Si el backend devuelve needsOutlet=true, la sucursal no está seleccionada
 * y se muestra un mensaje informativo.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Layers } from "lucide-react"

import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type StockRow, type StockReportResponse } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

export default function StockReportPage() {
  const { data: bootstrap } = useBootstrap()

  const { data, isLoading, error } = useReport<StockReportResponse>("stock", {})
  const rows = React.useMemo(() => data?.rows ?? [], [data])
  const needsOutlet = data?.needsOutlet === true

  const totals = React.useMemo(() => {
    let onHand = 0
    let cogs = 0
    rows.forEach((r) => {
      onHand += r.onHand
      cogs += r.cogs
    })
    return { onHand, cogs }
  }, [rows])

  const columns = React.useMemo<ColumnDef<StockRow>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Producto",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span className="font-medium">{r.name || "(sin nombre)"}</span>
              {r.sku && (
                <span className="text-[10px] text-muted-foreground">{r.sku}</span>
              )}
            </div>
          )
        },
        meta: { label: "Producto" },
      },
      {
        accessorKey: "principal",
        header: "Stock principal",
        cell: ({ row }) => {
          const p = row.original.principal
          return (
            <div className="flex flex-col tabular-nums">
              <span className="font-medium">{formatInt(p.count, bootstrap)}</span>
              {p.min > 0 && (
                <span className="text-[10px] text-muted-foreground">Mín: {p.min}</span>
              )}
            </div>
          )
        },
        meta: { label: "Stock principal", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "onHand",
        header: "Total en stock",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatInt(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Total en stock", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "cogs",
        header: "Valor al costo",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Valor al costo", className: "tabular-nums text-right" },
      },
      {
        id: "depots",
        header: "Depósitos",
        accessorFn: (r) => r.depots.length,
        cell: ({ row }) => {
          const depots = row.original.depots.filter((d) => d.count > 0)
          if (!depots.length) return <span className="opacity-40">—</span>
          return (
            <div className="flex flex-col gap-0.5">
              {depots.map((d) => (
                <span key={d.locationId} className="text-xs text-muted-foreground">
                  {d.locationName}: {formatInt(d.count, bootstrap)}
                </span>
              ))}
            </div>
          )
        },
        meta: { label: "Depósitos" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Niveles de Stock</h1>
          <p className="text-sm text-muted-foreground">
            Snapshot del stock actual por producto en la sucursal seleccionada.
          </p>
        </div>
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {needsOutlet && !isLoading && (
        <div className="flex items-start gap-3 rounded-md border bg-muted/30 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-muted-foreground" />
          <div>
            <p className="font-medium">Seleccioná una sucursal</p>
            <p className="text-xs text-muted-foreground">
              Este reporte requiere una sucursal específica para mostrar los niveles de stock.
            </p>
          </div>
        </div>
      )}

      {!isLoading && !needsOutlet && rows.length > 0 && (
        <StatsRow>
          <StatTile label="Productos" value={formatInt(rows.length, bootstrap)} />
          <StatTile label="Unidades en stock" value={formatInt(totals.onHand, bootstrap)} />
          <StatTile
            label="Valor al costo"
            value={formatMoney(totals.cogs, bootstrap)}
            emphasis
          />
        </StatsRow>
      )}

      <DataTable
        tableId="report-stock"
        data={rows}
        columns={columns}
        getRowId={(r) => r.itemId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por producto, SKU…"
        exportFileName="niveles_stock"
        emptyMessage={
          <EmptyState
            icon={Layers}
            title="Sin productos en stock"
            description="No se encontraron productos con inventario activo en esta sucursal."
          />
        }
      />
    </div>
  )
}

function BackLink() {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href="/reports">
        <ArrowLeft className="size-3.5" />
        Volver a reportes
      </Link>
    </Button>
  )
}
