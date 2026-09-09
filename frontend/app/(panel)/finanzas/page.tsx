"use client"

import * as React from "react"
import Link from "next/link"
import { Landmark } from "lucide-react"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { StatsRow, StatTile } from "@/components/stat-tile"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { EmptyState } from "@/components/empty-state"
import { Skeleton } from "@/components/ui/skeleton"
import { DateRangePicker, rangeToBackend } from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useFinanceSummary } from "@/hooks/use-finance-summary"

export default function FinanzasResumenPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])
  const { data: summary, isLoading } = useFinanceSummary(opts)

  const accounts = summary?.accounts ?? []
  const recentMovements = summary?.recentMovements ?? []

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Resumen</h1>
          <p className="text-sm text-muted-foreground">
            Saldos por cuenta y flujo de caja del período.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {isLoading ? (
        <div className="flex flex-col gap-6">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-28 w-full" />
            ))}
          </div>
          <Skeleton className="h-64 w-full" />
        </div>
      ) : accounts.length === 0 ? (
        <EmptyState
          icon={Landmark}
          title="Todavía no cargaste movimientos"
          description="Registrá tu primera entrada o salida desde Movimientos."
        />
      ) : (
        <>
      {/* Los dos bloques de números van en `StatTile` (gris) y el contenido
          en cards blancas: ese contraste ES la jerarquía de la página. Con
          todo blanco y solo bordes, saldos, totales del período y la tabla
          pesaban lo mismo (reportado por el owner).

          Grid y no `StatsRow`: la fila de saldos tiene N cuentas, y con el
          flex un comercio de una sola cuenta se comía el ancho de las tres
          columnas. Un tile ocupa UNA celda y el resto de la fila queda
          vacío (regla del owner). */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {accounts.map((account) => (
          <Link
            key={account.id}
            href={`/finanzas/movimientos?accountId=${account.id}`}
            className="rounded-[min(var(--radius-4xl),24px)] transition-opacity hover:opacity-80"
          >
            <StatTile
              label={account.name}
              value={formatMoney(account.currentBalance, bootstrap)}
            />
          </Link>
        ))}
      </div>

      {/* KPIs del período — neutros, sin color de énfasis (no hay precedente
          de --chart-1 en el repo para este caso; egresos no usan destructive
          porque no son un estado de error). */}
      <StatsRow>
        <StatTile
          label="Ingresos del período"
          value={formatMoney(summary?.totalIncome, bootstrap)}
        />
        <StatTile
          label="Egresos del período"
          value={formatMoney(summary?.totalExpense, bootstrap)}
        />
        <StatTile
          label="Flujo neto"
          value={formatMoney(summary?.netFlow, bootstrap)}
          emphasis
        />
      </StatsRow>

      {/* Últimos movimientos */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">
            Últimos movimientos
          </CardTitle>
        </CardHeader>
        <CardContent>
          {recentMovements.length === 0 ? (
            <p className="text-sm text-muted-foreground">Sin movimientos recientes.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Cuenta</TableHead>
                  <TableHead>Categoría</TableHead>
                  <TableHead>Descripción</TableHead>
                  <TableHead className="text-right">Monto</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {recentMovements.slice(0, 10).map((m) => (
                  <TableRow key={m.id}>
                    <TableCell className="tabular-nums whitespace-nowrap">
                      {formatDate(m.date)}
                    </TableCell>
                    <TableCell>{m.accountName ?? "—"}</TableCell>
                    <TableCell>{m.categoryName ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {m.description || "—"}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {m.kind === "expense" ? "-" : ""}
                      {formatMoney(m.amount, bootstrap)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
        </>
      )}
    </div>
  )
}
