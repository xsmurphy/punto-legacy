"use client"

/**
 * Componente genérico para reports tipo "ranking simple": una tabla con
 * Nombre / Vendidos / Total y totalizadores arriba. Usado por Categorías,
 * Marcas, Medios de pago — los 3 tienen el mismo shape (CategoryRow,
 * BrandRow, PaymentSummaryRow son casi idénticos: name + count + total).
 *
 * Mantiene boilerplate centralizado: date range, error state, empty state,
 * tabla, paginación, export Excel. Cada caller solo provee título +
 * endpoint name + label de la columna primaria.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft } from "lucide-react"

import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import {
  DateRangePicker,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { RankingBarChart } from "@/components/domain/reports/ranking-bar-chart"

interface RankingRow {
  name: string
  /** Unidades vendidas (usold del legacy) o count agregado. */
  units: number
  /** Total facturado en el período. */
  total: number
}

interface Props<TRawRow> {
  /** Título grande (h1) */
  title: string
  /** Descripción debajo del título */
  description: string
  /** Endpoint sin /v1/reports/ prefix (ej. "categories"). */
  endpoint: string
  /** Extra query params al endpoint (ej. { dataset: "summary" }). */
  endpointParams?: Record<string, string>
  /** Path para el botón "Volver" */
  backHref?: string
  /** Selector de las filas dentro del response del endpoint. */
  selectRows: (data: unknown) => TRawRow[]
  /** Mapper de la row cruda al shape genérico del ranking. */
  toRanking: (raw: TRawRow) => RankingRow & { id: string }
  /** Label de la columna primaria (default "Nombre"). */
  primaryColLabel?: string
  /** Label de la columna de unidades (default "Vendidos"). */
  unitsColLabel?: string
  /** Empty state icon — componente Lucide (no JSX), va al <EmptyState> nuevo. */
  emptyIcon: React.ComponentType<{ className?: string }>
  /** Empty state title (corto). */
  emptyLabel: string
  /** Nombre del archivo a exportar */
  exportFileName: string
  /** Search placeholder */
  searchPlaceholder?: string
  /** tableId para persistencia del column-toggle */
  tableId: string
  /**
   * Embebido dentro de otra página (un tab), no como página propia.
   *
   * Suprime el header —título, volver y selector de fechas— porque esos tres
   * ya los pone quien lo contiene, y el rango viene de afuera para que
   * cambiar de tab no obligue a re-elegir el período. Sin esto, dos
   * `useDateRange()` en la misma pantalla pelearían por el mismo estado
   * compartido.
   */
  embeddedRange?: DateRangeValue
}

export function RankingReportPage<TRawRow>({
  title,
  description,
  endpoint,
  endpointParams,
  backHref = "/reports",
  selectRows,
  toRanking,
  primaryColLabel = "Nombre",
  unitsColLabel = "Vendidos",
  emptyIcon,
  emptyLabel,
  exportFileName,
  searchPlaceholder = "Buscar…",
  tableId,
  embeddedRange,
}: Props<TRawRow>) {
  const { data: bootstrap } = useBootstrap()
  const own = useDateRange()
  const embedded = embeddedRange !== undefined
  const range = embeddedRange ?? own.range
  const setRange = own.setRange
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: endpointParams }),
    [range, endpointParams],
  )

  const { data, isLoading, error } = useReport<unknown>(endpoint, opts)
  const rawRows = data ? selectRows(data) : []
  const rows = React.useMemo(
    () => rawRows.map(toRanking),
    [rawRows, toRanking],
  )

  const totals = React.useMemo(() => {
    let units = 0
    let total = 0
    rows.forEach((r) => {
      units += r.units
      total += r.total
    })
    return { units, total }
  }, [rows])

  const columns = React.useMemo<ColumnDef<RankingRow & { id: string }>[]>(
    () => [
      {
        accessorKey: "name",
        header: primaryColLabel,
        cell: ({ getValue }) => (
          <span className="font-medium">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: primaryColLabel },
      },
      {
        accessorKey: "units",
        header: unitsColLabel,
        cell: ({ getValue }) => (
          <span className="tabular-nums">
            {formatInt(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: unitsColLabel, className: "tabular-nums text-right" },
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
        id: "share",
        header: "% del total",
        cell: ({ row }) => {
          const pct = totals.total > 0 ? (row.original.total / totals.total) * 100 : 0
          return (
            <span className="tabular-nums text-muted-foreground">
              {pct.toFixed(1)}%
            </span>
          )
        },
        meta: { label: "% del total", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap, primaryColLabel, unitsColLabel, totals.total],
  )

  return (
    <div className="flex flex-col gap-6">
      {!embedded && (
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink href={backHref} />
          <h1 className="text-2xl font-semibold">{title}</h1>
          <p className="text-sm text-muted-foreground">{description}</p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>
      )}

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
          <StatTile label="Items en ranking" value={rows.length.toString()} />
          <StatTile label={unitsColLabel} value={formatInt(totals.units, bootstrap)} />
          <StatTile
            label="Total facturado"
            value={formatMoney(totals.total, bootstrap)}
            emphasis
          />
        </StatsRow>
      )}

      {/* El ranking visual va ARRIBA de la tabla y no adentro de un tab: el
          chart se lee de un vistazo —quién domina, dónde cae la curva— y la
          tabla que sigue es el dato completo. Esconderlo detrás de un tab
          obliga a pedir lo que debería estar a la vista.

          Ordena por FACTURACIÓN, no por unidades: en un ranking de negocio la
          pregunta es de dónde viene la plata, y las dos métricas rara vez
          coinciden (lo más vendido suele ser lo más barato). Las unidades
          siguen en la tabla, que es donde se comparan columna contra columna. */}
      {!isLoading && rows.length > 0 && (
        <RankingBarChart
          data={rows.map((r) => ({ label: r.name, value: r.total }))}
          valueLabel="Total facturado"
          formatValue={(v) => formatMoney(v, bootstrap)}
        />
      )}

      <DataTable
        tableId={tableId}
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder={searchPlaceholder}
        exportFileName={exportFileName}
        emptyMessage={
          <EmptyState
            icon={emptyIcon}
            title={emptyLabel}
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
  )
}

function BackLink({ href }: { href: string }) {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href={href}>
        <ArrowLeft className="size-3.5" />
        Volver a reportes
      </Link>
    </Button>
  )
}
