"use client"

/**
 * Reporte de Movimientos de Caja — espejo de panel/reports/expenses.html.
 *
 * Lista las entradas/salidas manuales del cajón (no son ventas, son
 * extracciones e ingresos manuales que se cargan en el POS durante el día).
 * Backend: GET /v1/reports/expenses?from=&to= → array de rows con date,
 * outletName, registerName, userName, note, type (1=extracción, 2=ingreso),
 * amount.
 *
 * Las dos filas de KPI arriba: total de ingresos y total de extracciones.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowDown, ArrowLeft, ArrowUp, Coins } from "lucide-react"

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
import { useReport, type ExpenseRow } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { EmptyState } from "@/components/empty-state"

export default function ExpensesReportPage() {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<ExpenseRow[]>("expenses", opts)
  const rows = React.useMemo(() => data ?? [], [data])

  const totals = React.useMemo(() => {
    let income = 0
    let extraction = 0
    rows.forEach((r) => {
      // type: 1 = extracción (sale del cajón), 2 = ingreso (entra al cajón).
      if (r.type === 2) income += r.amount
      else extraction += r.amount
    })
    return { income, extraction, net: income - extraction }
  }, [rows])

  const columns = React.useMemo<ColumnDef<ExpenseRow>[]>(
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
        accessorKey: "type",
        header: "Tipo",
        cell: ({ row }) => {
          const t = row.original.type
          return t === 2 ? (
            <Badge variant="default" className="gap-1">
              <ArrowUp className="size-3" />
              Ingreso
            </Badge>
          ) : (
            <Badge variant="secondary" className="gap-1">
              <ArrowDown className="size-3" />
              Extracción
            </Badge>
          )
        },
        meta: { label: "Tipo" },
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
        accessorKey: "registerName",
        header: "Caja",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Caja" },
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "Usuario" },
      },
      {
        accessorKey: "note",
        header: "Concepto",
        cell: ({ getValue }) => {
          const v = (getValue() as string) ?? ""
          if (!v) return <span className="opacity-40">—</span>
          return <span className="truncate">{v}</span>
        },
        meta: { label: "Concepto" },
      },
      {
        accessorKey: "amount",
        header: "Monto",
        cell: ({ row }) => {
          const r = row.original
          const cls =
            r.type === 2
              ? "tabular-nums font-medium text-emerald-600"
              : "tabular-nums font-medium text-destructive"
          return (
            <span className={cls}>
              {r.type === 2 ? "+" : "−"} {formatMoney(r.amount, bootstrap)}
            </span>
          )
        },
        meta: { label: "Monto", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Movimientos de Caja</h1>
          <p className="text-sm text-muted-foreground">
            Entradas y salidas manuales del cajón (no son ventas). Se cargan en el POS durante el día.
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
        <div className="grid grid-cols-3 gap-6 border-y py-3 text-sm">
          <Stat
            icon={<ArrowUp className="size-3.5 text-emerald-600" />}
            label="Ingresos"
            value={`${bootstrap?.currency ?? ""} ${formatMoney(totals.income, bootstrap)}`}
            tone="positive"
          />
          <Stat
            icon={<ArrowDown className="size-3.5 text-destructive" />}
            label="Extracciones"
            value={`${bootstrap?.currency ?? ""} ${formatMoney(totals.extraction, bootstrap)}`}
            tone="negative"
          />
          <Stat
            label="Neto"
            value={`${bootstrap?.currency ?? ""} ${formatMoney(totals.net, bootstrap)}`}
            emphasis
            tone={totals.net >= 0 ? "positive" : "negative"}
          />
        </div>
      )}

      <DataTable
        tableId="report-expenses"
        data={rows}
        columns={columns}
        getRowId={(r) => r.expensesId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por concepto, usuario, caja…"
        exportFileName="movimientos_caja"
        emptyMessage={
          <EmptyState
            icon={Coins}
            title="Sin movimientos de caja"
            description="Los ingresos/extracciones se cargan desde el POS."
          />
        }
      />
    </div>
  )
}

function Stat({
  icon,
  label,
  value,
  emphasis,
  tone,
}: {
  icon?: React.ReactNode
  label: string
  value: string
  emphasis?: boolean
  tone?: "positive" | "negative"
}) {
  const valueCls = emphasis
    ? tone === "positive"
      ? "text-base font-semibold tabular-nums text-emerald-600"
      : tone === "negative"
        ? "text-base font-semibold tabular-nums text-destructive"
        : "text-base font-semibold tabular-nums"
    : tone === "positive"
      ? "text-sm font-medium tabular-nums text-emerald-600"
      : tone === "negative"
        ? "text-sm font-medium tabular-nums text-destructive"
        : "text-sm font-medium tabular-nums"
  return (
    <div className="flex flex-col gap-0.5">
      <span className="flex items-center gap-1 text-[10px] uppercase tracking-wide text-muted-foreground">
        {icon}
        {label}
      </span>
      <span className={valueCls}>{value}</span>
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
