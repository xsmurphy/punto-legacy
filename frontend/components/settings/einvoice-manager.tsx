"use client"

/**
 * Facturación Electrónica — configuración del comercio (F7, white-label).
 *
 * El comercio NUNCA ve al proveedor de FE, y NO re-tipea lo que Punto ya
 * tiene: el RUC y la razón social salen de Configuración del negocio, y los
 * timbrados de las CAJAS — cada caja es un punto de expedición (context/29
 * §1), la sección "Timbrados por caja" edita LA CAJA, no una copia. Acá solo
 * se completa lo que no existe en otro lado (actividad económica, tipo de
 * contribuyente, email, CSC, certificado). Punto provisiona la cuenta por
 * detrás con su credencial admin (EInvoiceProvisioningService).
 *
 * Dos estados:
 *   - Sin provisionar → formulario de alta (datos fiscales + timbrado).
 *   - Provisionado    → estado del emisor + certificado + emisión +
 *                       medios de pago + documentos.
 */

import * as React from "react"
import { CreditCard, Loader2, ShieldCheck, Upload } from "lucide-react"
import { toast } from "sonner"

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
import { EmptyState } from "@/components/empty-state"
import { EInvoiceDocumentsCard } from "@/components/settings/einvoice-documents-table"

import {
  useEinvoiceAccount,
  useEinvoicePaymentMethods,
  useProvisionEinvoice,
  useSaveEinvoiceConfig,
  useTestEinvoiceConnection,
  useTestEinvoiceSet,
  useUploadEinvoiceCert,
} from "@/hooks/use-einvoice"
import { usePaymentMethods } from "@/hooks/use-payment-methods"
import { usePermission } from "@/hooks/use-permissions"
import { useRegistersAdmin, useUpdateRegister, type RegisterFiscal, type RegisterListItem } from "@/hooks/use-registers-admin"
import { useSettings } from "@/hooks/use-settings"
import type { EInvoiceConfig, EInvoiceFiscalForm, EInvoiceStatus } from "@/lib/types/einvoice"
import Link from "next/link"

function StatusBadge({ status }: { status: EInvoiceStatus }) {
  if (status === "ok") return <Badge>Habilitado</Badge>
  if (status === "provisioning") return <Badge variant="secondary">Alta en proceso</Badge>
  if (status === "auth_error") return <Badge variant="destructive">Necesita atención</Badge>
  return <Badge variant="secondary">Sin configurar</Badge>
}

function formatSyncedAt(iso: string | null): string | null {
  if (!iso) return null
  // stamp_synced_at es TIMESTAMPTZ genuino (now() del servidor) — se parsea
  // directo, sin el stripeo de offset de lib/format-date.ts (ver ese archivo).
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
  // El backend ya gatea la escritura con `einvoice.manage`; acá se refleja
  // para que el usuario sin permiso no descubra el 403 recién al guardar.
  const canManage = usePermission("einvoice.manage")

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Facturación Electrónica</h1>
        <p className="text-sm text-muted-foreground">
          Emití facturas electrónicas habilitadas por la SET (SIFEN) directo desde tus ventas.
        </p>
      </header>

      {isLoading ? (
        <Card>
          <CardHeader>
            <Skeleton className="h-5 w-40" />
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
          </CardContent>
        </Card>
      ) : !account?.provisioned ? (
        <ProvisionForm
          canManage={canManage}
          initial={account?.fiscal}
          lastError={account?.lastError ?? null}
        />
      ) : (
        <ProvisionedView canManage={canManage} account={account} />
      )}
    </div>
  )
}

// ── Alta del emisor — formulario legal ──────────────────────────────────────

const EMPTY_FORM: EInvoiceFiscalForm = {
  email: "",
  taxpayerType: 2,
  actividadCodigo: "",
  actividadNombre: "",
  cscId: "",
  cscSecret: "",
  infoAdicional: "",
}

/**
 * RUC y razón social — solo LECTURA: la fuente es Configuración del negocio.
 * Si faltan, el link lleva a cargarlos ahí (un solo lugar por dato).
 */
function CompanyFiscalSummary() {
  const { data: settings, isLoading } = useSettings()
  const ruc = settings?.ruc?.trim() ?? ""
  const billingName = settings?.billingName?.trim() || settings?.name?.trim() || ""
  const missing = !isLoading && (ruc === "" || billingName === "")

  return (
    <div className="flex flex-col gap-2">
      <dl className="grid grid-cols-1 gap-x-6 gap-y-1.5 sm:grid-cols-2">
        <div className="flex items-baseline justify-between gap-3 sm:justify-start">
          <dt className="text-xs text-muted-foreground">RUC</dt>
          <dd className="text-sm tabular-nums">{isLoading ? "…" : ruc || "—"}</dd>
        </div>
        <div className="flex items-baseline justify-between gap-3 sm:justify-start">
          <dt className="text-xs text-muted-foreground">Razón social</dt>
          <dd className="text-sm">{isLoading ? "…" : billingName || "—"}</dd>
        </div>
      </dl>
      <p className="text-sm text-muted-foreground">
        {missing ? "Faltan datos del negocio — completalos en " : "Estos datos salen de "}
        <Link href="/settings" className="underline underline-offset-2">
          Configuración del negocio
        </Link>
        .
      </p>
    </div>
  )
}

/**
 * Timbrados POR CAJA — cada caja es un punto de expedición (context/29 §1).
 * Esta sección edita LA CAJA (register.data, vía el endpoint de cajas): no
 * existe una copia del timbrado en facturación electrónica. El alta del
 * emisor lee de acá.
 */
function RegisterStampsCard({ canManage }: { canManage: boolean }) {
  const { data, isLoading } = useRegistersAdmin()
  const registers = (data?.registers ?? []).filter((r) => r.status)

  return (
    <Card>
      <CardHeader>
        <CardTitle>Timbrados por caja</CardTitle>
        <CardDescription>
          Cada caja es un punto de expedición: cargá el timbrado que la SET le asignó. Las cajas
          sin timbrado no emiten factura electrónica.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {isLoading ? (
          <div className="flex flex-col gap-3">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
          </div>
        ) : registers.length === 0 ? (
          <EmptyState
            icon={CreditCard}
            title="Sin cajas activas"
            description="Creá una caja en Configuración antes de habilitar la facturación electrónica."
            ghost={false}
          />
        ) : (
          registers.map((r) => <RegisterStampRow key={r.id} register={r} canManage={canManage} />)
        )}
      </CardContent>
    </Card>
  )
}

function RegisterStampRow({ register, canManage }: { register: RegisterListItem; canManage: boolean }) {
  const update = useUpdateRegister()
  const [fiscal, setFiscal] = React.useState<RegisterFiscal>(register.fiscal)
  const dirty =
    fiscal.invoiceAuth !== register.fiscal.invoiceAuth ||
    fiscal.invoicePrefix !== register.fiscal.invoicePrefix ||
    fiscal.invoiceAuthStart !== register.fiscal.invoiceAuthStart ||
    fiscal.invoiceAuthExpiration !== register.fiscal.invoiceAuthExpiration

  function patch(p: Partial<RegisterFiscal>) {
    setFiscal((f) => ({ ...f, ...p }))
  }

  function handleSave() {
    update.mutate(
      { id: register.id, fiscal },
      {
        onSuccess: () => toast.success(`Timbrado de ${register.name} guardado.`),
        onError: (err) =>
          toast.error(`No se pudo guardar el timbrado de ${register.name}`, { description: err.message }),
      },
    )
  }

  return (
    <div className="flex flex-col gap-3 rounded-md border p-4">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm font-medium">
          {register.name}
          <span className="ml-2 text-xs text-muted-foreground">{register.outletName}</span>
        </p>
        {register.fiscal.invoiceAuth && <Badge variant="secondary">Timbrado cargado</Badge>}
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="space-y-1.5">
          <Label htmlFor={`stamp-auth-${register.id}`}>Número de timbrado</Label>
          <Input
            id={`stamp-auth-${register.id}`}
            value={fiscal.invoiceAuth}
            onChange={(e) => patch({ invoiceAuth: e.target.value.replace(/\D/g, "") })}
            placeholder="12345678"
            className="tabular-nums"
            disabled={!canManage}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={`stamp-prefix-${register.id}`}>Estab. y punto (EEE-PPP)</Label>
          <Input
            id={`stamp-prefix-${register.id}`}
            value={fiscal.invoicePrefix}
            onChange={(e) => patch({ invoicePrefix: e.target.value.replace(/[^0-9-]/g, "").slice(0, 7) })}
            placeholder="001-001"
            className="tabular-nums"
            disabled={!canManage}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={`stamp-start-${register.id}`}>Vigente desde</Label>
          <Input
            id={`stamp-start-${register.id}`}
            type="date"
            value={fiscal.invoiceAuthStart}
            onChange={(e) => patch({ invoiceAuthStart: e.target.value })}
            disabled={!canManage}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={`stamp-exp-${register.id}`}>Vence</Label>
          <Input
            id={`stamp-exp-${register.id}`}
            type="date"
            value={fiscal.invoiceAuthExpiration}
            onChange={(e) => patch({ invoiceAuthExpiration: e.target.value })}
            disabled={!canManage}
          />
        </div>
      </div>
      {dirty && (
        <div>
          <Button type="button" size="sm" onClick={handleSave} disabled={update.isPending || !canManage}>
            {update.isPending && <Loader2 className="size-4 animate-spin" />}
            Guardar timbrado
          </Button>
        </div>
      )}
    </div>
  )
}

function ProvisionForm({
  canManage,
  initial,
  lastError,
}: {
  canManage: boolean
  initial: Partial<EInvoiceFiscalForm> | undefined
  lastError: string | null
}) {
  const provision = useProvisionEinvoice()
  // Reanudación: si un alta anterior quedó a medias, el backend guardó el
  // formulario en `fiscal` y este estado lo pre-carga para reintentar.
  const [form, setForm] = React.useState<EInvoiceFiscalForm>(() => ({
    ...EMPTY_FORM,
    ...initial,
  }))

  function patch(p: Partial<EInvoiceFiscalForm>) {
    setForm((f) => ({ ...f, ...p }))
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    provision.mutate(form, {
      onSuccess: () => toast.success("Facturación electrónica habilitada."),
      onError: (err) =>
        toast.error("No se pudo completar el alta", { description: err.message }),
    })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle>Datos del emisor</CardTitle>
          <CardDescription>
            Lo que falta para registrarte como emisor de documentos electrónicos.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {lastError && (
            <Alert variant="destructive">
              <AlertDescription>{lastError}</AlertDescription>
            </Alert>
          )}

          <CompanyFiscalSummary />

          <Separator />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="ei-email">Email de facturación</Label>
              <Input
                id="ei-email"
                type="email"
                value={form.email}
                onChange={(e) => patch({ email: e.target.value })}
                placeholder="facturacion@tucomercio.com"
                autoComplete="email"
                disabled={!canManage}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ei-taxpayer">Tipo de contribuyente</Label>
              <Select
                value={String(form.taxpayerType ?? 2)}
                onValueChange={(v) => patch({ taxpayerType: Number(v) })}
                disabled={!canManage}
              >
                <SelectTrigger id="ei-taxpayer">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="1">Persona física</SelectItem>
                  <SelectItem value="2">Persona jurídica</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="ei-act-code">Actividad económica (código)</Label>
              <Input
                id="ei-act-code"
                value={form.actividadCodigo === "" ? "" : String(form.actividadCodigo)}
                onChange={(e) => {
                  const digits = e.target.value.replace(/\D/g, "")
                  patch({ actividadCodigo: digits === "" ? "" : Number(digits) })
                }}
                placeholder="Ej: 62010"
                className="tabular-nums"
                disabled={!canManage}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ei-act-name">Actividad económica (descripción)</Label>
              <Input
                id="ei-act-name"
                value={form.actividadNombre}
                onChange={(e) => patch({ actividadNombre: e.target.value })}
                placeholder="Ej: Restaurantes y servicios de comida"
                disabled={!canManage}
              />
            </div>
          </div>

          <p className="text-sm text-muted-foreground">
            El código de actividad figura en tu constancia de RUC (padrón de la SET). El email
            identifica a tu emisor en el sistema fiscal — usá uno que no cambie.
          </p>
        </CardContent>
      </Card>

      <RegisterStampsCard canManage={canManage} />

      {!canManage && (
        <p className="text-sm text-muted-foreground">
          No tenés permiso para configurar la facturación electrónica. Pedile a un administrador
          el permiso de gestión.
        </p>
      )}

      <div>
        <Button type="submit" disabled={provision.isPending || !canManage}>
          {provision.isPending && <Loader2 className="size-4 animate-spin" />}
          Habilitar facturación electrónica
        </Button>
      </div>
    </form>
  )
}

// ── Emisor provisionado — estado + certificado + emisión ────────────────────

function ProvisionedView({
  canManage,
  account,
}: {
  canManage: boolean
  account: NonNullable<ReturnType<typeof useEinvoiceAccount>["data"]>
}) {
  const saveConfig = useSaveEinvoiceConfig()
  const testConnection = useTestEinvoiceConnection()

  const [autoIssue, setAutoIssue] = React.useState(Boolean(account.config.autoIssue))
  const [onlyWithTaxId, setOnlyWithTaxId] = React.useState(Boolean(account.config.onlyWithTaxId))

  const persistConfig = React.useCallback(
    (patch: EInvoiceConfig, errorTitle: string) => {
      saveConfig.mutate(patch, {
        onError: (err) => toast.error(errorTitle, { description: err.message }),
      })
    },
    [saveConfig],
  )

  function handleEmissionChange(patch: { autoIssue?: boolean; onlyWithTaxId?: boolean }) {
    const nextAutoIssue = patch.autoIssue ?? autoIssue
    const nextOnlyWithTaxId = patch.onlyWithTaxId ?? onlyWithTaxId
    setAutoIssue(nextAutoIssue)
    setOnlyWithTaxId(nextOnlyWithTaxId)
    persistConfig(
      { autoIssue: nextAutoIssue, onlyWithTaxId: nextOnlyWithTaxId },
      "No se pudo guardar la configuración de emisión",
    )
  }

  function handleRecheck() {
    testConnection.mutate(undefined, {
      onSuccess: (result) => {
        if (result.status === "ok") toast.success("Todo en orden — el emisor está operativo.")
        else toast.error("El emisor necesita atención", { description: result.lastError ?? undefined })
      },
      onError: (err) => toast.error("No se pudo verificar el emisor", { description: err.message }),
    })
  }

  const fiscal = account.fiscal
  const syncedAt = formatSyncedAt(account.stampSyncedAt)

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-3">
            <div>
              <CardTitle>Emisor</CardTitle>
              <CardDescription>Datos fiscales registrados ante la SET.</CardDescription>
            </div>
            <StatusBadge status={account.status} />
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {account.lastError && account.status !== "ok" && (
            <Alert variant="destructive">
              <AlertDescription>{account.lastError}</AlertDescription>
            </Alert>
          )}

          {/* RUC/razón social salen de Configuración del negocio; el
              timbrado, de las cajas — acá solo lo que es propio del emisor. */}
          <CompanyFiscalSummary />
          <dl className="grid grid-cols-1 gap-x-6 gap-y-1.5 sm:grid-cols-2">
            <div className="flex items-baseline justify-between gap-3 sm:justify-start">
              <dt className="text-xs text-muted-foreground">Actividad</dt>
              <dd className="text-sm">
                {fiscal.actividadCodigo ? `${fiscal.actividadCodigo} · ${fiscal.actividadNombre ?? ""}` : "—"}
              </dd>
            </div>
            <div className="flex items-baseline justify-between gap-3 sm:justify-start">
              <dt className="text-xs text-muted-foreground">Email de facturación</dt>
              <dd className="text-sm">{String(fiscal.email ?? "—")}</dd>
            </div>
          </dl>

          {syncedAt && (
            <p className="text-xs text-muted-foreground">Última verificación {syncedAt}</p>
          )}

          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={handleRecheck}
              disabled={testConnection.isPending || !canManage}
            >
              {testConnection.isPending ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <ShieldCheck className="size-4" />
              )}
              Verificar estado
            </Button>
          </div>
        </CardContent>
      </Card>

      <RegisterStampsCard canManage={canManage} />

      <CertificateCard canManage={canManage} certUploaded={account.certUploaded} />

      <Card>
        <CardHeader>
          <CardTitle>Emisión</CardTitle>
          <CardDescription>Cómo se generan las facturas electrónicas de tus ventas.</CardDescription>
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
              disabled={!canManage}
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
              disabled={!canManage}
              onCheckedChange={(checked) => handleEmissionChange({ onlyWithTaxId: checked })}
            />
          </div>
        </CardContent>
      </Card>

      <PaymentMethodMappingCard
        connected={account.status === "ok"}
        config={account.config}
        canManage={canManage}
        saving={saveConfig.isPending}
        onSave={persistConfig}
      />

      <EInvoiceDocumentsCard />
    </div>
  )
}

// ── Certificado de firma ────────────────────────────────────────────────────

/**
 * El `.pfx` es la identidad de firma digital del contribuyente. Se lee en el
 * browser, viaja en base64 al backend, que lo PASA al sistema fiscal y lo
 * descarta — nunca se persiste ni se loguea (ni el archivo ni la contraseña).
 */
function CertificateCard({ canManage, certUploaded }: { canManage: boolean; certUploaded: boolean }) {
  const uploadCert = useUploadEinvoiceCert()
  const testSet = useTestEinvoiceSet()
  const fileRef = React.useRef<HTMLInputElement>(null)
  const [password, setPassword] = React.useState("")

  function handleUpload() {
    const file = fileRef.current?.files?.[0]
    if (!file) {
      toast.error("Elegí el archivo del certificado (.pfx o .p12).")
      return
    }
    if (password === "") {
      toast.error("Ingresá la contraseña del certificado.")
      return
    }
    const reader = new FileReader()
    reader.onload = () => {
      const result = String(reader.result ?? "")
      const base64 = result.includes(",") ? result.slice(result.indexOf(",") + 1) : result
      uploadCert.mutate(
        { certBase64: base64, certPassword: password },
        {
          onSuccess: () => {
            toast.success("Certificado cargado.")
            setPassword("")
            if (fileRef.current) fileRef.current.value = ""
          },
          onError: (err) => toast.error("No se pudo cargar el certificado", { description: err.message }),
        },
      )
    }
    reader.onerror = () => toast.error("No se pudo leer el archivo.")
    reader.readAsDataURL(file)
  }

  function handleTestSet() {
    testSet.mutate(undefined, {
      onSuccess: () => toast.success("La SET respondió correctamente — tu certificado está operativo."),
      onError: (err) =>
        toast.error("La prueba contra la SET falló", { description: err.message }),
    })
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between gap-3">
          <div>
            <CardTitle>Certificado de firma</CardTitle>
            <CardDescription>
              El certificado digital (.pfx) con el que se firman tus documentos electrónicos.
            </CardDescription>
          </div>
          {certUploaded && <Badge variant="secondary">Cargado</Badge>}
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="ei-cert-file">Archivo (.pfx / .p12)</Label>
            <Input id="ei-cert-file" ref={fileRef} type="file" accept=".pfx,.p12" disabled={!canManage} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="ei-cert-pass">Contraseña del certificado</Label>
            <Input
              id="ei-cert-pass"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="new-password"
              disabled={!canManage}
            />
          </div>
        </div>
        <p className="text-sm text-muted-foreground">
          El archivo y su contraseña se usan solo para registrarlo ante el sistema fiscal — no
          quedan guardados en Punto.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button type="button" onClick={handleUpload} disabled={uploadCert.isPending || !canManage}>
            {uploadCert.isPending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <Upload className="size-4" />
            )}
            {certUploaded ? "Reemplazar certificado" : "Cargar certificado"}
          </Button>
          {certUploaded && (
            <Button type="button" variant="outline" onClick={handleTestSet} disabled={testSet.isPending}>
              {testSet.isPending ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <ShieldCheck className="size-4" />
              )}
              Probar contra la SET
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

/** Sentinel del `<Select>`: shadcn no admite `value=""` en un `<SelectItem>`. */
const UNMAPPED = "__default__"

/**
 * Mapeo medio de pago de Punto → código de medio de pago de SIFEN. La factura
 * declara con qué se pagó; los códigos los define la SET, así que la lista de
 * la derecha se trae del backend y no se hardcodea.
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
            title="Emisor en proceso de habilitación"
            description="Los códigos de medio de pago se cargan cuando el emisor queda operativo."
            ghost={false}
          />
        ) : codesError ? (
          <Alert variant="destructive">
            <AlertDescription>
              No se pudieron traer los códigos de medio de pago: {codesError.message}
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
