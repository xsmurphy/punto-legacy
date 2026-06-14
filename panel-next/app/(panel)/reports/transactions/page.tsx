"use client"

/**
 * Reporte de Pagos y Transacciones (view=detail).
 *
 * Listado de ventas del período con fecha, documento, cliente, total, métodos
 * de pago y estado (completa / pendiente de pago). El backend
 * (/v1/reports/transactions?view=detail) ya devuelve los IDs resueltos a
 * nombres + componentes calculados (subtotal/tax/discount/total).
 *
 * Acciones (deletePayment / deleteQuote / edición fiscal) las dejamos para
 * slices futuros — éste cubre el caso 90% de uso: ver y exportar.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Receipt } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type TransactionRow,
  type TransactionsReportResponse,
} from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { EmptyState } from "@/components/empty-state"

export default function TransactionsReportPage() {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "detail" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<TransactionsReportResponse>(
    "transactions",
    opts,
  )

  const rows = data?.rows ?? []

  const columns = React.useMemo<ColumnDef<TransactionRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{niceDateTime((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Fecha", className: "tabular-nums" },
      },
      {
        accessorKey: "docNo",
        header: "Documento",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span className="font-medium tabular-nums">{r.docNo || "—"}</span>
              {r.authNo ? (
                <span className="text-[10px] text-muted-foreground tabular-nums">
                  Timbrado {r.authNo}
                </span>
              ) : null}
            </div>
          )
        },
        meta: { label: "Documento" },
      },
      {
        accessorKey: "customerName",
        header: "Cliente",
        cell: ({ row }) => {
          const r = row.original
          if (!r.customerName) {
            return <span className="text-muted-foreground">Consumidor final</span>
          }
          return (
            <div className="flex flex-col">
              <span className="font-medium truncate">{r.customerName}</span>
              {r.customerTIN ? (
                <span className="text-[10px] text-muted-foreground tabular-nums">
                  {r.customerTIN}
                </span>
              ) : null}
            </div>
          )
        },
        meta: { label: "Cliente" },
      },
      {
        accessorKey: "userName",
        header: "Cajero",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground truncate">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "Cajero" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        id: "payments",
        header: "Pago",
        cell: ({ row }) => {
          const r = row.original
          const ps = r.payments ?? []
          if (!ps.length) return <span className="text-muted-foreground">—</span>
          return (
            <div className="flex flex-wrap gap-1">
              {ps.slice(0, 2).map((p, i) => (
                <Badge key={i} variant="outline" className="text-[10px]">
                  {p}
                </Badge>
              ))}
              {ps.length > 2 && (
                <span className="text-[10px] text-muted-foreground">+{ps.length - 2}</span>
              )}
            </div>
          )
        },
        meta: { label: "Pago" },
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
        id: "status",
        header: "Estado",
        cell: ({ row }) => {
          const r = row.original
          // transactionComplete = 1 (pagado), 0 (pendiente).
          return r.transactionComplete === 1 ? (
            <Badge variant="default">Pagada</Badge>
          ) : (
            <Badge variant="secondary">Pendiente</Badge>
          )
        },
        meta: { label: "Estado", className: "w-24" },
      },
    ],
    [bootstrap],
  )

  const totalAmount = rows.reduce((sum, r) => sum + (Number(r.total) || 0), 0)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Transacciones</h1>
          <p className="text-sm text-muted-foreground">
            Ventas del período (incluye facturas, tickets y notas de crédito).
            Cap de 5.000 filas server-side — afiná el rango si necesitás más historial.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudieron cargar las transacciones</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {/* Totalizador horizontal — el legacy muestra Total al final. Lo ponemos
          arriba para que el user lo vea sin scrollear hasta el footer. */}
      {!isLoading && rows.length > 0 && (
        <div className="flex flex-wrap gap-6 border-y py-3 text-sm">
          <Stat label="Operaciones" value={rows.length.toString()} />
          <Stat
            label="Total"
            value={`${bootstrap?.currency ?? ""} ${formatMoney(totalAmount, bootstrap)}`}
            emphasis
          />
        </div>
      )}

      <DataTable
        tableId="report-transactions"
        data={rows}
        columns={columns}
        getRowId={(r) => r.transactionId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por documento, cliente, cajero…"
        exportFileName="transacciones"
        emptyMessage={
          <EmptyState
            icon={Receipt}
            title="Sin transacciones"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
  )
}

function Stat({
  label,
  value,
  emphasis,
}: {
  label: string
  value: string
  emphasis?: boolean
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
        {label}
      </span>
      <span
        className={
          emphasis
            ? "text-base font-semibold tabular-nums"
            : "text-sm font-medium tabular-nums"
        }
      >
        {value}
      </span>
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

function niceDateTime(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(undefined, {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  })
}
