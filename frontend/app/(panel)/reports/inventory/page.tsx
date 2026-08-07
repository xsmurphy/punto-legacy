"use client"

/**
 * Reporte Movimientos de Inventario — espejo de panel/reports/inventory.html.
 *
 * Backend: GET /v1/reports/inventory?dataset=movements&from=&to=
 * → array de filas: { stockId, stockDate, itemName, itemSKU, outletName,
 *   locationName, userName, source, note, count, onHand, cogs }
 *
 * Reporte date-scoped. Muestra el historial de movimientos de stock
 * (entradas, salidas, ajustes) del período.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, PackageSearch } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type InventoryMovementRow } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"

const SOURCE_LABELS: Record<string, string> = {
  sale: "Venta",
  purchase: "Compra",
  adjustment: "Ajuste",
  production: "Producción",
  transfer: "Transferencia",
  return: "Devolución",
}

function sourceLabel(src: string): string {
  return SOURCE_LABELS[src] ?? src
}


export default function InventoryReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { dataset: "movements" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<InventoryMovementRow[]>("inventory", opts)
  const rows = React.useMemo(() => data ?? [], [data])

  const columns = React.useMemo<ColumnDef<InventoryMovementRow>[]>(
    () => [
      {
        accessorKey: "stockDate",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-sm">{formatDateTime((getValue() as string) ?? "", "d MMM yyyy HH:mm")}</span>
        ),
        meta: { label: "Fecha", className: "tabular-nums" },
      },
      {
        accessorKey: "itemName",
        header: "Producto",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span className="font-medium">{r.itemName || "(sin nombre)"}</span>
              {r.itemSKU && (
                <span className="text-[10px] text-muted-foreground">{r.itemSKU}</span>
              )}
            </div>
          )
        },
        meta: { label: "Producto" },
      },
      {
        accessorKey: "source",
        header: "Origen",
        cell: ({ getValue }) => (
          <Badge variant="secondary" className="text-[10px]">
            {sourceLabel((getValue() as string) ?? "")}
          </Badge>
        ),
        meta: { label: "Origen" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "locationName",
        header: "Depósito",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "Principal"}</span>
        ),
        meta: { label: "Depósito" },
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Usuario" },
      },
      {
        accessorKey: "count",
        header: "Movimiento",
        cell: ({ getValue }) => {
          const v = Number(getValue()) || 0
          // formatInt y no toLocaleString(): sin locale explícito, el server
          // usa el default de Node y el browser el del usuario ("1,234" vs
          // "1.234"). Texto distinto entre render e hidratación = React #418,
          // que seguía disparando en /reports/inventory después del fix
          // be56f54e (capturado en producción por GlitchTip).
          return (
            <span className={`tabular-nums font-medium ${v < 0 ? "text-destructive" : ""}`}>
              {v > 0 ? "+" : ""}
              {formatInt(v, bootstrap)}
            </span>
          )
        },
        meta: { label: "Movimiento", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "onHand",
        header: "En stock",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatInt(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "En stock", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "cogs",
        header: "Costo",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Costo", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "note",
        header: "Nota",
        cell: ({ getValue }) => {
          const v = (getValue() as string) ?? ""
          return v ? <span className="text-xs truncate">{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Nota" },
      },
    ],
    [bootstrap],
  )

  const initialColumnVisibility = React.useMemo(
    () => ({ note: false, locationName: false }),
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Movimientos de Inventario</h1>
          <p className="text-sm text-muted-foreground">
            Historial general de entradas, salidas y ajustes de stock del período.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
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

      <DataTable
        tableId="report-inventory"
        data={rows}
        columns={columns}
        initialColumnVisibility={initialColumnVisibility}
        getRowId={(r) => r.stockId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por producto, usuario, origen…"
        exportFileName="movimientos_inventario"
        emptyMessage={
          <EmptyState
            icon={PackageSearch}
            title="Sin movimientos de inventario"
            description="Ajustá el rango de fechas y volvé a consultar."
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
