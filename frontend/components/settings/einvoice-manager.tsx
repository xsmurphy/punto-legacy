"use client"

/**
 * Facturación Electrónica — configuración del comercio (F7, white-label).
 *
 * El comercio NUNCA ve al proveedor de FE, y NO hay dos copias de ningún dato:
 * los timbrados salen de las CAJAS — cada caja es un punto de expedición
 * (context/29 §1) y su timbrado se configura EN la caja (Sucursales →
 * sucursal → Cajas → editar), tenga o no el comercio este módulo; acá solo se
 * muestra el estado (RegisterStampsSummary, solo lectura). Punto provisiona la
 * cuenta por detrás con su credencial admin (EInvoiceProvisioningService).
 *
 * El RUC y la razón social SÍ se editan acá (2026-09-06: el owner activó el
 * módulo, vio "RUC —" y no supo dónde cargarlo), pero escribiendo al MISMO
 * destino de siempre — los ajustes de la empresa, vía `useUpdateSettings`. Un
 * campo editable en dos pantallas no es una fuente duplicada mientras las dos
 * escriban al mismo lado; duplicar sería guardarlo también acá.
 *
 * Dos estados:
 *   - Sin provisionar → formulario de alta (datos fiscales editables +
 *                       actividad + CSC + estado de timbrados).
 *   - Provisionado    → estado del emisor + certificado + CSC + emisión +
 *                       medios de pago + documentos.
 */

import * as React from "react"
import { CreditCard, Loader2, Search, ShieldCheck, Trash2, Upload } from "lucide-react"
import { toast } from "sonner"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
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
  useDeleteEinvoiceCert,
  useEinvoiceAccount,
  useEinvoicePaymentMethods,
  useProvisionEinvoice,
  useSaveEinvoiceConfig,
  useSaveEinvoiceCsc,
  useTestEinvoiceConnection,
  useTestEinvoiceSet,
  useUploadEinvoiceCert,
} from "@/hooks/use-einvoice"
import { usePaymentMethods } from "@/hooks/use-payment-methods"
import { usePermission } from "@/hooks/use-permissions"
import { useRegistersAdmin } from "@/hooks/use-registers-admin"
import { useSettings, useTaxpayerLookup, useUpdateSettings } from "@/hooks/use-settings"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { resolveDateLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"
import type { EInvoiceConfig, EInvoiceFiscalForm, EInvoiceStatus } from "@/lib/types/einvoice"
import Link from "next/link"

function StatusBadge({ status }: { status: EInvoiceStatus }) {
  if (status === "ok") return <Badge>Habilitado</Badge>
  if (status === "provisioning") return <Badge variant="secondary">Alta en proceso</Badge>
  if (status === "auth_error") return <Badge variant="destructive">Necesita atención</Badge>
  return <Badge variant="secondary">Sin configurar</Badge>
}

function formatSyncedAt(
  iso: string | null,
  config: TenantLocaleConfig | null | undefined,
): string | null {
  if (!iso) return null
  // stamp_synced_at es TIMESTAMPTZ genuino (now() del servidor) — se parsea
  // directo, sin el stripeo de offset de lib/format-date.ts (ver ese archivo).
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleString(resolveDateLocale(config), {
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
 * RUC y razón social — SOLO LECTURA. Es la vista del emisor ya provisionado:
 * cambiar el RUC de un emisor que ya existe ante la SET no es editar un
 * ajuste, es otro emisor, así que acá se muestra y se manda a Configuración.
 *
 * La razón social NO cae al nombre comercial (bug fiscal corregido
 * 2026-09-06): son datos distintos y la SET valida la razón social contra el
 * padrón del RUC. Si falta, la tarjeta lo dice — antes el fallback la daba por
 * cargada y nadie se enteraba.
 */
function CompanyFiscalSummary() {
  const { data: settings, isLoading } = useSettings()
  const ruc = settings?.ruc?.trim() ?? ""
  const billingName = settings?.billingName?.trim() ?? ""
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
        {missing ? "Faltan datos fiscales del negocio — completalos en " : "Estos datos salen de "}
        <Link href="/settings" className="underline underline-offset-2">
          Configuración del negocio
        </Link>
        .
      </p>
    </div>
  )
}

/**
 * RUC y razón social EDITABLES en el alta.
 *
 * El owner activó el módulo, vio "RUC —" y no supo dónde cargarlo: mandarlo a
 * otra pantalla en el medio del alta es pedirle que abandone el formulario.
 * Ahora se cargan acá, pero **sin duplicar la fuente**: el submit del alta
 * escribe al MISMO destino de siempre (`ruc`/`billingName` de los ajustes de
 * la empresa, vía `useUpdateSettings`) antes de provisionar. No hay una
 * segunda copia de estos datos en ningún lado.
 *
 * La razón social se puede traer del padrón con el RUC, y llega como
 * SUGERENCIA editable: el padrón es la fuente correcta pero no es infalible, y
 * el que responde ante la SET es el comercio.
 */
function CompanyFiscalFields({
  ruc,
  billingName,
  onChange,
  canEdit,
}: {
  ruc: string
  billingName: string
  onChange: (patch: { ruc?: string; billingName?: string }) => void
  canEdit: boolean
}) {
  const lookup = useTaxpayerLookup()

  function handleLookup() {
    const value = ruc.trim()
    if (value === "") {
      toast.error("Escribí el RUC antes de buscarlo en el padrón.")
      return
    }
    lookup.mutate(value, {
      onSuccess: (found) => {
        onChange({ ruc: found.ruc || value, billingName: found.name })
        toast.success("Razón social traída del padrón.", {
          description: "Revisala: si el padrón la tiene distinta, corregila antes de guardar.",
        })
      },
      onError: (err) =>
        toast.error("No se encontró ese RUC en el padrón", {
          description: `${err.message} Podés cargar la razón social a mano.`,
        }),
    })
  }

  if (!canEdit) {
    return <CompanyFiscalSummary />
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="ei-ruc">RUC</Label>
          <div className="flex gap-2">
            <Input
              id="ei-ruc"
              value={ruc}
              onChange={(e) => onChange({ ruc: e.target.value })}
              placeholder="80012345-6"
              className="tabular-nums"
            />
            <Button
              type="button"
              variant="outline"
              onClick={handleLookup}
              disabled={lookup.isPending}
            >
              {lookup.isPending ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <Search className="size-4" />
              )}
              Buscar
            </Button>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ei-billing-name">Razón social</Label>
          <Input
            id="ei-billing-name"
            value={billingName}
            onChange={(e) => onChange({ billingName: e.target.value })}
            placeholder="Como figura en el padrón de la SET"
          />
        </div>
      </div>
      <p className="text-sm text-muted-foreground">
        La razón social es el nombre legal del contribuyente, no el nombre comercial: la SET la
        valida contra el padrón del RUC. Buscá el RUC para traerla y corregila si hace falta. Se
        guarda junto con el alta, en{" "}
        <Link href="/settings" className="underline underline-offset-2">
          Configuración del negocio
        </Link>
        .
      </p>
    </div>
  )
}

/**
 * Resumen de timbrados por caja — SOLO LECTURA. El timbrado se configura
 * donde se configuran las cajas (Sucursales → sucursal → Cajas → editar
 * caja), tenga o no el comercio este módulo: es dato fiscal de la caja, no
 * del módulo de facturación electrónica. Acá solo se muestra qué cajas
 * están listas para emitir, con link a donde se cargan.
 */
function RegisterStampsSummary() {
  const { data, isLoading } = useRegistersAdmin()
  const registers = (data?.registers ?? []).filter((r) => r.status)
  const withStamp = registers.filter((r) => r.fiscal.invoiceAuth !== "")

  return (
    <Card>
      <CardHeader>
        <CardTitle>Timbrados por caja</CardTitle>
        <CardDescription>
          Cada caja es un punto de expedición. El timbrado se carga en la configuración de la
          caja; las cajas sin timbrado no emiten factura electrónica.
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
            description="Creá una caja en Sucursales antes de habilitar la facturación electrónica."
            ghost={false}
          />
        ) : (
          <div className="flex flex-col gap-2">
            {registers.map((r) => (
              <div key={r.id} className="flex items-center justify-between gap-3">
                <p className="text-sm">
                  {r.name}
                  <span className="ml-2 text-xs text-muted-foreground">{r.outletName}</span>
                </p>
                {r.fiscal.invoiceAuth ? (
                  <span className="text-sm tabular-nums">
                    {r.fiscal.invoiceAuth}
                    {r.fiscal.invoicePrefix ? ` · ${r.fiscal.invoicePrefix}` : ""}
                  </span>
                ) : (
                  <Badge variant="secondary">Sin timbrado</Badge>
                )}
              </div>
            ))}
          </div>
        )}
        <p className="text-sm text-muted-foreground">
          {withStamp.length === 0
            ? "Ninguna caja tiene timbrado cargado — cargalos en "
            : "Los timbrados se administran en "}
          <Link href="/outlets" className="underline underline-offset-2">
            Sucursales
          </Link>
          {" (elegí la sucursal, después la caja)."}
        </p>
      </CardContent>
    </Card>
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
  const updateSettings = useUpdateSettings()
  // Los datos fiscales son de la EMPRESA, no de este formulario: se editan
  // acá pero se guardan donde siempre vivieron (ver CompanyFiscalFields).
  const canEditFiscal = usePermission("settings.company.edit")
  const { data: settings } = useSettings()

  // Reanudación: si un alta anterior quedó a medias, el backend guardó el
  // formulario en `fiscal` y este estado lo pre-carga para reintentar.
  const [form, setForm] = React.useState<EInvoiceFiscalForm>(() => ({
    ...EMPTY_FORM,
    ...initial,
  }))
  const [fiscalData, setFiscalData] = React.useState<{ ruc: string; billingName: string } | null>(
    null,
  )
  // Los ajustes llegan asincrónicos; el estado local arranca en cuanto están y
  // después manda lo que el usuario tipeó (no se pisa en cada refetch).
  const ruc = fiscalData?.ruc ?? settings?.ruc ?? ""
  const billingName = fiscalData?.billingName ?? settings?.billingName ?? ""

  function patch(p: Partial<EInvoiceFiscalForm>) {
    setForm((f) => ({ ...f, ...p }))
  }

  function patchFiscal(p: { ruc?: string; billingName?: string }) {
    setFiscalData({ ruc, billingName, ...p })
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()

    // Primero los datos de la empresa, después el alta: `provision()` los lee
    // de `company.config` server-side, así que si se mandaran al revés el
    // emisor se registraría con los viejos (o fallaría por faltantes).
    if (
      canEditFiscal &&
      (ruc.trim() !== (settings?.ruc ?? "").trim() ||
        billingName.trim() !== (settings?.billingName ?? "").trim())
    ) {
      try {
        await updateSettings.mutateAsync({ ruc: ruc.trim(), billingName: billingName.trim() })
      } catch (err) {
        toast.error("No se pudieron guardar los datos fiscales", {
          description: err instanceof Error ? err.message : undefined,
        })
        return
      }
    }

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

          <CompanyFiscalFields
            ruc={ruc}
            billingName={billingName}
            onChange={patchFiscal}
            canEdit={canEditFiscal && canManage}
          />

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

          <Separator />

          <CscFields
            cscId={form.cscId ?? ""}
            cscSecret={form.cscSecret ?? ""}
            onChange={patch}
            disabled={!canManage}
          />
        </CardContent>
      </Card>

      <RegisterStampsSummary />

      {!canManage && (
        <p className="text-sm text-muted-foreground">
          No tenés permiso para configurar la facturación electrónica. Pedile a un administrador
          el permiso de gestión.
        </p>
      )}

      <div>
        <Button
          type="submit"
          disabled={provision.isPending || updateSettings.isPending || !canManage}
        >
          {(provision.isPending || updateSettings.isPending) && (
            <Loader2 className="size-4 animate-spin" />
          )}
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
  // El bootstrap ya está en cache (lo pide el layout del panel) — leerlo acá
  // no agrega request, y es el único lugar con el país/idioma del tenant.
  const { data: bootstrap } = useBootstrap()
  const syncedAt = formatSyncedAt(account.stampSyncedAt, bootstrap)

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

      <RegisterStampsSummary />

      <CertificateCard
        canManage={canManage}
        certUploaded={account.certUploaded}
        certStored={account.certStored}
        certUploadedAt={account.certUploadedAt}
      />

      <CscCard
        canManage={canManage}
        cscId={String(fiscal.cscId ?? "")}
        cscStored={account.cscStored}
        cscUpdatedAt={account.cscUpdatedAt}
      />

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

// ── CSC de SIFEN ────────────────────────────────────────────────────────────

/**
 * Los dos campos del CSC, compartidos por el alta y por la tarjeta del emisor
 * ya provisionado.
 *
 * Estaban en el estado del formulario desde el día uno pero NUNCA se
 * renderizaban: el alta mandaba los dos vacíos y `IdCSCProduccion` /
 * `CSCProduccion` no se seteaban nunca del lado del emisor.
 *
 * Opcionales para PROVISIONAR, exigibles para emitir en PRODUCCIÓN: el manual
 * de Factomate los marca como no requeridos en `PUT /api/Tenant` ("no
 * requerido en modo desarrollo"), pero sin ellos el QR del KuDE no se puede
 * firmar. La ayuda del campo lo dice con esas palabras, para que nadie
 * descubra el faltante recién cuando la SET rechaza.
 */
function CscFields({
  cscId,
  cscSecret,
  onChange,
  disabled,
  secretPlaceholder,
}: {
  cscId: string
  cscSecret: string
  onChange: (patch: { cscId?: string; cscSecret?: string }) => void
  disabled: boolean
  secretPlaceholder?: string
}) {
  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="ei-csc-id">Identificador del CSC</Label>
          <Input
            id="ei-csc-id"
            value={cscId}
            onChange={(e) => onChange({ cscId: e.target.value })}
            placeholder="Ej: 0001"
            className="tabular-nums"
            disabled={disabled}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ei-csc-secret">Código de seguridad (CSC)</Label>
          {/* type="password" igual que la contraseña del certificado en esta
              misma pantalla: es un secreto que se tipea una vez y no se
              vuelve a mostrar. */}
          <Input
            id="ei-csc-secret"
            type="password"
            value={cscSecret}
            onChange={(e) => onChange({ cscSecret: e.target.value })}
            placeholder={secretPlaceholder}
            autoComplete="off"
            disabled={disabled}
          />
        </div>
      </div>
      <p className="text-sm text-muted-foreground">
        El CSC lo emite la SET desde el Marangatu (Código de Seguridad del Contribuyente) y es lo
        que firma el QR de tus facturas. No hace falta para dar de alta el emisor ni para probar,
        pero sí para emitir en producción — si todavía no lo tenés, seguí sin él y cargalo después.
      </p>
    </div>
  )
}

/**
 * Tarjeta del CSC para el emisor YA provisionado. Existe porque el CSC se
 * consigue casi siempre después del alta: sin esto, quien se dio de alta sin
 * CSC no tenía dónde cargarlo nunca más (el paso fiscal del provisioning está
 * checkpointeado y no vuelve a correr).
 *
 * El código guardado no se muestra — el backend no lo devuelve. Lo único que
 * se sabe desde acá es si hay uno cargado y desde cuándo.
 */
function CscCard({
  canManage,
  cscId,
  cscStored,
  cscUpdatedAt,
}: {
  canManage: boolean
  cscId: string
  cscStored: boolean
  cscUpdatedAt: string | null
}) {
  const saveCsc = useSaveEinvoiceCsc()
  const { data: bootstrap } = useBootstrap()
  const [draft, setDraft] = React.useState({ cscId, cscSecret: "" })
  const savedAt = formatSyncedAt(cscUpdatedAt, bootstrap)

  function handleSave() {
    saveCsc.mutate(
      { cscId: draft.cscId.trim(), cscSecret: draft.cscSecret },
      {
        onSuccess: () => {
          toast.success("CSC guardado.")
          setDraft((d) => ({ ...d, cscSecret: "" }))
        },
        onError: (err) => toast.error("No se pudo guardar el CSC", { description: err.message }),
      },
    )
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between gap-3">
          <div>
            <CardTitle>Código de seguridad (CSC)</CardTitle>
            <CardDescription>
              Con el CSC se firma el QR de tus facturas electrónicas.
            </CardDescription>
          </div>
          {cscStored ? (
            <Badge variant="secondary">Cargado</Badge>
          ) : (
            <Badge variant="outline">Falta para producción</Badge>
          )}
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <CscFields
          cscId={draft.cscId}
          cscSecret={draft.cscSecret}
          onChange={(p) => setDraft((d) => ({ ...d, ...p }))}
          disabled={!canManage}
          secretPlaceholder={cscStored ? "Guardado — escribí uno nuevo para reemplazarlo" : undefined}
        />
        {savedAt && <p className="text-sm text-muted-foreground">Guardado el {savedAt}.</p>}
        <div>
          <Button type="button" onClick={handleSave} disabled={saveCsc.isPending || !canManage}>
            {saveCsc.isPending && <Loader2 className="size-4 animate-spin" />}
            {cscStored ? "Reemplazar CSC" : "Guardar CSC"}
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

// ── Certificado de firma ────────────────────────────────────────────────────

/**
 * El `.pfx` es la identidad de firma digital del contribuyente. Se lee en el
 * browser, viaja en base64 al backend, que lo registra ante el sistema fiscal
 * y lo guarda CIFRADO (decisión del owner 2026-09-06 — ver context/28
 * §Custodia). Nunca vuelve al frontend ni toca un log: de acá para arriba lo
 * único que se sabe es si hay uno guardado y desde cuándo.
 *
 * La pantalla se lo DICE al comercio, no lo deja implícito: es su certificado
 * y enterarse por accidente sería peor que la custodia misma.
 */
function CertificateCard({
  canManage,
  certUploaded,
  certStored,
  certUploadedAt,
}: {
  canManage: boolean
  certUploaded: boolean
  certStored: boolean
  certUploadedAt: string | null
}) {
  const uploadCert = useUploadEinvoiceCert()
  const deleteCert = useDeleteEinvoiceCert()
  const testSet = useTestEinvoiceSet()
  const { data: bootstrap } = useBootstrap()
  const fileRef = React.useRef<HTMLInputElement>(null)
  const [password, setPassword] = React.useState("")
  const storedAt = formatSyncedAt(certUploadedAt, bootstrap)

  function handleDelete() {
    deleteCert.mutate(undefined, {
      onSuccess: () => toast.success("Punto ya no conserva tu certificado."),
      onError: (err) =>
        toast.error("No se pudo borrar el certificado", { description: err.message }),
    })
  }

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
          Punto conserva tu certificado cifrado para poder reconfigurar la emisión sin volver a
          pedírtelo. Es tu certificado: podés borrarlo cuando quieras, y cada vez que Punto lo usa
          queda registrado quién y cuándo.
          {storedAt ? ` Guardado el ${storedAt}.` : ""}
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
          {certStored && (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button type="button" variant="outline" disabled={deleteCert.isPending || !canManage}>
                  {deleteCert.isPending ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <Trash2 className="size-4" />
                  )}
                  Borrar de Punto
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>¿Borrar el certificado guardado?</AlertDialogTitle>
                  <AlertDialogDescription>
                    Vas a seguir facturando igual: el certificado ya está registrado ante el
                    sistema fiscal y no se toca. Lo que se borra es la copia cifrada que guarda
                    Punto, así que si más adelante hay que reconfigurar la emisión vamos a
                    pedirte el archivo y su contraseña de nuevo.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancelar</AlertDialogCancel>
                  <AlertDialogAction onClick={handleDelete}>Borrar</AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
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
