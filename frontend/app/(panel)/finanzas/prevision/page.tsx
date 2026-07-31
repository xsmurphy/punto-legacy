"use client"

import * as React from "react"
import Link from "next/link"
import { CalendarClock } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Skeleton } from "@/components/ui/skeleton"
import { EmptyState } from "@/components/empty-state"
import { DateRangePicker, type DateRangeValue } from "@/components/date-range-picker"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useFinanceForecast, type ForecastRow, type ForecastRowType } from "@/hooks/use-finance-forecast"

const TYPE_LABELS: Record<ForecastRowType, string> = {
  check: "Cheque",
  loan_installment: "Cuota",
  purchase: "Factura",
}

const TYPE_BADGE_VARIANT: Record<ForecastRowType, "outline" | "secondary" | "default"> = {
  check: "outline",
  loan_installment: "secondary",
  purchase: "default",
}

function pad(n: number): string {
  return String(n).padStart(2, "0")
}

function toISODate(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** Rango default de la Previsión: hoy → hoy+30 días (a futuro, NO el rango
 * compartido de reportes de `useDateRange` — ese mira para atrás). */
function defaultForecastRange(): DateRangeValue {
  const from = new Date()
  const to = new Date(from.getTime() + 30 * 24 * 60 * 60 * 1000)
  return { from, to }
}

function isOverdue(dueDate: string): boolean {
  return new Date(dueDate).getTime() < new Date(toISODate(new Date())).getTime()
}

export default function FinanzasPrevisionPage() {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultForecastRange)
  const { data, isLoading } = useFinanceForecast({
    from: toISODate(range.from),
    to: toISODate(range.to),
  })

  const obligations = data?.obligations ?? []
  const income = data?.income ?? []
  const totalObligations = obligations.reduce((s, r) => s + r.amount, 0)
  const totalIncome = income.reduce((s, r) => s + r.amount, 0)
  const overdueCount = obligations.filter((r) => isOverdue(r.dueDate)).length

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <p className="text-sm text-muted-foreground">
          Vencimientos futuros — cheques, cuotas de crédito y facturas de compra. Solo lectura.
        </p>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {isLoading ? (
        <div className="flex flex-col gap-6">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-24 w-full" />
            ))}
          </div>
          <Skeleton className="h-64 w-full" />
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Obligaciones del período
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-semibold tabular-nums">
                  {formatMoney(totalObligations, bootstrap)}
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Ingresos conocidos del período
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-semibold tabular-nums">{formatMoney(totalIncome, bootstrap)}</p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">Vencidos</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-semibold tabular-nums text-destructive">{overdueCount}</p>
              </CardContent>
            </Card>
          </div>

          <ForecastTable
            title="Obligaciones (egresos futuros)"
            rows={obligations}
            emptyDescription="Sin cheques, cuotas o facturas con vencimiento en este rango."
          />

          <ForecastTable
            title="Ingresos conocidos"
            rows={income}
            emptyDescription="Sin cheques recibidos pendientes de depositar/cobrar en este rango."
          />
        </>
      )}
    </div>
  )
}

function ForecastTable({
  title,
  rows,
  emptyDescription,
}: {
  title: string
  rows: ForecastRow[]
  emptyDescription: string
}) {
  const { data: bootstrap } = useBootstrap()

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base font-semibold tracking-tight">{title}</CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        {rows.length === 0 ? (
          <div className="p-6">
            <EmptyState icon={CalendarClock} title="Sin vencimientos" description={emptyDescription} />
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-28">Tipo</TableHead>
                <TableHead>Descripción</TableHead>
                <TableHead className="w-36">Vencimiento</TableHead>
                <TableHead className="text-right w-36">Monto</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((r) => {
                const overdue = isOverdue(r.dueDate)
                return (
                  <TableRow key={`${r.type}-${r.id}`}>
                    <TableCell>
                      <Badge variant={TYPE_BADGE_VARIANT[r.type]}>{TYPE_LABELS[r.type]}</Badge>
                    </TableCell>
                    <TableCell>
                      <Link href={r.link} className="hover:underline">
                        {r.label}
                      </Link>
                      {r.party && <span className="ml-1.5 text-xs text-muted-foreground">· {r.party}</span>}
                    </TableCell>
                    <TableCell
                      className={`tabular-nums whitespace-nowrap ${overdue ? "font-semibold text-destructive" : ""}`}
                    >
                      {formatDate(r.dueDate)}
                      {overdue && <span className="ml-1.5 text-xs">(vencido)</span>}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{formatMoney(r.amount, bootstrap)}</TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}
