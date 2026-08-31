"use client"

/**
 * Balance gerencial (B4 de `context/60`).
 *
 * Backend: GET /v1/reports/balance → Activo / Pasivo / Patrimonio neto.
 *
 * NO es un balance contable. El patrimonio es DERIVADO (Activo − Pasivo), no una
 * cuenta que alguien carga, y el destinatario es el dueño: responde "¿cuánto
 * tengo y cuánto debo?", no "¿cierra mi balance?".
 *
 * Es una FOTO a hoy, no un rango — por eso esta página NO lleva
 * `DateRangePicker`, a diferencia del resto de los reportes.
 */

import * as React from "react"
import Link from "next/link"
import { AlertCircle, ArrowLeft, Info } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type BalanceResponse } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

/** Etiquetas de los tipos de obligación que devuelve `ObligationsService`. */
const OBLIGATION_LABELS: Record<string, string> = {
  check: "Cheques emitidos",
  loan_installment: "Cuotas de préstamos",
}

export default function BalanceReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { data, isLoading, error } = useReport<BalanceResponse>("balance", {})

  const money = (n: number) => formatMoney(n, bootstrap)

  function Line({ label, value, strong }: { label: string; value: number; strong?: boolean }) {
    return (
      <div className="flex items-center justify-between border-b py-2 last:border-0">
        <span className={strong ? "text-sm font-medium" : "text-sm"}>{label}</span>
        <span className={`tabular-nums text-sm${strong ? " font-medium" : ""}`}>
          {money(value)}
        </span>
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
          <h1 className="text-2xl font-semibold">Balance</h1>
          <p className="text-sm text-muted-foreground">
            Qué tenés y qué debés, hoy. Es una foto del momento, no un período.
          </p>
        </div>
      </header>

      {error && (
        <EmptyState
          icon={AlertCircle}
          title="No se pudo cargar el balance"
          description={error.message}
        />
      )}

      {isLoading && <Skeleton className="h-32 w-full" />}

      {!isLoading && !error && data && (
        <>
          <StatsRow>
            <StatTile label="Activo" value={money(data.assets.total)} tone="positive" />
            <StatTile label="Pasivo" value={money(data.liabilities.total)} tone="negative" />
            <StatTile
              label="Patrimonio neto"
              value={money(data.equity)}
              tone={data.equity >= 0 ? "positive" : "negative"}
              emphasis
            />
          </StatsRow>

          <div className="grid gap-6 md:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold tracking-tight">Activo</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col">
                {data.assets.cashByAccount.map((a) => (
                  <Line key={a.accountId} label={a.name} value={a.balance} />
                ))}
                <Line label="Cuentas por cobrar" value={data.assets.receivables} />
                <Line label="Inventario" value={data.assets.inventory} />
                <Line label="Total activo" value={data.assets.total} strong />
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold tracking-tight">Pasivo</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col">
                <Line label="Cuentas por pagar" value={data.liabilities.payables} />
                {Object.entries(data.liabilities.obligationsByType).map(([type, amount]) => (
                  <Line key={type} label={OBLIGATION_LABELS[type] ?? type} value={amount} />
                ))}
                <Line label="Total pasivo" value={data.liabilities.total} strong />
              </CardContent>
            </Card>
          </div>

          {/* No es una nota al pie decorativa: Punto no modela activo fijo, así
              que el patrimonio de arriba está SUBESTIMADO. Un número presentado
              como patrimonio que ignora la mitad de los bienes es peor que no
              mostrarlo, así que se dice acá y no en un comentario del código. */}
          {data.notes.missingFixedAssets && (
            <Card variant="soft">
              <CardContent className="flex gap-3">
                <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div className="flex flex-col gap-1 text-sm text-muted-foreground">
                  <span className="font-medium text-foreground">
                    Este balance no incluye tus bienes
                  </span>
                  <span>
                    Heladeras, vitrinas, vehículos y equipamiento no están cargados en
                    Punto, así que el patrimonio real es mayor al que ves acá. Es un
                    resumen para decidir, no un balance contable — para eso está tu
                    contador.
                  </span>
                </div>
              </CardContent>
            </Card>
          )}
        </>
      )}
    </div>
  )
}
