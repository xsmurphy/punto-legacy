"use client"

/**
 * Reporte Flujo de Efectivo (B2 de `context/60`).
 *
 * Backend: GET /v1/reports/cashflow?from=&to= → saldos por cuenta, entradas y
 * salidas por categoría, y `balances.check`.
 *
 * La versión anterior mostraba KPIs derivados de `transaction` que no eran
 * efectivo (una venta con tarjeta contaba como caja) y un "saldo inicial" que
 * era el neto del período previo. Esta lee el ledger de Finanzas, así que el
 * reporte CUADRA: saldo inicial + entradas − salidas = saldo final.
 */

import * as React from "react"
import Link from "next/link"
import { AlertCircle, ArrowLeft, TrendingDown, TrendingUp, Wallet } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { DateRangePicker, rangeToBackend } from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type CashflowResponse, type CashflowCategory } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

export default function CashflowReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<CashflowResponse>("cashflow", opts)

  const money = (n: number) => formatMoney(n, bootstrap)

  function CategoryList({ rows, empty }: { rows: CashflowCategory[]; empty: string }) {
    if (rows.length === 0) {
      return <p className="text-sm text-muted-foreground">{empty}</p>
    }
    return (
      <div className="flex flex-col">
        {rows.map((r) => (
          <div
            key={r.categoryId ?? r.name}
            className="flex items-center justify-between border-b py-2 last:border-0"
          >
            <span className="text-sm">{r.name}</span>
            <span className="text-sm tabular-nums">{money(r.amount)}</span>
          </div>
        ))}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3">
        <Button variant="ghost" size="sm" asChild className="w-fit -ml-2">
          <Link href="/reports">
            <ArrowLeft className="size-4" />
            Volver a reportes
          </Link>
        </Button>
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Flujo de efectivo</h1>
          <p className="text-sm text-muted-foreground">
            Entradas y salidas reales de tus cuentas en el período, con el saldo al
            inicio y al final.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {error && (
        <EmptyState
          icon={AlertCircle}
          title="No se pudo cargar el reporte"
          description={error.message}
        />
      )}

      {isLoading && <Skeleton className="h-32 w-full" />}

      {!isLoading && !error && data && (
        <>
          <StatsRow>
            <StatTile label="Saldo inicial" value={money(data.balances.opening)} />
            <StatTile
              icon={<TrendingUp className="size-4" />}
              label="Entradas"
              value={money(data.incomeTotal)}
              tone="positive"
            />
            <StatTile
              icon={<TrendingDown className="size-4" />}
              label="Salidas"
              value={money(data.expenseTotal)}
              tone="negative"
            />
            <StatTile
              icon={<Wallet className="size-4" />}
              label="Saldo final"
              value={money(data.balances.closing)}
              tone={data.balances.closing >= 0 ? "positive" : "negative"}
              emphasis
            />
          </StatsRow>

          {/* El reporte debe cuadrar. Si no cuadra se dice, en vez de mostrar
              números que no cierran como si cerraran. */}
          {Math.abs(data.balances.check) >= 0.01 && (
            <EmptyState
              icon={AlertCircle}
              title="Los saldos no cuadran"
              description={`Saldo inicial + entradas − salidas no da el saldo final (diferencia: ${money(
                data.balances.check,
              )}). Es un problema de datos, no de cálculo — avisale al soporte.`}
            />
          )}

          <div className="grid gap-6 md:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold tracking-tight">
                  Entradas por categoría
                </CardTitle>
              </CardHeader>
              <CardContent>
                <CategoryList rows={data.income} empty="Sin entradas en el período." />
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold tracking-tight">
                  Salidas por categoría
                </CardTitle>
              </CardHeader>
              <CardContent>
                <CategoryList rows={data.expense} empty="Sin salidas en el período." />
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle className="text-base font-semibold tracking-tight">
                Movimiento por cuenta
              </CardTitle>
            </CardHeader>
            <CardContent>
              {data.accounts.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  No tenés cuentas de Finanzas cargadas.
                </p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        <th className="py-2 text-left font-semibold">Cuenta</th>
                        <th className="py-2 text-right font-semibold">Inicial</th>
                        <th className="py-2 text-right font-semibold">Entradas</th>
                        <th className="py-2 text-right font-semibold">Salidas</th>
                        <th className="py-2 text-right font-semibold">Final</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.accounts.map((a) => (
                        <tr key={a.accountId} className="border-b last:border-0">
                          <td className="py-2">{a.name}</td>
                          <td className="py-2 text-right tabular-nums">{money(a.opening)}</td>
                          <td className="py-2 text-right tabular-nums">{money(a.income)}</td>
                          <td className="py-2 text-right tabular-nums">{money(a.expense)}</td>
                          <td className="py-2 text-right font-medium tabular-nums">
                            {money(a.closing)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              {/* Las transferencias entre cuentas propias no son flujo de la
                  empresa, así que no están en entradas/salidas — pero sí mueven
                  el saldo de cada cuenta. Sin esta nota, los totales de la tabla
                  parecen no coincidir con los de arriba. */}
              <p className="mt-3 text-xs text-muted-foreground">
                Las transferencias entre tus propias cuentas mueven estos saldos pero no
                cuentan como entrada ni salida del negocio.
              </p>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}
