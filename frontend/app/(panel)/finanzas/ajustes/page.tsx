"use client"

import * as React from "react"
import Link from "next/link"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useFinanceAccounts } from "@/hooks/use-finance-accounts"
import {
  MANAGED_PAYMENT_METHODS,
  PAYMENT_METHOD_LABELS,
  useFinanceConfig,
  useUpdateFinanceConfig,
  type ManagedPaymentMethod,
} from "@/hooks/use-finance-config"

const CASH_ACCOUNT_VALUE = "__efectivo__"

export default function FinanzasAjustesPage() {
  const { data: accounts, isLoading: isLoadingAccounts } = useFinanceAccounts()
  const { data: config, isLoading: isLoadingConfig } = useFinanceConfig()
  const updateConfig = useUpdateFinanceConfig()

  const [localMap, setLocalMap] = React.useState<Partial<Record<ManagedPaymentMethod, string | null>>>({})

  React.useEffect(() => {
    if (config) {
      const next: Partial<Record<ManagedPaymentMethod, string | null>> = {}
      for (const key of MANAGED_PAYMENT_METHODS) {
        next[key] = config[key] ?? null
      }
      setLocalMap(next)
    }
  }, [config])

  const eligibleAccounts = (accounts ?? []).filter(
    (a) => a.type === "bank" || a.type === "wallet",
  )

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

          <div className="flex items-center justify-between gap-4 rounded-md border px-4 py-3">
            <span className="text-sm font-medium">Efectivo</span>
            <span className="text-sm text-muted-foreground">Efectivo (fijo)</span>
          </div>

          {MANAGED_PAYMENT_METHODS.map((key) => (
            <div key={key} className="flex items-center justify-between gap-4 rounded-md border px-4 py-3">
              <span className="text-sm font-medium">{PAYMENT_METHOD_LABELS[key]}</span>
              <Select
                value={localMap[key] ?? CASH_ACCOUNT_VALUE}
                onValueChange={(v) =>
                  setLocalMap((prev) => ({ ...prev, [key]: v === CASH_ACCOUNT_VALUE ? null : v }))
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
    </div>
  )
}
