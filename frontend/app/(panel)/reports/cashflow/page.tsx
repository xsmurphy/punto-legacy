"use client"

/**
 * Reporte Flujo de Caja — espejo de panel/reports/cashflow.html.
 *
 * Backend: GET /v1/reports/cashflow?from=&to=
 * → { cashSales, cashPayments, incomeTotal, stockPurchase, expensesPurchase,
 *     outPayment, outcomeTotal, remains, initialCash, accumulated }
 *
 * Reporte date-scoped. Muestra KPIs de saldo inicial / ingresos / egresos /
 * saldo final + una tabla de desglose de cada concepto.
 */

import * as React from "react"
import Link from "next/link"
import { AlertCircle, ArrowLeft, Wallet } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type CashflowResponse } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"

interface FlowLine {
  label: string
  value: number
  indent?: boolean
  total?: boolean
}

export default function CashflowReportPage() {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<CashflowResponse>("cashflow", opts)
  const currency = bootstrap?.currency ?? ""

  const lines = React.useMemo<FlowLine[]>(() => {
    if (!data) return []
    return [
      { label: "Saldo inicial", value: data.initialCash, total: true },
      { label: "Ingresos", value: data.incomeTotal, total: true },
      { label: "Ventas al contado", value: data.cashSales, indent: true },
      { label: "Cobros de deuda", value: data.cashPayments, indent: true },
      { label: "Egresos", value: data.outcomeTotal, total: true },
      { label: "Compra de stock", value: data.stockPurchase, indent: true },
      { label: "Gastos y servicios", value: data.expensesPurchase, indent: true },
      { label: "Pagos a proveedores", value: data.outPayment, indent: true },
      { label: "Saldo del período", value: data.remains, total: true },
      { label: "Saldo acumulado", value: data.accumulated, total: true },
    ]
  }, [data])

  const hasData = data != null

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Flujo de Caja</h1>
          <p className="text-sm text-muted-foreground">
            Resumen de ingresos y egresos de caja del período.
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

      {/* KPI cards */}
      {isLoading ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-20 rounded-lg" />
          ))}
        </div>
      ) : hasData ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <KpiCard label="Saldo Inicial" value={`${currency} ${formatMoney(data.initialCash, bootstrap)}`} />
          <KpiCard label="Ingresos" value={`${currency} ${formatMoney(data.incomeTotal, bootstrap)}`} />
          <KpiCard label="Egresos" value={`${currency} ${formatMoney(data.outcomeTotal, bootstrap)}`} />
          <KpiCard label="Saldo Final" value={`${currency} ${formatMoney(data.accumulated, bootstrap)}`} emphasis />
        </div>
      ) : null}

      {/* Breakdown table */}
      {isLoading ? (
        <div className="flex flex-col gap-2">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-10 rounded" />
          ))}
        </div>
      ) : !hasData ? (
        <EmptyState
          icon={Wallet}
          title="Sin datos de flujo de caja"
          description="Ajustá el rango de fechas y volvé a consultar."
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b bg-muted/40">
                <th className="px-4 py-2 text-left font-medium text-muted-foreground">Concepto</th>
                <th className="px-4 py-2 text-right font-medium text-muted-foreground">Monto</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((line, i) => (
                <tr
                  key={i}
                  className={
                    line.total
                      ? "border-b bg-muted/20 font-semibold"
                      : "border-b last:border-0 hover:bg-muted/20"
                  }
                >
                  <td className={`px-4 py-2 ${line.indent ? "pl-8 text-muted-foreground" : ""}`}>
                    {line.label}
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums">
                    {currency} {formatMoney(line.value, bootstrap)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function KpiCard({
  label,
  value,
  emphasis,
}: {
  label: string
  value: string
  emphasis?: boolean
}) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p
        className={
          emphasis
            ? "mt-1 text-lg font-semibold tabular-nums"
            : "mt-1 text-base font-medium tabular-nums"
        }
      >
        {value}
      </p>
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
