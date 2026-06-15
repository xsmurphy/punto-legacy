"use client"

/**
 * Reporte Pagos ePOS / vPayments — espejo de panel/reports/vpayments.html.
 *
 * Backend: GET /v1/reports/vpayments?from=&to=
 * → { rows: [...], kpi: { sold, deposited, pendingDeposit, count } }
 *
 * Proxy a gateway externo (Bancard/Dinelco). En dev sin credenciales las rows
 * vienen vacías — es el comportamiento esperado (verificación estructural).
 * Reporte date-scoped.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, CreditCard } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type VPaymentRow, type VPaymentsReportResponse } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"

function niceDate(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(undefined, {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
}

export default function VPaymentsReportPage() {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<VPaymentsReportResponse>("vpayments", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])
  const kpi = data?.kpi
  const currency = bootstrap?.currency ?? ""

  const columns = React.useMemo<ColumnDef<VPaymentRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{niceDate((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Fecha", className: "tabular-nums" },
      },
      {
        accessorKey: "authCode",
        header: "Cód. autorización",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-mono text-sm text-muted-foreground">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "Cód. autorización", className: "tabular-nums" },
      },
      {
        accessorKey: "source",
        header: "Canal",
        cell: ({ getValue }) => (
          <Badge variant="outline" className="text-[10px]">
            {(getValue() as string) || "—"}
          </Badge>
        ),
        meta: { label: "Canal" },
      },
      {
        accessorKey: "brand",
        header: "Tarjeta",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Tarjeta" },
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
        accessorKey: "amount",
        header: "Vendido",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Vendido", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "payoutAmount",
        header: "Acreditado",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Acreditado", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "deposited",
        header: "Estado",
        cell: ({ getValue }) => {
          const dep = getValue() as boolean
          return dep ? (
            <Badge variant="default" className="text-[10px]">Acreditado</Badge>
          ) : (
            <Badge variant="secondary" className="text-[10px]">Pendiente</Badge>
          )
        },
        meta: { label: "Estado" },
      },
      {
        accessorKey: "depositedDate",
        header: "Fecha acreditación",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {niceDate((getValue() as string) ?? "")}
          </span>
        ),
        meta: { label: "Fecha acreditación", className: "tabular-nums" },
      },
    ],
    [bootstrap],
  )

  const initialColumnVisibility = React.useMemo(
    () => ({ depositedDate: false, operationNo: false }),
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Pagos ePOS</h1>
          <p className="text-sm text-muted-foreground">
            Pagos electrónicos procesados por Bancard/Dinelco en el período.
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

      {!isLoading && kpi && kpi.count > 0 && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <KpiCard label="Transacciones" value={kpi.count.toLocaleString()} />
          <KpiCard label="Vendido" value={`${currency} ${formatMoney(kpi.sold, bootstrap)}`} />
          <KpiCard label="Acreditado" value={`${currency} ${formatMoney(kpi.deposited, bootstrap)}`} />
          <KpiCard
            label="Pendiente acreditación"
            value={`${currency} ${formatMoney(kpi.pendingDeposit, bootstrap)}`}
          />
        </div>
      )}

      <DataTable
        tableId="report-vpayments"
        data={rows}
        columns={columns}
        initialColumnVisibility={initialColumnVisibility}
        getRowId={(r) => r.eUID || r.authCode || Math.random().toString()}
        isLoading={isLoading}
        searchPlaceholder="Buscar por código, canal, sucursal…"
        exportFileName="pagos_epos"
        emptyMessage={
          <EmptyState
            icon={CreditCard}
            title="Sin pagos ePOS registrados"
            description="No se encontraron pagos electrónicos en el período. Verificá las credenciales del gateway."
          />
        }
      />
    </div>
  )
}

function KpiCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-base font-medium tabular-nums">{value}</p>
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
