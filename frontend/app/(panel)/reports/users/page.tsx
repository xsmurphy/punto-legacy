"use client"

/**
 * Reporte Staff y Usuarios — espejo de panel/reports/users.html.
 *
 * Backend: GET /v1/reports/users?from=&to=
 * → array de filas: { userId, name, usold, total, comission, discount, count }
 *
 * El reporte es date-scoped (por período). Muestra ventas, comisiones y
 * descuentos por usuario/recurso. Incluye usuarios sin actividad (total=0).
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Users } from "lucide-react"

import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type UserReportRow } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

export default function UsersReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<UserReportRow[]>("users", opts)
  const rows = React.useMemo(() => data ?? [], [data])

  const totals = React.useMemo(() => {
    let usold = 0
    let total = 0
    let count = 0
    let discount = 0
    rows.forEach((r) => {
      usold += r.usold
      total += r.total
      count += r.count
      discount += r.discount
    })
    return { usold, total, count, discount }
  }, [rows])

  const columns = React.useMemo<ColumnDef<UserReportRow>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Usuario / Recurso",
        cell: ({ getValue }) => (
          <span className="font-medium">{(getValue() as string) || "(sin nombre)"}</span>
        ),
        meta: { label: "Usuario / Recurso" },
      },
      {
        accessorKey: "count",
        header: "Transacciones",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{(Number(getValue()) || 0).toLocaleString()}</span>
        ),
        meta: { label: "Transacciones", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "usold",
        header: "Unidades",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {(Number(getValue()) || 0).toLocaleString()}
          </span>
        ),
        meta: { label: "Unidades", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "total",
        header: "Ventas",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Ventas", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "discount",
        header: "Descuentos",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Descuentos", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "comission",
        header: "Comisión",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Comisión", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Staff y Usuarios</h1>
          <p className="text-sm text-muted-foreground">
            Ventas, unidades y comisiones por usuario/recurso del período.
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
          <StatTile label="Usuarios" value={rows.length.toLocaleString()} />
          <StatTile label="Transacciones" value={totals.count.toLocaleString()} />
          <StatTile label="Unidades vendidas" value={totals.usold.toLocaleString()} />
          <StatTile
            label="Total ventas"
            value={formatMoney(totals.total, bootstrap)}
            emphasis
          />
        </StatsRow>
      )}

      <DataTable
        tableId="report-users"
        data={rows}
        columns={columns}
        getRowId={(r) => r.userId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por usuario…"
        exportFileName="staff_usuarios"
        emptyMessage={
          <EmptyState
            icon={Users}
            title="Sin datos de usuarios"
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
