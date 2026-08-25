"use client"

/**
 * Reporte de Análisis de Clientes — espejo de panel/reports/customers.html.
 *
 * Backend: GET /v1/reports/customers?from=&to= → array de rows con
 * customerId, name, ruc, ci, phone, email, loyalty, usold, grossTotal,
 * discount, count, tags[].
 *
 * El reporte muestra ranking por consumo + permite ver loyalty y tags.
 * Columnas opcionales (Email, Teléfono, CI) hidden-by-default vía toggle.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Users } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type CustomerRow } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { formatPhone } from "@/lib/phone"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

export default function CustomersReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  // El endpoint devuelve `{ rows: CustomerRow[] }`. El comentario anterior
  // estaba mal y la página tiraba TypeError "rows.forEach is not a function"
  // porque `data` era un objeto, no un array.
  const { data, isLoading, error } = useReport<{ rows: CustomerRow[] }>(
    "customers",
    opts,
  )
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  const totals = React.useMemo(() => {
    let units = 0
    let total = 0
    let count = 0
    rows.forEach((r) => {
      units += r.usold
      total += r.grossTotal
      count += r.count
    })
    return { units, total, count }
  }, [rows])

  const columns = React.useMemo<ColumnDef<CustomerRow>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Cliente",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span className="font-medium truncate">
                {r.name || "(sin nombre)"}
              </span>
              {r.secondName && (
                <span className="text-[10px] text-muted-foreground truncate">
                  {r.secondName}
                </span>
              )}
            </div>
          )
        },
        meta: { label: "Cliente" },
      },
      {
        accessorKey: "ruc",
        header: "RUC",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? (
            <span className="tabular-nums text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "RUC", className: "tabular-nums" },
      },
      {
        accessorKey: "ci",
        header: "CI",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? (
            <span className="tabular-nums text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "CI", className: "tabular-nums" },
      },
      {
        accessorKey: "phone",
        header: "Teléfono",
        cell: ({ getValue }) => {
          // formatPhone: la BD guarda E.164 sin '+'; la columna lo pintaba crudo.
          const v = formatPhone(getValue() as string)
          return v ? (
            <span className="tabular-nums text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Teléfono", className: "tabular-nums" },
      },
      {
        accessorKey: "email",
        header: "Email",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? (
            <span className="text-muted-foreground truncate">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Email" },
      },
      {
        accessorKey: "count",
        header: "Compras",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{Number(getValue()) || 0}</span>
        ),
        meta: { label: "Compras", className: "tabular-nums text-right" },
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
        accessorKey: "grossTotal",
        header: "Total gastado",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Total gastado", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "loyalty",
        header: "Loyalty",
        cell: ({ getValue }) => {
          const v = Number(getValue()) || 0
          if (v <= 0) return <span className="text-muted-foreground">—</span>
          return (
            <span className="tabular-nums text-emerald-600">
              {formatInt(v, bootstrap)}
            </span>
          )
        },
        meta: { label: "Loyalty", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "tags",
        header: "Etiquetas",
        cell: ({ row }) => {
          const tags = row.original.tags ?? []
          if (!tags.length) return <span className="opacity-40">—</span>
          return (
            <div className="flex flex-wrap gap-1">
              {tags.slice(0, 3).map((t, i) => (
                <Badge key={i} variant="outline" className="text-[10px]">
                  {t}
                </Badge>
              ))}
              {tags.length > 3 && (
                <span className="text-[10px] text-muted-foreground">
                  +{tags.length - 3}
                </span>
              )}
            </div>
          )
        },
        meta: { label: "Etiquetas" },
      },
    ],
    [bootstrap],
  )

  // Columnas opcionales hidden por default — el legacy las muestra todas pero
  // saturan en pantalla angosta. Toggle del DataTable las activa cuando importa.
  const initialColumnVisibility = React.useMemo(
    () => ({ ci: false, phone: false, email: false, tags: false }),
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Análisis de Clientes</h1>
          <p className="text-sm text-muted-foreground">
            Ranking por consumo en el período. Las columnas CI, Teléfono, Email y
            Etiquetas se activan desde el toggle <strong>Columnas</strong>.
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
          <StatTile label="Clientes" value={rows.length.toString()} />
          <StatTile label="Compras" value={formatInt(totals.count, bootstrap)} />
          <StatTile label="Unidades vendidas" value={formatInt(totals.units, bootstrap)} />
          <StatTile
            label="Total facturado"
            value={formatMoney(totals.total, bootstrap)}
            emphasis
          />
        </StatsRow>
      )}

      <DataTable
        tableId="report-customers"
        data={rows}
        columns={columns}
        initialColumnVisibility={initialColumnVisibility}
        getRowId={(r) => r.customerId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por nombre, RUC, CI, teléfono…"
        exportFileName="analisis_clientes"
        emptyMessage={
          <EmptyState
            icon={Users}
            title="Sin clientes con ventas"
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
