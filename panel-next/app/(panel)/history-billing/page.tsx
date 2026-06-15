"use client"

import * as React from "react"
import {
  Wallet,
  Sparkles,
  Receipt,
  Clock,
  XCircle,
  Loader2,
} from "lucide-react"

import type { ColumnDef } from "@tanstack/react-table"
import type { BillingInvoice, BillingPack } from "@/lib/types/billing"

import { useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"

import {
  useBilling,
  useBillingInvoices,
  useBillingPacks,
  useCheckout,
} from "@/hooks/use-billing"

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

function invoiceStatusBadge(status: BillingInvoice["status"]) {
  switch (status) {
    case "paid":
      return <Badge variant="secondary">Pagado</Badge>
    case "pending":
      return <Badge variant="outline">Pendiente</Badge>
    case "failed":
      return <Badge variant="outline">Fallido</Badge>
    case "refunded":
      return <Badge variant="outline">Reembolsado</Badge>
    case "cancelled":
      return <Badge variant="outline">Cancelado</Badge>
  }
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

const invoiceColumns: ColumnDef<BillingInvoice, unknown>[] = [
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
    cell: ({ row }) => row.original.invoice || "—",
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
    cell: ({ row }) => invoiceStatusBadge(row.original.status),
  },
]

// ── sub-components ────────────────────────────────────────────────────────────

function FacturasCard() {
  const { data, isLoading } = useBillingInvoices()
  const invoices = data?.invoices ?? []

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold">Facturas</CardTitle>
        <CardDescription>
          Tus pagos de suscripción y compras de créditos.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
        ) : invoices.length === 0 ? (
          <EmptyState
            icon={Receipt}
            title="Sin facturas todavía"
            description="Tus comprobantes de suscripción y compras de créditos van a aparecer acá."
          />
        ) : (
          <DataTable<BillingInvoice>
            tableId="billing-invoices"
            data={invoices}
            columns={invoiceColumns}
            getRowId={(row) => row.id}
            searchPlaceholder="Buscar comprobante…"
            exportFileName={null}
            pageSize={10}
          />
        )}
      </CardContent>
    </Card>
  )
}

function PackCard({ pack }: { pack: BillingPack }) {
  const checkout = useCheckout()

  function handleComprar() {
    checkout.mutate(
      { packId: pack.id },
      {
        onSuccess: (res) => {
          if (res.redirectUrl) {
            window.location.href = res.redirectUrl
          }
        },
        onError: () => {
          toast.error("No se pudo iniciar el pago. Intentá de nuevo.")
        },
      },
    )
  }

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base font-semibold">{pack.name}</CardTitle>
        <CardDescription>
          {nf.format(pack.credits)} créditos · válido {pack.validityDays} días
        </CardDescription>
      </CardHeader>
      <CardContent className="flex items-center justify-between gap-4">
        <span className="text-2xl font-bold tracking-tight tabular-nums">
          USD {pack.priceUsd.toFixed(2)}
        </span>
        <Button
          size="sm"
          onClick={handleComprar}
          disabled={checkout.isPending}
          className="gap-2"
        >
          {checkout.isPending ? (
            <>
              <Loader2 className="size-4 animate-spin" />
              Redirigiendo…
            </>
          ) : (
            "Comprar"
          )}
        </Button>
      </CardContent>
    </Card>
  )
}

function ComprarCreditosTab() {
  const { data, isLoading } = useBillingPacks()
  const packs = data?.packs ?? []
  const paymentsEnabled = data?.paymentsEnabled ?? false

  if (isLoading) {
    return (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Skeleton className="h-32 rounded-xl" />
        <Skeleton className="h-32 rounded-xl" />
        <Skeleton className="h-32 rounded-xl" />
      </div>
    )
  }

  if (!paymentsEnabled || packs.length === 0) {
    return (
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
    )
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {packs.map((pack) => (
        <PackCard key={pack.id} pack={pack} />
      ))}
    </div>
  )
}

// ── main page ─────────────────────────────────────────────────────────────────

export default function HistoryBillingPage() {
  const { data: billing, isLoading, isError } = useBilling()
  const qc = useQueryClient()

  // Manejo del retorno de dLocal (?checkout=success)
  React.useEffect(() => {
    if (typeof window === "undefined") return
    const params = new URLSearchParams(window.location.search)
    if (params.get("checkout") !== "success") return

    toast.success(
      "¡Pago recibido! Tus créditos se acreditan en unos instantes.",
    )
    void qc.invalidateQueries({ queryKey: ["billing"] })

    // Segunda invalidación a los 4 s (el webhook es async)
    const timer = setTimeout(() => {
      void qc.invalidateQueries({ queryKey: ["billing"] })
    }, 4000)

    // Limpiar el query string de la URL
    window.history.replaceState(
      {},
      "",
      window.location.pathname,
    )

    return () => clearTimeout(timer)
  }, [qc])

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

  const { plan, trial, aiCredits } = billing

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

        {/* Movimientos: facturas */}
        <TabsContent value="movimientos" className="mt-4">
          <FacturasCard />
        </TabsContent>

        {/* Comprar créditos: packs dLocal */}
        <TabsContent value="comprar" className="mt-4">
          <ComprarCreditosTab />
        </TabsContent>
      </Tabs>
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
