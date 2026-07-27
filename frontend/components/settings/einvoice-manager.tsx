"use client"

import * as React from "react"
import { Loader2, ShieldCheck } from "lucide-react"
import { toast } from "sonner"

import { Alert, AlertDescription } from "@/components/ui/alert"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Separator } from "@/components/ui/separator"
import { Skeleton } from "@/components/ui/skeleton"
import { Switch } from "@/components/ui/switch"

import {
  useEinvoiceAccount,
  useSaveEinvoiceAccount,
  useTestEinvoiceConnection,
} from "@/hooks/use-einvoice"
import { usePermission } from "@/hooks/use-permissions"
import type { EInvoiceStatus } from "@/lib/types/einvoice"

function StatusBadge({ status }: { status: EInvoiceStatus }) {
  if (status === "ok") return <Badge>Conectado</Badge>
  if (status === "auth_error") return <Badge variant="destructive">Error de autenticación</Badge>
  return <Badge variant="secondary">Sin configurar</Badge>
}

/**
 * Datos del emisor devueltos por Automate (`/auth/me`) — el spec no tipa la
 * respuesta, así que se listan las claves tal cual vienen en vez de asumir
 * nombres de campo fijos (razón social, RUC, etc. pueden llamarse distinto
 * según lo que realmente devuelva la cuenta de prueba).
 */
function EmitterSummary({ emitter }: { emitter: Record<string, unknown> }) {
  const entries = Object.entries(emitter).filter(
    ([, value]) => typeof value === "string" || typeof value === "number",
  )
  if (entries.length === 0) return null

  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-1.5 sm:grid-cols-2">
      {entries.map(([key, value]) => (
        <div key={key} className="flex items-baseline justify-between gap-3 sm:justify-start">
          <dt className="text-xs text-muted-foreground">{key}</dt>
          <dd className="text-sm">{String(value)}</dd>
        </div>
      ))}
    </dl>
  )
}

export function EInvoiceManager() {
  const { data: account, isLoading } = useEinvoiceAccount()
  const saveAccount = useSaveEinvoiceAccount()
  const testConnection = useTestEinvoiceConnection()
  // El backend ya gatea la escritura con `einvoice.manage` (api/v1/einvoice.php).
  // Acá se refleja en la UI para que el usuario sin permiso no descubra el 403
  // recién al apretar Guardar — mismo criterio que /produccion.
  const canManage = usePermission("einvoice.manage")

  const [username, setUsername] = React.useState("")
  const [password, setPassword] = React.useState("")
  const [autoIssue, setAutoIssue] = React.useState(false)
  const [onlyWithTaxId, setOnlyWithTaxId] = React.useState(false)

  // La contraseña NUNCA se hidrata desde el backend (no vuelve en la
  // respuesta) — solo el usuario y la config sincronizan con la cuenta cargada.
  React.useEffect(() => {
    if (!account) return
    setUsername(account.username)
    setAutoIssue(Boolean(account.config.autoIssue))
    setOnlyWithTaxId(Boolean(account.config.onlyWithTaxId))
  }, [account])

  function handleSaveConnection(e: React.FormEvent) {
    e.preventDefault()
    const trimmedUsername = username.trim()
    if (trimmedUsername === "") {
      toast.error("Ingresá el usuario de Automate.")
      return
    }
    if (!account?.configured && password.trim() === "") {
      toast.error("Ingresá la contraseña para conectar la cuenta.")
      return
    }

    saveAccount.mutate(
      {
        username: trimmedUsername,
        password: password.trim() === "" ? undefined : password.trim(),
        config: { autoIssue, onlyWithTaxId },
      },
      {
        onSuccess: () => {
          toast.success("Cuenta guardada. Probá la conexión para verificarla.")
          setPassword("")
        },
        onError: (err) => toast.error("No se pudo guardar la cuenta", { description: err.message }),
      },
    )
  }

  function handleTest() {
    testConnection.mutate(undefined, {
      onSuccess: (result) => {
        if (result.status === "ok") {
          toast.success("Conexión exitosa.")
        } else {
          toast.error("No se pudo conectar", {
            description: result.lastError ?? "Revisá el usuario y la contraseña.",
          })
        }
      },
      onError: (err) => toast.error("No se pudo probar la conexión", { description: err.message }),
    })
  }

  // Los switches de emisión persisten al toque solo si ya hay cuenta
  // conectada (guardar config sin usuario todavía no tiene sentido — el
  // primer guardado siempre pasa por handleSaveConnection). Antes de eso
  // solo actualizan el estado local; se mandan junto con la conexión inicial.
  function handleEmissionChange(patch: { autoIssue?: boolean; onlyWithTaxId?: boolean }) {
    const nextAutoIssue = patch.autoIssue ?? autoIssue
    const nextOnlyWithTaxId = patch.onlyWithTaxId ?? onlyWithTaxId
    setAutoIssue(nextAutoIssue)
    setOnlyWithTaxId(nextOnlyWithTaxId)

    if (!account?.configured) return

    saveAccount.mutate(
      { username, config: { autoIssue: nextAutoIssue, onlyWithTaxId: nextOnlyWithTaxId } },
      {
        onError: (err) =>
          toast.error("No se pudo guardar la configuración de emisión", { description: err.message }),
      },
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Facturación Electrónica</h1>
        <p className="text-sm text-muted-foreground">
          Conectá la cuenta de Automate de tu comercio para emitir facturas electrónicas habilitadas por la SET.
        </p>
      </header>

      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-3">
            <div>
              <CardTitle>Conexión</CardTitle>
              <CardDescription>Usuario y contraseña de tu cuenta de Automate.</CardDescription>
            </div>
            {isLoading ? <Skeleton className="h-5 w-24" /> : <StatusBadge status={account?.status ?? "unconfigured"} />}
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {isLoading ? (
            <div className="flex flex-col gap-3">
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
            </div>
          ) : (
            <form className="flex flex-col gap-4" onSubmit={handleSaveConnection}>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="einvoice-username">Usuario</Label>
                  <Input
                    id="einvoice-username"
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    placeholder="Usuario de Automate"
                    autoComplete="off"
                    disabled={!canManage}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="einvoice-password">Contraseña</Label>
                  <Input
                    id="einvoice-password"
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder={account?.configured ? "••••••••" : "Contraseña de Automate"}
                    autoComplete="new-password"
                    disabled={!canManage}
                  />
                </div>
              </div>

              {account?.lastError && (
                <Alert variant="destructive">
                  <AlertDescription>{account.lastError}</AlertDescription>
                </Alert>
              )}

              {account?.status === "ok" && account.emitter && Object.keys(account.emitter).length > 0 && (
                <>
                  <Separator />
                  <div className="space-y-2">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Datos del emisor
                    </p>
                    <EmitterSummary emitter={account.emitter} />
                  </div>
                </>
              )}

              {!canManage && (
                <p className="text-xs text-muted-foreground">
                  No tenés permiso para modificar la conexión. Pedile a un administrador el permiso
                  de gestión de facturación electrónica.
                </p>
              )}

              <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={saveAccount.isPending || !canManage}>
                  {saveAccount.isPending && <Loader2 className="size-4 animate-spin" />}
                  Guardar
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  onClick={handleTest}
                  disabled={testConnection.isPending || !account?.configured || !canManage}
                >
                  {testConnection.isPending ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <ShieldCheck className="size-4" />
                  )}
                  Probar conexión
                </Button>
              </div>
            </form>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Emisión</CardTitle>
          <CardDescription>
            Estas preferencias quedan guardadas ahora; la emisión automática se activa en la próxima fase.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex items-center justify-between gap-3">
            <div className="space-y-0.5">
              <Label htmlFor="einvoice-auto-issue">Emitir automáticamente en cada venta</Label>
              <p className="text-xs text-muted-foreground">
                Cada venta generará su factura electrónica sin intervención manual.
              </p>
            </div>
            <Switch
              id="einvoice-auto-issue"
              checked={autoIssue}
              disabled={isLoading || !canManage}
              onCheckedChange={(checked) => handleEmissionChange({ autoIssue: checked })}
            />
          </div>
          <div className="flex items-center justify-between gap-3">
            <div className="space-y-0.5">
              <Label htmlFor="einvoice-only-tax-id">Solo ventas con RUC/CI del cliente</Label>
              <p className="text-xs text-muted-foreground">
                Las ventas sin datos fiscales del cliente no generan factura electrónica.
              </p>
            </div>
            <Switch
              id="einvoice-only-tax-id"
              checked={onlyWithTaxId}
              disabled={isLoading || !canManage}
              onCheckedChange={(checked) => handleEmissionChange({ onlyWithTaxId: checked })}
            />
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
