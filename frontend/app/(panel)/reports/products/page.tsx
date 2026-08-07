"use client"

/**
 * Reporte de Productos y Servicios (view=general).
 *
 * Listado ranking de items vendidos en el período: unidades, total facturado,
 * descuento, impuestos, COGS, comisión y utilidad calculada. El backend
 * (/v1/reports/products) ya devuelve los nombres resueltos + metadata
 * (sku/brand/category) por item.
 *
 * Vistas `month`/`combos` quedan como follow-up — este slice cubre el general,
 * que es el caso 90% de uso (ranking diario/semanal/mensual).
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Package, Trash2 } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type ProductRow,
  type ProductsReportResponse,
} from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

export default function ProductsReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "general" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<ProductsReportResponse>(
    "products",
    opts,
  )

  const rows = data?.rows ?? []

  const columns = React.useMemo<ColumnDef<ProductRow>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Artículo",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <div className="flex items-center gap-1.5 font-medium">
                <span className="truncate">{r.name || "(sin nombre)"}</span>
                {r.deleted && (
                  <Badge variant="secondary" className="gap-1 text-[10px]">
                    <Trash2 className="size-3" /> eliminado
                  </Badge>
                )}
              </div>
              {(r.sku || r.brand || r.category) && (
                <span className="text-[10px] text-muted-foreground tabular-nums">
                  {[r.sku, r.brand, r.category].filter(Boolean).join(" · ")}
                </span>
              )}
            </div>
          )
        },
        meta: { label: "Artículo" },
      },
      {
        accessorKey: "usold",
        header: "Vendidos",
        cell: ({ getValue }) => (
          <span className="tabular-nums">
            {formatInt(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Vendidos", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "total",
        header: "Total",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Total", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "discount",
        header: "Descuento",
        cell: ({ getValue }) => {
          const v = Number(getValue()) || 0
          if (v <= 0) return <span className="text-muted-foreground">—</span>
          return (
            <span className="tabular-nums text-amber-600">
              {formatMoney(v, bootstrap)}
            </span>
          )
        },
        meta: { label: "Descuento", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "cogs",
        header: "Costo",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Costo (COGS)", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "utility",
        header: "Utilidad",
        cell: ({ row }) => {
          const r = row.original
          // El backend calcula utility = (total - cogs) - comission antes de devolver.
          // Fallback al cálculo cliente si no llegó (versión de service antigua).
          const u =
            typeof r.utility === "number"
              ? r.utility
              : r.total - r.cogs - r.comission
          if (u === 0) {
            return <span className="text-muted-foreground tabular-nums">—</span>
          }
          return (
            <span
              className={
                u > 0
                  ? "tabular-nums text-emerald-600"
                  : "tabular-nums text-destructive"
              }
            >
              {formatMoney(u, bootstrap)}
            </span>
          )
        },
        meta: { label: "Utilidad", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

  // Totalizadores agregados — el legacy los muestra en el footer.
  const totals = React.useMemo(() => {
    let total = 0
    let cogs = 0
    let usold = 0
    let utility = 0
    rows.forEach((r) => {
      total += r.total
      cogs += r.cogs
      usold += r.usold
      utility +=
        typeof r.utility === "number" ? r.utility : r.total - r.cogs - r.comission
    })
    return { total, cogs, usold, utility }
  }, [rows])

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Productos y servicios</h1>
          <p className="text-sm text-muted-foreground">
            Ranking de artículos vendidos en el período. Ordená por cualquier
            columna; los eliminados aparecen marcados.
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

      {!isLoading && rows.length > 0 && (
        <StatsRow>
          <StatTile label="Artículos" value={rows.length.toString()} />
          <StatTile
            label="Unidades vendidas"
            value={formatInt(totals.usold, bootstrap)}
          />
          <StatTile
            label="Total facturado"
            value={formatMoney(totals.total, bootstrap)}
            emphasis
          />
          <StatTile
            label="Costo total"
            value={formatMoney(totals.cogs, bootstrap)}
          />
          <StatTile
            label="Utilidad"
            value={formatMoney(totals.utility, bootstrap)}
            emphasis
          />
        </StatsRow>
      )}

      <DataTable
        tableId="report-products"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder="Buscar por nombre, SKU, marca o categoría…"
        exportFileName="productos"
        emptyMessage={
          <div className="flex flex-col items-center gap-2 text-muted-foreground">
            <Package className="size-8 opacity-30" />
            <p>No se vendieron artículos en este período.</p>
            <p className="text-xs">Ajustá el rango de fechas y volvé a consultar.</p>
          </div>
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
