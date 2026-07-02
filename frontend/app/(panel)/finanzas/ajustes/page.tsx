"use client"

import * as React from "react"
import Link from "next/link"
import { Wallet, History } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { EmptyState } from "@/components/empty-state"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { useFinanceAccounts } from "@/hooks/use-finance-accounts"
import { useFinanceConfig, useUpdateFinanceConfig } from "@/hooks/use-finance-config"
import { useFinanceBackfill } from "@/hooks/use-finance-backfill"

const CASH_ACCOUNT_VALUE = "__efectivo__"

export default function FinanzasAjustesPage() {
  const { data: accounts, isLoading: isLoadingAccounts } = useFinanceAccounts()
  const { data: methods, isLoading: isLoadingConfig } = useFinanceConfig()
  const updateConfig = useUpdateFinanceConfig()
  const backfill = useFinanceBackfill()

  const [localMap, setLocalMap] = React.useState<Record<string, string | null>>({})
  const [backfillDialogOpen, setBackfillDialogOpen] = React.useState(false)

  const handleBackfill = async () => {
    try {
      const result = await backfill.mutateAsync()
      toast.success("Histórico importado", {
        description: `${result.movementsCreated} movimiento(s) nuevo(s) generado(s).`,
      })
    } catch (e) {
      toast.error("No se pudo importar el histórico", {
        description: e instanceof Error ? e.message : undefined,
      })
    } finally {
      setBackfillDialogOpen(false)
    }
  }

  React.useEffect(() => {
    if (methods) {
      const next: Record<string, string | null> = {}
      for (const m of methods) {
        if (!m.isCash) {
          next[m.methodId] = m.accountId ?? null
        }
      }
      setLocalMap(next)
    }
  }, [methods])

  // Mantiene 'wallet' junto a 'bank' (comportamiento previo de la page, no achicar alcance).
  const eligibleAccounts = (accounts ?? []).filter(
    (a) => a.type === "bank" || a.type === "wallet",
  )

  const editableMethods = (methods ?? []).filter((m) => !m.isCash)
  const cashMethod = (methods ?? []).find((m) => m.isCash)

  const handleSave = async () => {
    try {
      await updateConfig.mutateAsync(localMap)
      toast.success("Configuración guardada")
    } catch (e) {
      toast.error("No se pudo guardar la configuración", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  if (isLoadingAccounts || isLoadingConfig) {
    return <Skeleton className="h-96 w-full" />
  }

  if (!methods || methods.length === 0) {
    return (
      <EmptyState
        icon={Wallet}
        title="Sin medios de pago configurados"
        description="Creá tus medios de pago en la configuración del comercio para poder asignarles una cuenta acá."
      />
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">
            ¿A qué cuenta va cada medio de pago?
          </CardTitle>
          <CardDescription>
            Los cobros de la caja se acreditan automáticamente en la cuenta que elijas acá.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {eligibleAccounts.length === 0 && (
            <div className="rounded-md border bg-muted/30 p-4 text-sm text-muted-foreground">
              Todavía no creaste ninguna cuenta bancaria o billetera. Creá una en{" "}
              <Link href="/finanzas/cuentas" className="font-medium text-foreground hover:underline">
                Cuentas
              </Link>{" "}
              para poder asignarle medios de pago.
            </div>
          )}

          {cashMethod && (
            <div className="flex items-center justify-between gap-4 rounded-md border px-4 py-3">
              <span className="text-sm font-medium">{cashMethod.methodName}</span>
              <span className="text-sm text-muted-foreground">Efectivo (fijo)</span>
            </div>
          )}

          {editableMethods.map((method) => (
            <div key={method.methodId} className="flex items-center justify-between gap-4 rounded-md border px-4 py-3">
              <span className="text-sm font-medium">{method.methodName}</span>
              <Select
                value={localMap[method.methodId] ?? CASH_ACCOUNT_VALUE}
                onValueChange={(v) =>
                  setLocalMap((prev) => ({
                    ...prev,
                    [method.methodId]: v === CASH_ACCOUNT_VALUE ? null : v,
                  }))
                }
              >
                <SelectTrigger className="w-[240px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={CASH_ACCOUNT_VALUE}>Efectivo (default)</SelectItem>
                  {eligibleAccounts.map((account) => (
                    <SelectItem key={account.id} value={account.id}>
                      {account.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          ))}
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button onClick={() => void handleSave()} disabled={updateConfig.isPending}>
          Guardar
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">
            Importar histórico
          </CardTitle>
          <CardDescription>
            Genera los movimientos de Finanzas a partir de las ventas, pagos de crédito,
            compras y movimientos de caja que ya existen en el sistema. Se puede ejecutar
            más de una vez — no duplica movimientos ya importados.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button
            variant="outline"
            onClick={() => setBackfillDialogOpen(true)}
            disabled={backfill.isPending}
          >
            <History className="size-4" />
            Importar histórico
          </Button>
        </CardContent>
      </Card>

      <AlertDialog open={backfillDialogOpen} onOpenChange={setBackfillDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Importar histórico de Finanzas</AlertDialogTitle>
            <AlertDialogDescription>
              Se van a generar movimientos para toda la operación histórica (ventas,
              pagos de crédito, compras y caja) que todavía no tenga su movimiento en
              Finanzas. Puede tardar unos segundos según el volumen de datos.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={() => void handleBackfill()} disabled={backfill.isPending}>
              {backfill.isPending ? "Importando…" : "Importar"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
