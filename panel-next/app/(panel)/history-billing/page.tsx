"use client"

import * as React from "react"
import {
  BotMessageSquare,
  CheckCircle2,
  CircleDollarSign,
  Clock,
  CreditCard,
  MessageSquare,
  PackageCheck,
  Receipt,
  RefreshCcw,
  Sparkles,
  XCircle,
} from "lucide-react"
import { toast } from "sonner"

import type { ColumnDef } from "@tanstack/react-table"
import type { BillingPayment, BillingPlan } from "@/lib/types/billing"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useBilling, useBillingPlans, useAiLedger, useRequestPlanChange } from "@/hooks/use-billing"
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Progress } from "@/components/ui/progress"
import { Skeleton } from "@/components/ui/skeleton"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"

// ── helpers ──────────────────────────────────────────────────────────────────

function paymentStatusLabel(status: number) {
  if (status === 1)
    return (
      <Badge variant="outline" className="text-emerald-600 border-emerald-300">
        Pagado
      </Badge>
    )
  if (status === 2)
    return (
      <Badge variant="outline" className="text-orange-500 border-orange-300">
        Reembolsado
      </Badge>
    )
  return (
    <Badge variant="outline" className="text-muted-foreground">
      Pendiente
    </Badge>
  )
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
    header: "Factura",
    cell: ({ row }) => (row.original.invoice ? String(row.original.invoice) : "—"),
  },
  {
    id: "order",
    accessorKey: "order",
    header: "Orden",
    cell: ({ row }) => (row.original.order ? String(row.original.order) : "—"),
  },
  {
    id: "amount",
    accessorKey: "amount",
    header: "Monto",
    cell: ({ row }) => `$ ${row.original.amount.toFixed(2)}`,
  },
  {
    id: "status",
    accessorKey: "status",
    header: "Estado",
    cell: ({ row }) => paymentStatusLabel(row.original.status),
  },
]

// ── components ────────────────────────────────────────────────────────────────

function SkeletonCard() {
  return (
    <Card>
      <CardHeader>
        <Skeleton className="h-5 w-40" />
        <Skeleton className="h-4 w-60 mt-1" />
      </CardHeader>
      <CardContent className="space-y-3">
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-4 w-3/4" />
        <Skeleton className="h-4 w-1/2" />
      </CardContent>
    </Card>
  )
}

// ── plan comparison dialog ────────────────────────────────────────────────────

interface PlanDialogProps {
  open: boolean
  onClose: () => void
  currentPlanCode: number
}

function PlanDialog({ open, onClose, currentPlanCode }: PlanDialogProps) {
  const { data: plansData, isLoading } = useBillingPlans()
  const mutation = useRequestPlanChange()
  const [selected, setSelected] = React.useState<number | null>(null)
  const [note, setNote] = React.useState("")
  const [confirming, setConfirming] = React.useState(false)

  const plans = plansData?.plans ?? []

  function handleRequest() {
    if (selected === null) return
    mutation.mutate(
      { planCode: selected, note: note.trim() || undefined },
      {
        onSuccess: () => {
          toast.success(
            "Solicitud enviada. Te contactaremos para coordinar el pago.",
          )
          onClose()
          setSelected(null)
          setNote("")
          setConfirming(false)
        },
        onError: (err) => {
          toast.error(err.message ?? "No se pudo enviar la solicitud")
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onClose() }}>
      <DialogContent className="max-w-3xl max-h-[80vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Cambiar de plan</DialogTitle>
          <DialogDescription>
            Seleccioná el plan que mejor se adapte a tu negocio. Te
            contactaremos para coordinar el pago.
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
            {[...Array(3)].map((_, i) => (
              <Skeleton key={i} className="h-48 rounded-xl" />
            ))}
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
            {plans
              .filter((p) => p.code !== 0)
              .map((plan) => {
                const isCurrent = plan.code === currentPlanCode
                const isSelected = plan.code === selected
                return (
                  <button
                    key={plan.code}
                    type="button"
                    onClick={() => setSelected(isCurrent ? null : plan.code)}
                    className={[
                      "relative flex flex-col gap-2 rounded-xl border p-4 text-left transition-all",
                      isCurrent
                        ? "border-primary bg-primary/5 cursor-default"
                        : isSelected
                          ? "border-primary ring-2 ring-primary/30 bg-primary/5"
                          : "border-border hover:border-primary/50 hover:bg-muted/40",
                    ].join(" ")}
                  >
                    {isCurrent && (
                      <Badge className="absolute top-3 right-3 text-[10px]">
                        Plan actual
                      </Badge>
                    )}
                    <div className="text-base font-semibold">{plan.name}</div>
                    <div className="text-2xl font-bold">
                      {plan.price > 0 ? `$ ${plan.price.toFixed(2)}` : "Gratis"}
                      {plan.price > 0 && (
                        <span className="text-sm font-normal text-muted-foreground">
                          {" "}/ mes
                        </span>
                      )}
                    </div>
                    <ul className="mt-1 space-y-1 text-xs text-muted-foreground">
                      <PlanLimit label="Artículos" value={plan.maxItems} />
                      <PlanLimit label="Usuarios" value={plan.maxUsers} suffix=" /suc." />
                      <PlanLimit label="Sucursales" value={plan.maxOutlets} />
                      <PlanLimit label="Cajas" value={plan.maxRegisters} />
                      <PlanLimit label="Clientes" value={plan.maxCustomers} />
                      {plan.aiCreditsMonthly > 0 && (
                        <li className="flex items-center gap-1">
                          <Sparkles className="size-3 text-violet-500 shrink-0" />
                          <span>{plan.aiCreditsMonthly.toLocaleString()} créditos IA/mes</span>
                        </li>
                      )}
                    </ul>
                  </button>
                )
              })}
          </div>
        )}

        {confirming && selected !== null && (
          <div className="mt-2 space-y-2">
            <label className="text-sm font-medium">
              Nota adicional (opcional)
            </label>
            <textarea
              className="w-full rounded-md border bg-background px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-ring"
              rows={3}
              placeholder="Alguna consulta o comentario para el equipo de Punto…"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              maxLength={1000}
            />
          </div>
        )}

        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose} disabled={mutation.isPending}>
            Cancelar
          </Button>
          {!confirming ? (
            <Button
              disabled={selected === null || selected === currentPlanCode}
              onClick={() => setConfirming(true)}
            >
              Continuar
            </Button>
          ) : (
            <Button
              disabled={selected === null || mutation.isPending}
              onClick={handleRequest}
            >
              {mutation.isPending ? "Enviando…" : "Solicitar cambio de plan"}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function PlanLimit({
  label,
  value,
  suffix = "",
}: {
  label: string
  value: number
  suffix?: string
}) {
  const display =
    value === 0 ? "Ilimitado" : value.toLocaleString() + suffix
  return (
    <li className="flex items-center justify-between gap-2">
      <span>{label}</span>
      <span className="font-medium text-foreground">{display}</span>
    </li>
  )
}

// ── main page ─────────────────────────────────────────────────────────────────

export default function HistoryBillingPage() {
  const { data: bootstrap } = useBootstrap()
  const { data: billing, isLoading, isError } = useBilling()
  const { data: ledgerData, isLoading: ledgerLoading } = useAiLedger()
  const [planDialogOpen, setPlanDialogOpen] = React.useState(false)

  const ledgerRows = ledgerData?.rows ?? []
  const payments = billing?.payments ?? []

  // ── loading ───────────────────────────────────────────────────────────────
  if (isLoading) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Mi Plan</h1>
          <p className="text-muted-foreground text-sm mt-1">
            Resumen de tu suscripción y uso
          </p>
        </div>
        <div className="grid gap-4 md:grid-cols-2">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </div>
      </div>
    )
  }

  if (isError || !billing) {
    return (
      <div className="space-y-4">
        <h1 className="text-2xl font-bold tracking-tight">Mi Plan</h1>
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

  const trialDaysLeft = trial.expiresAt
    ? Math.max(
        0,
        Math.ceil(
          (new Date(trial.expiresAt).getTime() - Date.now()) /
            (1000 * 60 * 60 * 24),
        ),
      )
    : null

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Mi Plan</h1>
        <p className="text-muted-foreground text-sm mt-1">
          Resumen de tu suscripción, uso y créditos
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        {/* ── Plan actual ─────────────────────────────────────────────────── */}
        <Card>
          <CardHeader className="pb-2">
            <div className="flex items-start justify-between gap-2">
              <CardTitle className="text-base font-semibold">Plan actual</CardTitle>
              <div className="flex gap-1.5 flex-wrap justify-end">
                {trial.isTrial && (
                  <Badge variant="secondary" className="text-amber-600">
                    <Clock className="size-3 mr-1" />
                    Prueba
                    {trialDaysLeft !== null && trialDaysLeft > 0
                      ? ` · ${trialDaysLeft}d`
                      : ""}
                  </Badge>
                )}
                {trial.expired && (
                  <Badge variant="destructive">Plan vencido</Badge>
                )}
              </div>
            </div>
            <CardDescription>
              {plan.price > 0
                ? `$ ${plan.price.toFixed(2)} / mes`
                : "Sin costo"}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-2">
            <div className="flex items-center gap-2">
              <PackageCheck className="size-4 text-muted-foreground shrink-0" />
              <span className="font-semibold text-lg">{plan.name || "—"}</span>
            </div>
            {trial.expiresAt && (
              <p className="text-xs text-muted-foreground">
                {trial.expired ? "Vencido el" : "Vence el"}{" "}
                {fmtDate(trial.expiresAt)}
              </p>
            )}
            <div className="pt-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => setPlanDialogOpen(true)}
              >
                Cambiar de plan
              </Button>
            </div>
          </CardContent>
        </Card>

        {/* ── Uso del plan ────────────────────────────────────────────────── */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold">Uso del plan</CardTitle>
            <CardDescription>Recursos utilizados vs. límite del plan</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {usage.length === 0 ? (
              <p className="text-sm text-muted-foreground">Sin información disponible</p>
            ) : (
              usage.map((row) => {
                const isUnlimited = row.max === 0
                const pct = isUnlimited
                  ? 0
                  : Math.min(100, Math.round((row.used / row.max) * 100))
                return (
                  <div key={row.key} className="space-y-1">
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">{row.label}</span>
                      <span className="font-medium tabular-nums">
                        {isUnlimited
                          ? `${row.used.toLocaleString()} / ∞`
                          : `${row.used.toLocaleString()} / ${row.max.toLocaleString()}`}
                      </span>
                    </div>
                    {!isUnlimited && (
                      <Progress
                        value={pct}
                        className={[
                          "h-1.5",
                          pct >= 90
                            ? "[&>div]:bg-destructive"
                            : pct >= 70
                              ? "[&>div]:bg-amber-500"
                              : "",
                        ].join(" ")}
                      />
                    )}
                  </div>
                )
              })
            )}
          </CardContent>
        </Card>

        {/* ── Créditos IA ─────────────────────────────────────────────────── */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold flex items-center gap-2">
              <BotMessageSquare className="size-4 text-violet-500" />
              Créditos IA
            </CardTitle>
            <CardDescription>
              {aiCredits.monthly > 0
                ? `Incluidos con el plan: ${aiCredits.monthly.toLocaleString()} / mes`
                : "Sin créditos IA incluidos en el plan actual"}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="text-3xl font-bold tabular-nums">
              {aiCredits.balance.toLocaleString()}
              <span className="text-sm font-normal text-muted-foreground ml-1">
                créditos disponibles
              </span>
            </div>
            {/* Movimientos recientes del ledger */}
            {ledgerLoading ? (
              <Skeleton className="h-16 w-full" />
            ) : ledgerRows.length > 0 ? (
              <div className="space-y-1.5">
                <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                  Últimos movimientos
                </p>
                {ledgerRows.slice(0, 5).map((entry) => (
                  <div
                    key={entry.id}
                    className="flex items-center justify-between text-xs"
                  >
                    <span className="text-muted-foreground truncate max-w-[180px]">
                      {entry.reason}
                    </span>
                    <span
                      className={
                        entry.delta >= 0
                          ? "text-emerald-600 font-medium tabular-nums"
                          : "text-destructive font-medium tabular-nums"
                      }
                    >
                      {entry.delta >= 0 ? "+" : ""}
                      {entry.delta.toLocaleString()}
                    </span>
                  </div>
                ))}
              </div>
            ) : null}
          </CardContent>
        </Card>

        {/* ── Créditos SMS ─────────────────────────────────────────────────── */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold flex items-center gap-2">
              <MessageSquare className="size-4 text-blue-500" />
              Créditos SMS
            </CardTitle>
            <CardDescription>
              Mensajes de texto disponibles para notificaciones
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="text-3xl font-bold tabular-nums">
              {smsCredit.toLocaleString()}
              <span className="text-sm font-normal text-muted-foreground ml-1">
                créditos
              </span>
            </div>
          </CardContent>
        </Card>

        {/* ── Balance de cuenta ────────────────────────────────────────────── */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold flex items-center gap-2">
              <CircleDollarSign className="size-4 text-emerald-500" />
              Balance de cuenta
            </CardTitle>
            <CardDescription>Saldo a favor en tu cuenta Punto</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="text-3xl font-bold tabular-nums">
              {formatMoney(balance, bootstrap)}
            </div>
          </CardContent>
        </Card>

        {/* ── Add-ons ─────────────────────────────────────────────────────── */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold flex items-center gap-2">
              <Sparkles className="size-4 text-amber-500" />
              Add-ons contratados
            </CardTitle>
            <CardDescription>Recursos adicionales activos en tu plan</CardDescription>
          </CardHeader>
          <CardContent>
            {addons.length === 0 ? (
              <EmptyState
                icon={PackageCheck}
                showMarquee={false}
                title="Sin add-ons activos"
                description="Los add-ons se activan a pedido del equipo de Punto."
                className="py-4"
              />
            ) : (
              <div className="space-y-2">
                {addons.map((addon) => (
                  <div
                    key={addon.key}
                    className="flex items-center justify-between text-sm"
                  >
                    <span className="text-muted-foreground">{addon.label}</span>
                    <span className="font-medium">
                      {addon.qty.toLocaleString()} uds.
                    </span>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* ── Historial de pagos ──────────────────────────────────────────────── */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold flex items-center gap-2">
            <Receipt className="size-4 text-muted-foreground" />
            Historial de pagos
          </CardTitle>
          <CardDescription>
            Últimos 50 comprobantes de pago a Punto
          </CardDescription>
        </CardHeader>
        <CardContent>
          {payments.length === 0 ? (
            <EmptyState
              icon={CreditCard}
              showMarquee={false}
              title="Sin pagos registrados"
              description="El historial de pagos aparecerá aquí una vez que se procesen."
            />
          ) : (
            <DataTable<BillingPayment>
              tableId="billing-payments"
              data={payments}
              columns={paymentColumns}
              getRowId={(row) => row.id}
              searchPlaceholder="Buscar comprobante…"
              exportFileName={null}
              emptyMessage="Sin pagos"
              pageSize={10}
            />
          )}
        </CardContent>
      </Card>

      {/* ── Dialog cambio de plan ───────────────────────────────────────────── */}
      <PlanDialog
        open={planDialogOpen}
        onClose={() => setPlanDialogOpen(false)}
        currentPlanCode={plan.code}
      />
    </div>
  )
}
