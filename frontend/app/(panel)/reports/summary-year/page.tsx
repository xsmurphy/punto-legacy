"use client"

/**
 * Reporte Resumen Anual — espejo de panel/reports/summary-year.html.
 *
 * Backend: GET /v1/reports/summary_year?y=<YYYY>
 * → { year, years:[...], months:[{ month, usold, count, discount, tax,
 *     salesTotal, expensesTotal, returnsTotal, nonAddingTotal, customers }] }
 *
 * Reporte por año (no date-range). El selector de año se construye desde
 * years[] devueltos por el backend.
 * Visualización: tabla mensual + bar chart de ingresos vs egresos.
 */

import * as React from "react"
import Link from "next/link"
import { AlertCircle, ArrowLeft, CalendarDays } from "lucide-react"
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts"

import { Button } from "@/components/ui/button"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type SummaryYearResponse } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

const MONTH_NAMES = [
  "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio",
  "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre",
]

export default function SummaryYearPage() {
  const { data: bootstrap } = useBootstrap()
  const currentYear = new Date().getFullYear()
  const [year, setYear] = React.useState(String(currentYear))

  const { data, isLoading, error } = useReport<SummaryYearResponse>("summary_year", {
    params: { y: year },
  })

  const months = data?.months ?? []
  const years = data?.years ?? [currentYear]

  const annualTotals = React.useMemo(() => {
    let sales = 0
    let expenses = 0
    let returns = 0
    let count = 0
    let customers = 0
    months.forEach((m) => {
      sales += m.salesTotal
      expenses += m.expensesTotal
      returns += m.returnsTotal
      count += m.count
      customers += m.customers
    })
    return { sales, expenses, returns, count, customers, net: sales - returns }
  }, [months])

  const chartData = React.useMemo(
    () =>
      months.map((m) => ({
        name: MONTH_NAMES[m.month - 1]?.slice(0, 3) ?? `M${m.month}`,
        Ingresos: m.salesTotal,
        Egresos: m.expensesTotal,
        Devoluciones: m.returnsTotal,
      })),
    [months],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Resumen Anual</h1>
          <p className="text-sm text-muted-foreground">
            Ingresos y egresos mensuales del año seleccionado.
          </p>
        </div>
        <Select value={year} onValueChange={setYear}>
          <SelectTrigger className="w-32">
            <SelectValue placeholder="Año" />
          </SelectTrigger>
          <SelectContent>
            {years.map((y) => (
              <SelectItem key={y} value={String(y)}>
                {y}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
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
      {(isLoading || months.length > 0) && (
        <StatsRow>
          <StatTile
            label="Ventas brutas"
            value={formatMoney(annualTotals.sales, bootstrap)}
            isLoading={isLoading}
          />
          <StatTile
            label="Devoluciones"
            value={formatMoney(annualTotals.returns, bootstrap)}
            isLoading={isLoading}
          />
          <StatTile
            label="Egresos"
            value={formatMoney(annualTotals.expenses, bootstrap)}
            isLoading={isLoading}
          />
          <StatTile
            label="Ventas netas"
            value={formatMoney(annualTotals.net, bootstrap)}
            emphasis
            isLoading={isLoading}
          />
        </StatsRow>
      )}

      {/* Chart */}
      {!isLoading && chartData.length > 0 && (
        <div className="rounded-lg border bg-card p-4">
          <p className="mb-4 text-sm font-medium">Ingresos y egresos por mes — {year}</p>
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={chartData} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
              <XAxis
                dataKey="name"
                tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                axisLine={false}
                tickLine={false}
              />
              <YAxis
                tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                axisLine={false}
                tickLine={false}
                tickFormatter={(v: number) => formatMoney(v, bootstrap)}
              />
              <Tooltip
                contentStyle={{
                  fontSize: 12,
                  borderRadius: 8,
                  border: "1px solid var(--border)",
                  background: "var(--card)",
                  color: "var(--foreground)",
                }}
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
              formatter={(value: any, name: any) => [
                  formatMoney(Number(value) || 0, bootstrap),
                  String(name),
                ]}
              />
              <Bar dataKey="Ingresos" fill="var(--chart-1)" radius={[3, 3, 0, 0]} />
              <Bar dataKey="Egresos" fill="var(--chart-3)" radius={[3, 3, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}

      {/* Monthly table */}
      {isLoading ? (
        <div className="flex flex-col gap-2">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-10 rounded" />
          ))}
        </div>
      ) : months.length === 0 ? (
        <EmptyState
          icon={CalendarDays}
          title="Sin datos para este año"
          description="Seleccioná otro año o revisá que haya transacciones registradas."
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b bg-muted/40">
                <th className="px-4 py-2 text-left font-medium text-muted-foreground">Mes</th>
                <th className="px-4 py-2 text-right font-medium text-muted-foreground">Ventas</th>
                <th className="px-4 py-2 text-right font-medium text-muted-foreground">Devoluciones</th>
                <th className="px-4 py-2 text-right font-medium text-muted-foreground">Egresos</th>
                <th className="px-4 py-2 text-right font-medium text-muted-foreground">Transac.</th>
                <th className="px-4 py-2 text-right font-medium text-muted-foreground">Clientes nuevos</th>
              </tr>
            </thead>
            <tbody>
              {months.map((m) => (
                <tr key={m.month} className="border-b last:border-0 hover:bg-muted/30">
                  <td className="px-4 py-2 font-medium">{MONTH_NAMES[m.month - 1]}</td>
                  <td className="px-4 py-2 text-right tabular-nums">
                    {formatMoney(m.salesTotal, bootstrap)}
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums text-muted-foreground">
                    {formatMoney(m.returnsTotal, bootstrap)}
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums text-muted-foreground">
                    {formatMoney(m.expensesTotal, bootstrap)}
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums text-muted-foreground">
                    {m.count.toLocaleString()}
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums text-muted-foreground">
                    {m.customers.toLocaleString()}
                  </td>
                </tr>
              ))}
              <tr className="border-t bg-muted/20 font-semibold">
                <td className="px-4 py-2">Total</td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {formatMoney(annualTotals.sales, bootstrap)}
                </td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {formatMoney(annualTotals.returns, bootstrap)}
                </td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {formatMoney(annualTotals.expenses, bootstrap)}
                </td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {annualTotals.count.toLocaleString()}
                </td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {annualTotals.customers.toLocaleString()}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      )}
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
