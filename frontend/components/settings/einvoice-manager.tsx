"use client"

import * as React from "react"
import { CreditCard, Loader2, ShieldCheck } from "lucide-react"
import { toast } from "sonner"
import type { CountryCode } from "libphonenumber-js"

import { Alert, AlertDescription } from "@/components/ui/alert"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Separator } from "@/components/ui/separator"
import { Skeleton } from "@/components/ui/skeleton"
import { Switch } from "@/components/ui/switch"
import { PhoneInput } from "@/components/forms/phone-input"
import { EmptyState } from "@/components/empty-state"
import { EInvoiceDocumentsCard } from "@/components/settings/einvoice-documents-table"

import {
  useEinvoiceAccount,
  useEinvoicePaymentMethods,
  useSaveEinvoiceAccount,
  useTestEinvoiceConnection,
} from "@/hooks/use-einvoice"
import { usePaymentMethods } from "@/hooks/use-payment-methods"
import { usePermission } from "@/hooks/use-permissions"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import type { EInvoiceConfig, EInvoiceEnvironment, EInvoiceStatus } from "@/lib/types/einvoice"

function StatusBadge({ status }: { status: EInvoiceStatus }) {
  if (status === "ok") return <Badge>Conectado</Badge>
  if (status === "auth_error") return <Badge variant="destructive">Error de autenticación</Badge>
  return <Badge variant="secondary">Sin configurar</Badge>
}

/**
 * Renderiza pares clave/valor de un payload crudo de Factomate (GetUserInfo
 * o sincro/config) — el spec no tipa las respuestas, así que se listan las
 * claves tal cual vienen en vez de asumir nombres de campo fijos (razón
 * social, RUC, número de timbrado, establecimiento, vigencia, etc. pueden
 * llamarse distinto según lo que realmente devuelva la cuenta).
 */
function RawKeyValueSummary({ data }: { data: Record<string, unknown> }) {
  const entries = Object.entries(data).filter(
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

function formatSyncedAt(iso: string | null): string | null {
  if (!iso) return null
  // stamp_synced_at es TIMESTAMPTZ genuino (now() del servidor), no un
  // timestamp "naive" de negocio — se parsea directo, sin el stripeo de
  // offset que usan los helpers de lib/format-date.ts para timestamps de
  // ventas/transacciones (ver ese archivo para el porqué de esa distinción).
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleString("es-PY", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
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
  const [phone, setPhone] = React.useState("")
  const [phoneCountry, setPhoneCountry] = React.useState<CountryCode>(DEFAULT_COUNTRY)
  const [phoneValid, setPhoneValid] = React.useState(false)
  const [environment, setEnvironment] = React.useState<EInvoiceEnvironment>("test")
  const [autoIssue, setAutoIssue] = React.useState(false)
  const [onlyWithTaxId, setOnlyWithTaxId] = React.useState(false)

  // La contraseña NUNCA se hidrata desde el backend (no vuelve en la
  // respuesta) — el resto sí, incluido el teléfono (ver comentario en
  // EInvoiceService::getAccount sobre por qué el teléfono vuelve en claro).
  React.useEffect(() => {
    if (!account) return
    setUsername(account.username)
    // account.phone viene sin '+' (convención de storage del proyecto);
    // PhoneInput espera E.164 con '+' para poder parsearlo como
    // internacional en vez de intentar leerlo como número nacional del
    // país seleccionado.
    setPhone(account.phone ? `+${account.phone}` : "")
    setPhoneValid(Boolean(account.phone))
    setEnvironment(account.environment)
    setAutoIssue(Boolean(account.config.autoIssue))
    setOnlyWithTaxId(Boolean(account.config.onlyWithTaxId))
  }, [account])

  function handleSaveConnection(e: React.FormEvent) {
    e.preventDefault()
    const trimmedUsername = username.trim()
    if (trimmedUsername === "") {
      toast.error("Ingresá el usuario de Factomate.")
      return
    }
    if (phone.trim() === "" || !phoneValid) {
      toast.error("Ingresá un teléfono del titular válido.")
      return
    }
    if (!account?.configured && password.trim() === "") {
      toast.error("Ingresá la contraseña para conectar la cuenta.")
      return
    }

    saveAccount.mutate(
      {
        username: trimmedUsername,
        phone,
        environment,
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
            description: result.lastError ?? "Revisá el usuario, la contraseña y el teléfono.",
          })
        }
      },
      onError: (err) => toast.error("No se pudo probar la conexión", { description: err.message }),
    })
  }

  /**
   * Persiste un pedazo de la config sin tocar el resto: el backend mergea
   * clave por clave (EInvoiceService::saveAccount), así que cada sección de
   * la pantalla manda solo lo suyo. Antes se mandaba la config completa desde
   * cada sección, y guardar un switch de emisión borraba el mapeo de medios
   * de pago.
   */
  const persistConfig = React.useCallback(
    (patch: EInvoiceConfig, errorTitle: string) => {
      saveAccount.mutate(
        { username, phone, environment, config: patch },
        { onError: (err) => toast.error(errorTitle, { description: err.message }) },
      )
    },
    [saveAccount, username, phone, environment],
  )

  // Los switches de emisión persisten al toque solo si ya hay cuenta
  // conectada (guardar config sin cuenta todavía no tiene sentido — el
  // primer guardado siempre pasa por handleSaveConnection). Antes de eso
  // solo actualizan el estado local; se mandan junto con la conexión inicial.
  function handleEmissionChange(patch: { autoIssue?: boolean; onlyWithTaxId?: boolean }) {
    const nextAutoIssue = patch.autoIssue ?? autoIssue
    const nextOnlyWithTaxId = patch.onlyWithTaxId ?? onlyWithTaxId
    setAutoIssue(nextAutoIssue)
    setOnlyWithTaxId(nextOnlyWithTaxId)

    if (!account?.configured) return

    persistConfig(
      { autoIssue: nextAutoIssue, onlyWithTaxId: nextOnlyWithTaxId },
      "No se pudo guardar la configuración de emisión",
    )
  }

  const syncedAt = formatSyncedAt(account?.stampSyncedAt ?? null)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Facturación Electrónica</h1>
        <p className="text-sm text-muted-foreground">
          Conectá la cuenta de Factomate de tu comercio para emitir facturas electrónicas habilitadas por la SET.
        </p>
      </header>

      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-3">
            <div>
              <CardTitle>Conexión</CardTitle>
              <CardDescription>Usuario, teléfono del titular y entorno de tu cuenta de Factomate.</CardDescription>
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
                    placeholder="Usuario de Factomate"
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
                    placeholder={account?.configured ? "••••••••" : "Contraseña de Factomate"}
                    autoComplete="new-password"
                    disabled={!canManage}
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="einvoice-phone">Teléfono del titular</Label>
                  <PhoneInput
                    id="einvoice-phone"
                    value={phone}
                    country={phoneCountry}
                    onChange={(v) => {
                      setPhone(v.e164 ?? v.value)
                      setPhoneCountry(v.country)
                      setPhoneValid(v.isValid)
                    }}
                    disabled={!canManage}
                  />
                  <p className="text-xs text-muted-foreground">
                    Factomate lo exige en todas las llamadas — sin él, la cuenta no puede autenticar.
                  </p>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="einvoice-environment">Entorno</Label>
                  <Select
                    value={environment}
                    onValueChange={(v) => setEnvironment(v as EInvoiceEnvironment)}
                    disabled={!canManage}
                  >
                    <SelectTrigger id="einvoice-environment">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="test">Prueba</SelectItem>
                      <SelectItem value="prod">Producción</SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">
                    En Prueba no se emiten documentos fiscales reales — usalo mientras validás la conexión.
                  </p>
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
                    <RawKeyValueSummary data={account.emitter} />
                  </div>
                </>
              )}

              {account?.status === "ok" && account.stamp && Object.keys(account.stamp).length > 0 && (
                <>
                  <Separator />
                  <div className="space-y-2">
                    <div className="flex items-baseline justify-between gap-3">
                      <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Timbrado vigente
                      </p>
                      {syncedAt && (
                        <p className="text-xs text-muted-foreground">Sincronizado {syncedAt}</p>
                      )}
                    </div>
                    <RawKeyValueSummary data={account.stamp} />
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

      <PaymentMethodMappingCard
        connected={account?.status === "ok"}
        config={account?.config}
        canManage={canManage}
        saving={saveAccount.isPending}
        onSave={persistConfig}
      />

      <EInvoiceDocumentsCard />
    </div>
  )
}

/** Sentinel del `<Select>`: shadcn no admite `value=""` en un `<SelectItem>`. */
const UNMAPPED = "__default__"

/**
 * Mapeo medio de pago de Punto → código de medio de pago de SIFEN. La factura
 * declara con qué se pagó; los códigos los define la SET y Factomate los expone
 * por cuenta, así que la lista de la derecha se trae del proveedor y no se
 * hardcodea.
 *
 * El mapa se keyea por `taxonomyId` del medio (identidad estable del método,
 * igual que `finAccountMap` de Finanzas) — no por el nombre, que el comercio
 * puede renombrar.
 */
function PaymentMethodMappingCard({
  connected,
  config,
  canManage,
  saving,
  onSave,
}: {
  connected: boolean
  config: EInvoiceConfig | undefined
  canManage: boolean
  saving: boolean
  onSave: (patch: EInvoiceConfig, errorTitle: string) => void
}) {
  const { data: methodsData, isLoading: loadingMethods } = usePaymentMethods()
  const { data: sifenCodes, isLoading: loadingCodes, error: codesError } = useEinvoicePaymentMethods(connected)

  const methods = methodsData?.paymentMethods ?? []
  const map = React.useMemo(() => config?.paymentMethodMap ?? {}, [config])
  const defaultCode = config?.defaultPaymentMethodCode ?? 1

  function handleMethodChange(methodId: string, value: string) {
    const next = { ...map }
    if (value === UNMAPPED) {
      delete next[methodId]
    } else {
      next[methodId] = Number(value)
    }
    onSave({ paymentMethodMap: next }, "No se pudo guardar el mapeo de medios de pago")
  }

  function handleDefaultChange(value: string) {
    onSave({ defaultPaymentMethodCode: Number(value) }, "No se pudo guardar el medio de pago por defecto")
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Medios de pago</CardTitle>
        <CardDescription>
          Con qué código de la SET se declara cada medio de pago tuyo. Los que no asignes se emiten con el
          medio por defecto.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {!connected ? (
          <EmptyState
            icon={CreditCard}
            title="Conectá la cuenta primero"
            description="Los códigos de medio de pago los publica Factomate para tu emisor — se cargan al probar la conexión."
            ghost={false}
          />
        ) : codesError ? (
          <Alert variant="destructive">
            <AlertDescription>
              No se pudieron traer los códigos de medio de pago de Factomate: {codesError.message}
            </AlertDescription>
          </Alert>
        ) : loadingMethods || loadingCodes ? (
          <div className="flex flex-col gap-3">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
          </div>
        ) : (
          <>
            <div className="flex flex-col gap-3">
              {methods.map((method) => (
                <div key={method.id} className="flex items-center justify-between gap-4">
                  <Label htmlFor={`einvoice-pm-${method.id}`} className="font-normal">
                    {method.name}
                  </Label>
                  <Select
                    value={map[method.id] !== undefined ? String(map[method.id]) : UNMAPPED}
                    onValueChange={(v) => handleMethodChange(method.id, v)}
                    disabled={!canManage || saving}
                  >
                    <SelectTrigger id={`einvoice-pm-${method.id}`} className="w-56">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={UNMAPPED}>Por defecto</SelectItem>
                      {(sifenCodes ?? []).map((c) => (
                        <SelectItem key={c.code} value={String(c.code)}>
                          {c.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              ))}
            </div>

            <Separator />

            <div className="flex items-center justify-between gap-4">
              <div className="space-y-0.5">
                <Label htmlFor="einvoice-pm-default">Medio de pago por defecto</Label>
                <p className="text-sm text-muted-foreground">
                  Se usa cuando el medio de pago de la venta no está asignado a ningún código.
                </p>
              </div>
              <Select
                value={String(defaultCode)}
                onValueChange={handleDefaultChange}
                disabled={!canManage || saving}
              >
                <SelectTrigger id="einvoice-pm-default" className="w-56">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {(sifenCodes ?? []).map((c) => (
                    <SelectItem key={c.code} value={String(c.code)}>
                      {c.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}
