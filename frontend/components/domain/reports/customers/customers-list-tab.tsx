"use client"

/**
 * Tab "Listado" del reporte de Análisis de Clientes.
 *
 * Una fila por cliente con actividad en el período + sus métricas. Va en
 * `<DataTable>` (convención de todo listado largo: search, sort, export XLSX,
 * column-toggle persistido).
 *
 * COMPOSICIÓN — este tab es la excepción al rediseño de escritorio del
 * 2026-08-31: no se partió en columnas a propósito. Su contenido es UNA tabla
 * de quince columnas, que a ancho completo ya usa toda la pantalla (de hecho
 * scrollea en horizontal); meterla en 2/3 para poner algo al lado le sacaría
 * las columnas que el owner pidió ver de entrada. Los KPIs de arriba ya van en
 * fila. Si en algún momento se le suma un gráfico, ahí sí corresponde la fila
 * de dos columnas.
 *
 * Los encabezados del identificador fiscal y del documento personal salen de
 * `resolveTaxIdLabel` / `resolvePersonalIdLabel`: escribir "RUC" o "Cédula"
 * literales afirmaría Paraguay en un panel que ya es multi-país.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Users } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"
import { formatInt, formatMoney } from "@/lib/format"
import { formatPhone } from "@/lib/phone"
import { resolvePersonalIdLabel, resolveTaxIdLabel } from "@/lib/tenant-locale"
import type { CustomerRow } from "@/hooks/use-reports"
import type { Bootstrap } from "@/lib/types/bootstrap"

/** Celda de texto opcional — un guion tenue cuando el dato no está cargado. */
function OptionalText({
  value,
  className,
}: {
  value: string | null | undefined
  className?: string
}) {
  if (!value) return <span className="opacity-40">—</span>
  return (
    <span className={className ?? "truncate text-muted-foreground"}>{value}</span>
  )
}

export function CustomersListTab({
  rows,
  isLoading,
  bootstrap,
}: {
  rows: CustomerRow[]
  isLoading: boolean
  bootstrap: Bootstrap | undefined
}) {
  const taxIdLabel = resolveTaxIdLabel(bootstrap)
  const personalIdLabel = resolvePersonalIdLabel(bootstrap)

  const totals = React.useMemo(() => {
    let units = 0
    let total = 0
    let count = 0
    for (const r of rows) {
      units += r.usold
      total += r.grossTotal
      count += r.count
    }
    return { units, total, count }
  }, [rows])

  const columns = React.useMemo<ColumnDef<CustomerRow>[]>(
    () => [
      {
        accessorKey: "displayName",
        header: "Nombre",
        cell: ({ row }) => (
          <span className="truncate font-medium">
            {row.original.displayName || "(sin nombre)"}
          </span>
        ),
        meta: { label: "Nombre" },
      },
      {
        accessorKey: "name",
        header: "Razón social",
        cell: ({ getValue }) => <OptionalText value={getValue() as string} />,
        meta: { label: "Razón social" },
      },
      {
        accessorKey: "ruc",
        header: taxIdLabel,
        cell: ({ getValue }) => (
          <OptionalText
            value={getValue() as string}
            className="tabular-nums text-muted-foreground"
          />
        ),
        meta: { label: taxIdLabel, className: "tabular-nums" },
      },
      {
        accessorKey: "ci",
        header: personalIdLabel,
        cell: ({ getValue }) => (
          <OptionalText
            value={getValue() as string}
            className="tabular-nums text-muted-foreground"
          />
        ),
        meta: { label: personalIdLabel, className: "tabular-nums" },
      },
      {
        accessorKey: "phone",
        header: "Teléfono",
        // La BD guarda E.164 sin '+'; sin el helper la columna sale cruda.
        cell: ({ getValue }) => (
          <OptionalText
            value={formatPhone(getValue() as string)}
            className="tabular-nums text-muted-foreground"
          />
        ),
        meta: { label: "Teléfono", className: "tabular-nums" },
      },
      {
        accessorKey: "email",
        header: "Email",
        cell: ({ getValue }) => <OptionalText value={getValue() as string} />,
        meta: { label: "Email" },
      },
      {
        accessorKey: "address",
        header: "Dirección",
        cell: ({ getValue }) => <OptionalText value={getValue() as string} />,
        meta: { label: "Dirección" },
      },
      {
        accessorKey: "location",
        header: "Localidad",
        cell: ({ getValue }) => <OptionalText value={getValue() as string} />,
        meta: { label: "Localidad" },
      },
      {
        accessorKey: "city",
        header: "Ciudad",
        cell: ({ getValue }) => <OptionalText value={getValue() as string} />,
        meta: { label: "Ciudad" },
      },
      {
        accessorKey: "tags",
        header: "Etiquetas",
        cell: ({ row }) => {
          const tags = row.original.tags ?? []
          if (!tags.length) return <span className="opacity-40">—</span>
          return (
            <div className="flex flex-wrap gap-1">
              {tags.slice(0, 3).map((t) => (
                <Badge key={t} variant="outline">
                  {t}
                </Badge>
              ))}
              {tags.length > 3 && (
                <span className="text-sm text-muted-foreground">
                  +{tags.length - 3}
                </span>
              )}
            </div>
          )
        },
        meta: { label: "Etiquetas" },
      },
      {
        accessorKey: "loyalty",
        header: "Loyalty",
        cell: ({ getValue }) => {
          const v = Number(getValue()) || 0
          if (v <= 0) return <span className="opacity-40">—</span>
          // text-emerald-600 = "saldo positivo a favor del cliente"; excepción
          // documentada en context/20 §3 (no hay token semántico de éxito).
          return (
            <span className="tabular-nums text-emerald-600">
              {formatInt(v, bootstrap)}
            </span>
          )
        },
        meta: { label: "Loyalty", className: "tabular-nums text-right" },
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
        accessorKey: "avgTicket",
        header: "Gasto promedio",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Gasto promedio", className: "tabular-nums text-right" },
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
    ],
    [bootstrap, personalIdLabel, taxIdLabel],
  )

  // El listado es ancho a propósito: es el "listado completo" y todas las
  // columnas que el owner enumeró arrancan VISIBLES, aunque la tabla scrollee
  // en horizontal. Esconderlas por default obligaría a descubrir el toggle
  // "Columnas" para ver un dato que se pidió explícito.
  //
  // La única oculta es Unidades, que no estaba en el pedido y compite con
  // Compras por el mismo lugar mental. El toggle la trae, y la elección queda
  // persistida por `tableId`.
  const initialColumnVisibility = React.useMemo(() => ({ usold: false }), [])

  return (
    <div className="flex flex-col gap-4">
      <StatsRow>
        <StatTile
          label="Clientes"
          value={formatInt(rows.length, bootstrap)}
          isLoading={isLoading}
        />
        <StatTile
          label="Compras"
          value={formatInt(totals.count, bootstrap)}
          isLoading={isLoading}
        />
        <StatTile
          label="Unidades vendidas"
          value={formatInt(totals.units, bootstrap)}
          isLoading={isLoading}
        />
        <StatTile
          label="Total facturado"
          value={formatMoney(totals.total, bootstrap)}
          isLoading={isLoading}
          emphasis
        />
      </StatsRow>

      <DataTable
        tableId="report-customers"
        data={rows}
        columns={columns}
        initialColumnVisibility={initialColumnVisibility}
        getRowId={(r) => r.customerId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por nombre, identificación, teléfono, ciudad…"
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
