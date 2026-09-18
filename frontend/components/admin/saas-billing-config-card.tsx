"use client"

import * as React from "react"
import { Loader2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { CompanyCombobox } from "@/components/admin/company-combobox"

import {
  useAdminPlatformConfig,
  useAdminSetSaasBilling,
  useAdminTenantOutlets,
  useAdminTenantRegisters,
} from "@/hooks/use-admin"

/**
 * F5 (context/34-admin-saas-plan.md) — tenant/sucursal/caja emisor de la
 * facturación SaaS (dogfooding). Elegir un tenant nuevo marca
 * `company.isinternal=1` en él (y lo desmarca en el anterior) — efecto
 * colateral aplicado server-side en el mismo POST.
 */
export function SaasBillingConfigCard() {
  const { data, isLoading } = useAdminPlatformConfig()
  const cfg = data?.saasBilling
  const setSaasBilling = useAdminSetSaasBilling()

  const [tenantId, setTenantId] = React.useState("")
  const [tenantName, setTenantName] = React.useState("")
  const [outletId, setOutletId] = React.useState("")
  const [registerId, setRegisterId] = React.useState("")
  const [enabled, setEnabled] = React.useState(false)
  const [seeded, setSeeded] = React.useState(false)

  // Seed una sola vez con lo que ya está guardado (no pisar mientras el admin edita).
  React.useEffect(() => {
    if (cfg && !seeded) {
      setTenantId(cfg.tenantId ?? "")
      setTenantName(cfg.tenantName ?? "")
      setOutletId(cfg.outletId ?? "")
      setRegisterId(cfg.registerId ?? "")
      setEnabled(cfg.enabled)
      setSeeded(true)
    }
  }, [cfg, seeded])

  const { data: outletsData, isLoading: outletsLoading } = useAdminTenantOutlets(tenantId)
  const outlets = outletsData?.outlets ?? []
  const { data: registersData, isLoading: registersLoading } = useAdminTenantRegisters(tenantId, outletId)
  const registers = registersData?.registers ?? []

  const onPickTenant = (id: string, name: string) => {
    if (id === tenantId) return
    setTenantId(id)
    setTenantName(name)
    // Cambiar de tenant invalida sucursal/caja elegidas — no tiene sentido
    // arrastrar un outletId/registerId de OTRO tenant.
    setOutletId("")
    setRegisterId("")
  }

  const onSave = () => {
    if (enabled && (!tenantId || !outletId || !registerId)) {
      toast.error("Elegí tenant, sucursal y caja antes de habilitar")
      return
    }
    setSaasBilling.mutate(
      { tenantId, outletId, registerId, enabled },
      {
        onSuccess: () => toast.success("Facturación del SaaS: guardado"),
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">Facturación del SaaS</CardTitle>
        <CardDescription>
          Tenant Punto propio donde se emite, como venta real, la suscripción de cada cliente
          (dogfooding — numeración fiscal y factura electrónica salen del rail normal de venta).
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Cargando…</p>
        ) : (
          <>
            <div className="space-y-1.5">
              <Label>Tenant emisor</Label>
              <CompanyCombobox
                value={tenantId}
                valueName={tenantName}
                placeholder="Buscar tenant…"
                onPick={(c, name) => onPickTenant(c.id, name)}
              />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label>Sucursal</Label>
                <Select
                  value={outletId || undefined}
                  onValueChange={(v) => {
                    setOutletId(v)
                    setRegisterId("")
                  }}
                  disabled={!tenantId || outletsLoading}
                >
                  <SelectTrigger>
                    <SelectValue placeholder={tenantId ? "Elegí una sucursal" : "Elegí un tenant primero"} />
                  </SelectTrigger>
                  <SelectContent>
                    {outlets.map((o) => (
                      <SelectItem key={o.id} value={o.id}>
                        {o.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label>Caja</Label>
                <Select
                  value={registerId || undefined}
                  onValueChange={setRegisterId}
                  disabled={!outletId || registersLoading}
                >
                  <SelectTrigger>
                    <SelectValue placeholder={outletId ? "Elegí una caja" : "Elegí una sucursal primero"} />
                  </SelectTrigger>
                  <SelectContent>
                    {registers.map((r) => (
                      <SelectItem key={r.id} value={r.id}>
                        {r.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="flex items-center justify-between rounded-md border p-3">
              <div className="space-y-0.5">
                <Label htmlFor="saas-billing-enabled">Habilitada</Label>
                <p className="text-xs text-muted-foreground">
                  Con esto apagado, el webhook de pagos NO emite venta — solo queda disponible el botón manual.
                </p>
              </div>
              <Switch id="saas-billing-enabled" checked={enabled} onCheckedChange={setEnabled} />
            </div>

            <div className="flex justify-end">
              <Button size="sm" onClick={onSave} disabled={setSaasBilling.isPending}>
                {setSaasBilling.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Guardar
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}
