"use client"

/**
 * Detalle de Equipo — la tabla histórica del reporte, movida tal cual desde
 * `app/(panel)/reports/users/page.tsx` cuando la página pasó a tener pestañas.
 *
 * Backend: `GET /v1/reports/users?from=&to=` (sin `view`)
 * → array de filas: { userId, name, usold, total, comission, discount, count }
 *
 * Incluye usuarios sin actividad (total=0): es la única vista que los muestra,
 * y por eso se conserva aunque el Dashboard cubra los mismos números — sirve
 * para ver quién NO vendió.
 *
 * `count` acá son LÍNEAS de venta, no transacciones. El Dashboard usa tickets
 * distintos; los dos números son correctos y miden cosas distintas.
 *
 * La atribución es COALESCE(vendedor de la línea, operador de la venta): ver el
 * comentario en api/lib/Reports/UsersService.php. La comisión, en cambio, sigue
 * siendo la que se congeló por línea al vender — no se recalcula acá.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, Users } from "lucide-react"

import { DataTable } from "@/components/data-table/data-table"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type UserReportRow } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { StatsRow, StatTile } from "@/components/stat-tile"

export function UsersDetailTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
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

  const columns = React.useMemo<ColumnDef<UserReportRow, unknown>[]>(
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
        header: "Líneas vendidas",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatInt(Number(getValue()) || 0, bootstrap)}</span>
        ),
        meta: { label: "Líneas vendidas", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "usold",
        header: "Unidades",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatInt(Number(getValue()) || 0, bootstrap)}
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
          <StatTile label="Usuarios" value={formatInt(rows.length, bootstrap)} />
          {/* "Líneas vendidas" y no "Transacciones": este número cuenta líneas
              de venta. El de transacciones vive en el Dashboard, con el
              distinct. Llamarlos igual era lo que hacía parecer que un tab
              contradecía al otro. */}
          <StatTile label="Líneas vendidas" value={formatInt(totals.count, bootstrap)} />
          <StatTile label="Unidades vendidas" value={formatInt(totals.usold, bootstrap)} />
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
        exportFileName="equipo"
        emptyMessage={
          <EmptyState
            icon={Users}
            title="Sin datos del equipo"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
  )
}
