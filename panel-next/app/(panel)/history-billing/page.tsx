"use client"

import * as React from "react"
import {
  Wallet,
  Sparkles,
  Receipt,
  Coins,
  ArrowUpRight,
  ArrowDownRight,
  Clock,
  XCircle,
  MessageSquare,
  PackageCheck,
} from "lucide-react"

import type { ColumnDef } from "@tanstack/react-table"
import type { BillingPayment } from "@/lib/types/billing"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useBilling, useAiLedger } from "@/hooks/use-billing"
import { formatMoney } from "@/lib/format"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Progress } from "@/components/ui/progress"
import { Skeleton } from "@/components/ui/skeleton"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"

// ── helpers ──────────────────────────────────────────────────────────────────

/** Badge de estado de pago — monocromático (solo tokens del design system). */
function paymentStatusLabel(status: number) {
  if (status === 1) return <Badge variant="secondary">Pagado</Badge>
  if (status === 2) return <Badge variant="outline">Reembolsado</Badge>
  return <Badge variant="outline">Pendiente</Badge>
}

function fmtDate(s: string | null): string {
  if (!s) return "—"
  try {
    return new Date(s).toLocaleDateString("es", {
      year: "numeric",
      month: "short",
      day: "numeric",
    })
  } catch {
    return s
  }
}

const nf = new Intl.NumberFormat("es-ES")

// ── column defs ───────────────────────────────────────────────────────────────

const paymentColumns: ColumnDef<BillingPayment, unknown>[] = [
  {
    id: "date",
    accessorKey: "date",
    header: "Fecha",
    cell: ({ row }) => fmtDate(row.original.date),
  },
  {
    id: "invoice",
    accessorKey: "invoice",
    header: "Comprobante",
    cell: ({ row }) => (row.original.invoice ? String(row.original.invoice) : "—"),
  },
  {
    id: "amount",
    accessorKey: "amount",
    header: "Monto",
    cell: ({ row }) => `USD ${row.original.amount.toFixed(2)}`,
  },
  {
    id: "status",
    accessorKey: "status",
    header: "Estado",
    cell: ({ row }) => paymentStatusLabel(row.original.status),
  },
]

// ── main page ─────────────────────────────────────────────────────────────────

export default function HistoryBillingPage() {
  const { data: bootstrap } = useBootstrap()
  const { data: billing, isLoading, isError } = useBilling()
  const { data: ledgerData, isLoading: ledgerLoading } = useAiLedger()

  const ledgerRows = ledgerData?.rows ?? []
  const payments = billing?.payments ?? []

  if (isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader />
        <div className="grid gap-4 lg:grid-cols-3">
          <Skeleton className="h-44 rounded-xl lg:col-span-2" />
          <Skeleton className="h-44 rounded-xl" />
        </div>
        <Skeleton className="h-10 w-full rounded-lg" />
        <Skeleton className="h-64 rounded-xl" />
      </div>
    )
  }

  if (isError || !billing) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader />
        <EmptyState
          icon={XCircle}
          showMarquee={false}
          title="No se pudo cargar la información de facturación"
          description="Intentá recargar la página o contactá a soporte."
        />
      </div>
    )
  }

  const { plan, usage, trial, balance, smsCredit, aiCredits, addons } = billing

  const monthly = aiCredits.monthly
  const creditsUsed = monthly > 0 ? Math.max(0, monthly - aiCredits.balance) : 0
  const creditsPct =
    monthly > 0 ? Math.min(100, Math.round((creditsUsed / monthly) * 100)) : 0

  const trialDaysLeft = trial.expiresAt
    ? Math.max(
        0,
        Math.ceil(
          (new Date(trial.expiresAt).getTime() - Date.now()) / 86_400_000,
        ),
      )
    : null

  return (
    <div className="flex flex-col gap-6">
      <PageHeader />

      {/* ── Balance + Plan actual ───────────────────────────────────────────── */}
      <div className="grid gap-4 lg:grid-cols-3">
        {/* Balance disponible */}
        <Card className="lg:col-span-2">
          <CardHeader className="flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">
              Balance disponible
            </CardTitle>
            <Wallet className="size-4 text-muted-foreground" />
          </CardHeader>
          <CardContent className="flex flex-col gap-6">
            <div className="flex items-baseline gap-2">
              <span className="text-4xl font-bold tracking-tight tabular-nums">
                {nf.format(aiCredits.balance)}
              </span>
              <span className="text-sm text-muted-foreground">créditos</span>
            </div>

            {monthly > 0 && (
              <div className="flex flex-col gap-1.5">
                <div className="flex items-center justify-between text-xs">
                  <span className="text-muted-foreground">
                    Uso del plan {plan.name}
                  </span>
                  <span className="tabular-nums text-muted-foreground">
                    {nf.format(creditsUsed)} / {nf.format(monthly)}
                  </span>
                </div>
                <Progress value={creditsPct} className="h-2" />
              </div>
            )}
          </CardContent>
        </Card>

        {/* Plan actual */}
        <Card>
          <CardHeader className="flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">
              Plan actual
            </CardTitle>
            <Sparkles className="size-4 text-muted-foreground" />
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <div className="flex items-center gap-2">
              <span className="text-2xl font-bold tracking-tight">
                {plan.name || "—"}
              </span>
              {trial.expired ? (
                <Badge variant="destructive">Vencido</Badge>
              ) : trial.isTrial ? (
                <Badge variant="secondary">
                  <Clock className="mr-1 size-3" />
                  Prueba{trialDaysLeft ? ` · ${trialDaysLeft}d` : ""}
                </Badge>
              ) : (
                <Badge>Activo</Badge>
              )}
            </div>

            <p className="text-sm text-muted-foreground">
              {plan.price > 0 ? `USD ${nf.format(plan.price)} / mes` : "Gratis"}
              {monthly > 0 && ` · ${nf.format(monthly)} créditos`}
            </p>

            <Button
              variant="outline"
              size="sm"
              disabled
              className="w-fit gap-2"
            >
              Cambiar plan
              <Badge variant="secondary" className="text-[10px]">
                Próximamente
              </Badge>
            </Button>
          </CardContent>
        </Card>
      </div>

      {/* ── Movimientos / Comprar créditos ──────────────────────────────────── */}
      <Tabs defaultValue="movimientos">
        <TabsList className="grid w-full grid-cols-2">
          <TabsTrigger value="movimientos">Movimientos</TabsTrigger>
          <TabsTrigger value="comprar">Comprar créditos</TabsTrigger>
        </TabsList>

        {/* Movimientos: facturas + movimientos de créditos */}
        <TabsContent value="movimientos" className="mt-4 flex flex-col gap-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base font-semibold">Facturas</CardTitle>
              <CardDescription>
                Tus pagos de suscripción y compras de créditos.
              </CardDescription>
            </CardHeader>
            <CardContent>
              {payments.length === 0 ? (
                <EmptyState
                  icon={Receipt}
                  title="Sin facturas todavía"
                  description="Tus comprobantes de suscripción y compras de créditos van a aparecer acá."
                />
              ) : (
                <DataTable<BillingPayment>
                  tableId="billing-payments"
                  data={payments}
                  columns={paymentColumns}
                  getRowId={(row) => row.id}
                  searchPlaceholder="Buscar comprobante…"
                  exportFileName={null}
                  pageSize={10}
                />
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base font-semibold">
                Movimientos de créditos
              </CardTitle>
              <CardDescription>
                Altas y consumo de créditos de IA de tu cuenta.
              </CardDescription>
            </CardHeader>
            <CardContent>
              {ledgerLoading ? (
                <div className="flex flex-col gap-2">
                  <Skeleton className="h-10 w-full" />
                  <Skeleton className="h-10 w-full" />
                  <Skeleton className="h-10 w-full" />
                </div>
              ) : ledgerRows.length === 0 ? (
                <EmptyState
                  icon={Coins}
                  title="Sin movimientos de créditos"
                  description="Cuando se acrediten o consuman créditos, vas a ver el detalle acá."
                />
              ) : (
                <div className="flex flex-col divide-y">
                  {ledgerRows.map((entry) => (
                    <div
                      key={entry.id}
                      className="flex items-center justify-between gap-3 py-3"
                    >
                      <div className="flex min-w-0 items-center gap-3">
                        {entry.delta >= 0 ? (
                          <ArrowUpRight className="size-4 shrink-0 text-muted-foreground" />
                        ) : (
                          <ArrowDownRight className="size-4 shrink-0 text-muted-foreground" />
                        )}
                        <div className="flex min-w-0 flex-col">
                          <span className="truncate text-sm">{entry.reason}</span>
                          <span className="text-xs text-muted-foreground">
                            {fmtDate(entry.createdAt)}
                          </span>
                        </div>
                      </div>
                      <span className="shrink-0 text-sm font-medium tabular-nums">
                        {entry.delta >= 0 ? "+" : ""}
                        {nf.format(entry.delta)}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Comprar créditos: próximamente */}
        <TabsContent value="comprar" className="mt-4">
          <Card>
            <CardContent className="py-2">
              <EmptyState
                icon={Sparkles}
                showMarquee={false}
                title="Compra de créditos — próximamente"
                description="Pronto vas a poder comprar paquetes de créditos de IA directamente desde acá."
              />
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* ── Detalle del plan (límites + SMS + add-ons) ──────────────────────── */}
      <div className="grid gap-4 lg:grid-cols-3">
        {/* Límites del plan */}
        <Card className="lg:col-span-2">
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold">Límites del plan</CardTitle>
            <CardDescription>Recursos usados vs. el tope de tu plan.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {usage.length === 0 ? (
              <EmptyState
                icon={PackageCheck}
                showMarquee={false}
                title="Sin información de uso"
                className="py-4"
              />
            ) : (
              usage.map((row) => {
                const unlimited = row.max === 0
                const pct = unlimited
                  ? 0
                  : Math.min(100, Math.round((row.used / row.max) * 100))
                return (
                  <div key={row.key} className="flex flex-col gap-1.5">
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">{row.label}</span>
                      <span className="font-medium tabular-nums">
                        {unlimited
                          ? `${nf.format(row.used)} / ∞`
                          : `${nf.format(row.used)} / ${nf.format(row.max)}`}
                      </span>
                    </div>
                    {!unlimited && <Progress value={pct} className="h-1.5" />}
                  </div>
                )
              })
            )}
          </CardContent>
        </Card>

        <div className="flex flex-col gap-4">
          {/* Créditos SMS */}
          <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Créditos SMS
              </CardTitle>
              <MessageSquare className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="flex items-baseline gap-2">
                <span className="text-2xl font-bold tabular-nums">
                  {nf.format(smsCredit)}
                </span>
                <span className="text-xs text-muted-foreground">créditos</span>
              </div>
            </CardContent>
          </Card>

          {/* Balance de cuenta */}
          <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Saldo a favor
              </CardTitle>
              <Coins className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <span className="text-2xl font-bold tabular-nums">
                {formatMoney(balance, bootstrap)}
              </span>
            </CardContent>
          </Card>
        </div>
      </div>

      {/* Add-ons (solo si hay) */}
      {addons.length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold">Add-ons contratados</CardTitle>
            <CardDescription>Recursos adicionales activos en tu plan.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-2">
            {addons.map((addon) => (
              <div
                key={addon.key}
                className="flex items-center justify-between text-sm"
              >
                <span className="text-muted-foreground">{addon.label}</span>
                <span className="font-medium tabular-nums">
                  {nf.format(addon.qty)} uds.
                </span>
              </div>
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  )
}

function PageHeader() {
  return (
    <div className="flex flex-col gap-1">
      <h1 className="text-2xl font-bold tracking-tight">Mi plan</h1>
      <p className="text-sm text-muted-foreground">
        Tu plan, tus créditos y tus facturas.
      </p>
    </div>
  )
}
