"use client"

/**
 * ContactDetailView — vista reutilizable del perfil de un contacto.
 *
 * Usado en:
 *   - `(panel)/contacts/[id]/page.tsx` → variant="panel" nav="tabs"
 *   - `register/customer-dialog.tsx`   → variant="pos"   nav="sidebar"
 *     (abre desde el botón ⋮ de cada cliente)
 *
 * Estructura (context/84 §3, owner 2026-09-18): Resumen → Datos → Financiero,
 * Comportamiento, Transacciones, Órdenes, Agenda, Packs. En el panel la
 * impone `EntityShell`; en la caja el menú lateral toma el MISMO orden y los
 * MISMOS nombres de `resolveEntityTabs`. Direcciones es una sección de Datos.
 *
 * Props:
 *   customerId       UUID del contacto.
 *   variant          "panel" | "pos" (default "panel").
 *                    En "pos": oculta acciones destructivas (archivar), muestra
 *                    footer fijo "Añadir" y botón cerrar (X).
 *   nav              "tabs" | "sidebar" (default "tabs").
 *                    "sidebar": aside izq ≈ 200 px + contenido a la derecha.
 *   onClose          Solo variant="pos". Llama al cerrar la X.
 *   onSelectForSale  Solo variant="pos". Llama con el contacto al pulsar "Cobrar".
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { useForm, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Loader2, SearchCode, X, Pencil, Trash2 } from "lucide-react"
import { toast } from "sonner"
import { useTheme } from "next-themes"
import type { Map as MapLibreMap, Marker as MapLibreMarker } from "maplibre-gl"
import "maplibre-gl/dist/maplibre-gl.css"
import type { CountryCode } from "libphonenumber-js"
import {
  Bar, BarChart, CartesianGrid, Cell, Line, LineChart,
  Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts"

import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { FormSection } from "@/components/forms/form-section"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { DatePicker } from "@/components/date-picker"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Tabs, TabsList, TabsTrigger,
} from "@/components/ui/tabs"
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
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { PhoneInput } from "@/components/forms/phone-input"
import { MoneyInput } from "@/components/ui/money-input"
import {
  useArchiveContact,
  useContact,
  useContactStatement,
  useContactAnalytics,
  useContactPacks,
  useUpdateContact,
  useCustomerAddresses,
  useAddAddress,
  useUpdateAddress,
  useSetDefaultAddress,
  useDeleteAddress,
  useTaxpayerLookup,
} from "@/hooks/use-contacts"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"
import { useModules } from "@/hooks/use-modules"
import { WalletSection } from "@/components/domain/wallet/wallet-section"
import { usePriceLists } from "@/hooks/use-price-lists"
import { ApiError } from "@/lib/api-client"
import { useTenantPhoneCountry } from "@/hooks/use-tenant-phone-country"
import {
  contactIdTypesFor,
  personalIdFieldCopy,
  taxIdFieldCopy,
} from "@/lib/contact-id-types"
import type { TenantLocaleConfig } from "@/lib/tenant-locale"
import { formatInt, formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { formatBucketLabel, formatBucketTick } from "@/lib/charts/granularity"
import { cn } from "@/lib/utils"
import { AddressMapParser } from "@/components/geo/address-map-parser"
import { AddressAutocompleteInput } from "@/components/geo/address-autocomplete-input"
import type { GeoSuggestion } from "@/lib/geo/types"
import type {
  ContactAnalytics,
  ContactFormValues,
  ContactFull,
  CustomerAddress,
  SoldPack,
} from "@/lib/types/contact"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"
import { OrdersList } from "@/components/domain/orders/orders-list"
import { ScheduleList } from "@/components/domain/schedule/schedule-list"
import { AccountStatementSection } from "@/components/domain/contacts/account-statement-section"
import { StatsRow, StatTile } from "@/components/stat-tile"
import {
  ENTITY_DATA_KEY,
  ENTITY_SUMMARY_KEY,
  EntityShell,
  entityInitials,
  resolveEntityTabs,
  type EntityTab,
} from "@/components/page/entity-shell"
import { ContactOrdersCompact } from "@/components/domain/contacts/contact-orders-compact"
import { ContactScheduleCompact } from "@/components/domain/contacts/contact-schedule-compact"
import { ContactTransactionsTab } from "@/components/domain/contacts/contact-transactions-tab"
import { formatPhone } from "@/lib/phone"

// ── Zod schema (igual que el de la page original) ────────────────────────────

export const contactSchema = z
  .object({
    kind: z.enum(["persona", "empresa"]),
    name: z.string(),
    fiscalName: z.string(),
    tin: z.string(),
    ci: z.string(),
    idType: z.number().nullable(),
    bday: z.string(),
    phone: z.string().nullable(),
    email: z.union([z.string().email("Email inválido"), z.literal("")]),
    note: z.string(),
    status: z.boolean(),
    priceListId: z.string().nullable(),
    isCreditable: z.boolean(),
    creditLine: z.number().nullable(),
  })
  .refine(
    (v) => (v.kind === "persona" ? v.name.trim() !== "" : v.fiscalName.trim() !== ""),
    { message: "El nombre es requerido", path: ["name"] },
  )

// ── Types ─────────────────────────────────────────────────────────────────────

/**
 * La ficha de cliente guardaba la pestaña en estado local; desde 2026-09-18
 * vive en `?tab=` con las claves del armazón. Estos alias cubren las claves
 * viejas por si algún link las usa. Direcciones pasó a ser sección de Datos.
 */
const CONTACT_TAB_ALIASES: Record<string, string> = {
  summary: "resumen",
  data: "datos",
  addresses: "datos",
  direcciones: "datos",
  financial: "financiero",
  behavior: "comportamiento",
  transactions: "transacciones",
  orders: "ordenes",
  schedule: "agenda",
}

export interface ContactDetailViewProps {
  customerId: string
  variant?: "panel" | "pos"
  nav?: "tabs" | "sidebar"
  onClose?: () => void
  onSelectForSale?: (contact: PosCustomer) => void
  /** Solo panel: a qué listado vuelve (clientes o proveedores). */
  back?: { href: string; label: string }
}

// ── Component ─────────────────────────────────────────────────────────────────

export function ContactDetailView({
  customerId,
  variant = "panel",
  nav = "tabs",
  onClose,
  onSelectForSale,
  back = { href: "/contacts", label: "Volver a clientes" },
}: ContactDetailViewProps) {
  const router = useRouter()
  const { data, isLoading, error } = useContact(customerId)
  const update = useUpdateContact()
  const archive = useArchiveContact()
  // Ver nota en `app/(panel)/contacts/[id]/page.tsx`: el estado guarda `null`
  // hasta que el usuario elige, así el selector sigue al país del tenant
  // cuando el bootstrap llega después del primer render.
  const tenantPhoneCountry = useTenantPhoneCountry()
  const [pickedCountry, setCountry] = React.useState<CountryCode | null>(null)
  const country = pickedCountry ?? tenantPhoneCountry
  // Pestaña activa del menú lateral de la CAJA. En el panel la maneja
  // `EntityShell` (en `?tab=`).
  const [posTab, setPosTab] = React.useState<string>(ENTITY_SUMMARY_KEY)

  const form = useForm<ContactFormValues>({
    resolver: zodResolver(contactSchema),
    defaultValues: emptyContactValues(),
  })

  React.useEffect(() => {
    if (!data) return
    const kind: "persona" | "empresa" = data.fullname ? "empresa" : "persona"
    form.reset({
      kind,
      name: kind === "empresa" ? data.fullname ?? "" : data.name ?? "",
      fiscalName: kind === "empresa" ? data.name ?? "" : "",
      tin: data.tin ?? "",
      ci: data.ci ?? "",
      idType: data.idType ?? null,
      bday: data.bday ?? "",
      phone: data.phone ?? null,
      email: data.email ?? "",
      note: data.note ?? "",
      status: (data.status ?? 1) === 1,
      priceListId: data.priceListId ?? null,
      isCreditable: data.isCreditable ?? false,
      creditLine: data.creditLine ?? null,
    })
  }, [data, form])

  // Siempre se pide: el segmento y la última visita son metadata del
  // ENCABEZADO de la ficha (owner 2026-09-18), no solo del Resumen.
  const analytics = useContactAnalytics(customerId, 1)
  const { data: bootstrap } = useBootstrap()
  // Tab "Agenda" (ContactScheduleCompact) depende del módulo calendar — mismo
  // criterio conservador que panel-auth-guard/pos-sidebar: oculto mientras
  // carga o está apagado, para no ofrecer una sección sin datos relevantes.
  const { data: modules, isLoading: modulesLoading } = useModules()
  const calendarEnabled = !modulesLoading && modules?.calendar?.enabled === true
  // Espejo del gate del backend para el tab "Transacciones" (ver abajo, donde
  // se arman las secciones).
  const canViewSales = usePermission("reports.sales.view")
  // El tab "Agenda" pega contra `/v1/reports/schedule`, cuyo GET exige
  // `reports.schedule.view` desde el 2026-09-02: hasta entonces la clave
  // estaba en el archivo pero solo gateaba el POST, así que la lectura pasaba
  // de largo. Mismo espejo que el tab "Transacciones" de acá abajo.
  const canViewSchedule = usePermission("reports.schedule.view")
  // El tab "Órdenes" pega contra `/v1/orders-core?customerId=…`, cuyo GET exige
  // `contacts.customer.view` desde el 2026-09-11 (gate contextual: filtrada por
  // cliente es la ficha, sin filtro es el reporte). Para un CLIENTE el espejo
  // es redundante —sin la clave, `/v1/contacts` ni devuelve la ficha y esta
  // vista corta en el error— pero la ficha de un PROVEEDOR se abre con
  // `contacts.supplier.view` y monta los mismos tabs: sin esto, un rol de
  // compras veía "Órdenes" y se comía un 403. Mismo espejo que los dos de
  // arriba.
  const canViewCustomers = usePermission("contacts.customer.view")
  // Saldo de la wallet (context/74) dentro del tab "Financiero": solo panel
  // (`/v1/wallet` es realm `panel`), con el módulo activo y con permiso —
  // `wallet.manage` alcanza para leer, igual que en el endpoint.
  const canViewWallet = usePermission("wallet.view")
  const canManageWallet = usePermission("wallet.manage")
  const showWallet =
    variant === "panel" &&
    !modulesLoading &&
    modules?.wallet?.enabled === true &&
    (canViewWallet || canManageWallet)

  const onSubmit = async (values: ContactFormValues) => {
    try {
      await update.mutateAsync({ id: customerId, values })
      toast.success("Contacto actualizado")
    } catch (e) {
      toast.error("No se pudo guardar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const onArchive = async () => {
    try {
      await archive.mutateAsync(customerId)
      toast.success("Contacto archivado")
      if (variant === "panel") {
        router.push(back.href)
      } else {
        onClose?.()
      }
    } catch (e) {
      toast.error("No se pudo archivar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const kind = form.watch("kind")
  const isPos = variant === "pos"

  if (error) {
    return (
      <Card>
        <CardContent className="p-8 text-center text-sm text-muted-foreground">
          No se pudo cargar el contacto. {error.message}
        </CardContent>
      </Card>
    )
  }

  // ── Contenido de cada pestaña ────────────────────────────────────────────
  // El ORDEN y los nombres de Resumen y Datos no se deciden acá: los fija
  // `EntityShell`/`resolveEntityTabs` (context/84 §3). Acá solo se arma qué
  // va adentro de cada una y qué pestañas propias existen.

  const summary = (
    <SummaryTab
      analytics={analytics.data}
      isLoading={analytics.isLoading || isLoading}
      bootstrap={bootstrap}
    />
  )

  // Datos = el ÚNICO lugar donde se edita el contacto. Las direcciones son
  // parte de sus datos (owner 2026-09-18): sección dentro de Datos, no
  // pestaña propia.
  const dataTab = (
    <div className="flex flex-col gap-6">
      <ContactFormBody
        form={form}
        kind={kind}
        country={country}
        setCountry={setCountry}
        tenant={bootstrap}
      />
      <FormSection title="Direcciones">
        <AddressesSection contactId={customerId} />
      </FormSection>
    </div>
  )

  // "transactions" es panel-only: pega contra /v1/reports/transactions, que
  // solo acepta realm `panel` (apiAuthTenant(['panel'])). El customer-dialog
  // del POS (variant="pos") corre con el Bearer del device (realm pos-app) —
  // si se mostrara ahí, el fetch devolvería 401 "Token de otro realm".
  const extraTabs: EntityTab[] = [
    {
      key: "financiero",
      label: "Financiero",
      content: (
        <FinancialTab
          customerId={customerId}
          contactName={data?.name || "Cliente"}
          analytics={analytics.data}
          isLoading={analytics.isLoading}
          bootstrap={bootstrap}
          variant={variant}
          wallet={showWallet ? { canManage: canManageWallet } : null}
        />
      ),
    },
    {
      key: "comportamiento",
      label: "Comportamiento",
      content: (
        <BehaviorTab analytics={analytics.data} isLoading={analytics.isLoading} bootstrap={bootstrap} />
      ),
    },
    // Además del realm, el PERMISO: el GET de `/v1/reports/transactions` exige
    // `reports.sales.view` desde el 2026-09-01 (antes no chequeaba nada). Sin
    // este espejo, un rol que puede ver contactos pero no ventas —el `cashier`
    // seed, por ejemplo— seguía viendo el tab y se comía un 403 al abrirlo.
    ...(variant === "panel" && canViewSales
      ? [{ key: "transacciones", label: "Transacciones", content: <ContactTransactionsTab customerId={customerId} /> }]
      : []),
    // En `variant="pos"` NO se gatea: `usePermission` lee el bootstrap del
    // PANEL y en una tablet pareada ese bootstrap no existe, así que el espejo
    // escondería el tab por falta de credencial y no por falta de permiso.
    // Mismo criterio que el `variant === "panel" &&` del tab "Transacciones".
    ...(variant !== "panel" || canViewCustomers
      ? [{ key: "ordenes", label: "Órdenes", content: <ContactOrdersCompact customerId={customerId} /> }]
      : []),
    ...(calendarEnabled && canViewSchedule
      ? [{ key: "agenda", label: "Agenda", content: <ContactScheduleCompact customerId={customerId} /> }]
      : []),
    { key: "packs", label: "Packs", content: <PacksTab contactId={customerId} /> },
  ]

  // ── Encabezado ────────────────────────────────────────────────────────────
  // Nombre + atributos (segmento, archivado) como badges; RUC/teléfono/email y
  // las fechas de relación como texto secundario. Antes el segmento, "cliente
  // desde" y "última visita" ocupaban una fila entera del Resumen.
  const segment = analytics.data?.segment
  const visits = analytics.data?.visits
  const identity = [
    data?.tin ? `RUC ${data.tin}` : null,
    // formatPhone: la BD guarda E.164 sin '+' y el subtítulo lo pintaba crudo
    // ("595991742353"). Esta vista la monta también el POS
    // (customer-dialog.tsx), así que el cajero veía el número sin formato.
    formatPhone(data?.phone) || null,
    data?.email ?? null,
  ].filter(Boolean).join(" · ")
  const relation = [
    data?.date ? `Cliente desde ${formatDate(data.date)}` : null,
    analytics.data ? `Última visita: ${lastVisitLabel(visits?.lastAt, visits?.daysSinceLast)}` : null,
  ].filter(Boolean).join(" · ")

  const statusBadges = (
    <>
      {segment?.label && <Badge variant={segmentVariant(segment.key)}>{segment.label}</Badge>}
      {data && (data.status ?? 1) !== 1 && <Badge variant="outline">Archivado</Badge>}
    </>
  )

  const archiveAction = !isPos && (
    <AlertDialog>
      <AlertDialogTrigger asChild>
        <Button variant="outline">Archivar</Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>¿Archivar este contacto?</AlertDialogTitle>
          <AlertDialogDescription>
            No se elimina — queda con estado &quot;Archivado&quot;. Lo podés
            reactivar volviendo a prender el switch de estado.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancelar</AlertDialogCancel>
          <AlertDialogAction autoFocus onClick={onArchive} disabled={archive.isPending}>
            {archive.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
            Archivar
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )

  // ── Panel: el armazón canónico de ficha ─────────────────────────────────
  if (nav === "tabs") {
    return (
      <Form {...form}>
        <form onSubmit={form.handleSubmit(onSubmit)}>
          <EntityShell
            back={back}
            title={data?.name || "Contacto"}
            isLoading={isLoading}
            avatar={
              <Avatar className="size-10 shrink-0">
                <AvatarFallback className="text-sm font-medium">
                  {isLoading ? "…" : entityInitials(data?.name)}
                </AvatarFallback>
              </Avatar>
            }
            status={statusBadges}
            subtitle={
              identity || relation ? (
                <div className="flex flex-col gap-0.5">
                  {identity && <span>{identity}</span>}
                  {relation && <span>{relation}</span>}
                </div>
              ) : null
            }
            actions={archiveAction}
            summary={summary}
            data={dataTab}
            extraTabs={extraTabs}
            tabAliases={CONTACT_TAB_ALIASES}
            save={{ pending: update.isPending }}
          />
        </form>
      </Form>
    )
  }

  // ── Caja: menú lateral dentro del diálogo del POS ──────────────────────
  // Mismo orden y mismos nombres que el panel: salen de `resolveEntityTabs`.
  const sections = resolveEntityTabs({ summary, data: dataTab, extraTabs })
  const active = sections.find((s) => s.key === posTab) ?? sections[0]

  return (
    <Form {...form}>
      <form
        onSubmit={form.handleSubmit(onSubmit)}
        // En el POS esta vista se monta dentro de un diálogo que en móvil es
        // FULLSCREEN (`customer-dialog.tsx`): toca los cuatro bordes físicos
        // del teléfono. Los insets laterales se descuentan una sola vez acá
        // —el formulario no pinta fondo, así que el `bg-popover` del diálogo
        // sigue llegando al borde y solo se corre el contenido— y los insets
        // vertical arriba/abajo los descuenta cada chrome (header y footer).
        className="flex h-full flex-col max-sm:safe-area-x"
      >
        {/* El `pt-6` son 24px desde el borde FÍSICO cuando el diálogo va
            fullscreen: el nombre del cliente y —peor— la X de cerrar quedaban
            adentro del status bar. Esta X es la ÚNICA salida de la ficha
            (`showCloseButton={false}` en el call-site). */}
        <header className="flex shrink-0 items-start justify-between gap-3 px-6 pt-6 pb-2 max-sm:pt-[calc(1.5rem+var(--safe-t))]">
          <div className="flex min-w-0 items-center gap-2.5">
            <Avatar className="size-9 shrink-0">
              <AvatarFallback className="text-xs font-medium">
                {isLoading ? "…" : entityInitials(data?.name)}
              </AvatarFallback>
            </Avatar>
            <div className="flex min-w-0 flex-col">
              <div className="flex min-w-0 items-center gap-2">
                <h2 className="truncate text-base font-semibold leading-tight">
                  {isLoading ? <Skeleton className="h-4 w-40" /> : (data?.name || "Contacto")}
                </h2>
                {!isLoading && statusBadges}
              </div>
              {isLoading ? (
                <Skeleton className="mt-1 h-3 w-56" />
              ) : (
                <p className="truncate text-xs text-muted-foreground">
                  {identity || "Sin datos de contacto"}
                </p>
              )}
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            {active.key === ENTITY_DATA_KEY && (
              <Button type="submit" size="sm" disabled={update.isPending || isLoading}>
                {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Guardar
              </Button>
            )}
            {onClose && (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={onClose}
                aria-label="Cerrar"
                // 32px de `size="icon"` no es un blanco táctil en un teléfono
                // (mínimo 44px), y acá es la única salida de la ficha.
                className="max-sm:size-11"
              >
                <X className="size-4" />
              </Button>
            )}
          </div>
        </header>

        {/* En el POS esta vista se monta en un diálogo que en móvil va
            fullscreen, y ahí una columna de 220px se comía más de la mitad del
            ancho del teléfono. Bajo `sm` el menú pasa a ser una tira
            horizontal scrolleable arriba (pedido del owner sobre la ficha de
            la CAJA, 2026-08-25). */}
        <div className="grid min-h-0 flex-1 grid-cols-1 grid-rows-[auto_1fr] sm:grid-cols-[220px_1fr] sm:grid-rows-1">
          <nav
            aria-label="Secciones del cliente"
            className="flex shrink-0 gap-0.5 bg-card p-3 max-sm:flex-row max-sm:overflow-x-auto max-sm:border-b max-sm:p-2 sm:flex-col sm:border-r"
          >
            {sections.map((s) => (
              <Button
                key={s.key}
                type="button"
                variant="ghost"
                onClick={() => setPosTab(s.key)}
                className={cn(
                  "h-auto w-full shrink-0 justify-start rounded-md px-2.5 py-2 text-left text-sm font-normal",
                  // Tira horizontal: cada item se ajusta a su texto, no parte
                  // el label en dos líneas y respeta el blanco táctil de 44px.
                  "max-sm:min-h-11 max-sm:w-auto max-sm:whitespace-nowrap max-sm:px-3",
                  active.key === s.key
                    ? "bg-accent font-medium text-accent-foreground"
                    : "text-muted-foreground hover:bg-accent/50 hover:text-foreground",
                )}
                aria-current={active.key === s.key ? "page" : undefined}
              >
                {s.label}
              </Button>
            ))}
          </nav>
          <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            <header className="flex items-center gap-2 border-b py-3 pl-6 pr-4 text-sm">
              <span className="text-muted-foreground">Cliente</span>
              <span className="text-muted-foreground/50">›</span>
              <span className="text-foreground">{active.label}</span>
            </header>
            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 sm:p-6">
              {active.content}
            </div>
          </div>
        </div>

        {/* Footer fijo — solo en la caja */}
        {onSelectForSale && (
          <div className="flex shrink-0 justify-end border-t px-6 py-4 max-sm:pb-[calc(1rem+var(--safe-b))]">
            <Button
              type="button"
              size="lg"
              disabled={isLoading || !data}
              onClick={() => {
                if (!data) return
                // Construir PosCustomer desde ContactFull
                const posContact: PosCustomer = {
                  id: customerId,
                  name: data.name ?? "",
                  phone: data.phone ?? null,
                  tin: data.tin ?? null,
                  storeCredit: 0,
                  isCreditable: false,
                }
                onSelectForSale(posContact)
              }}
            >
              Añadir
            </Button>
          </div>
        )}
      </form>
    </Form>
  )
}

// ── ContactFormBody ───────────────────────────────────────────────────────────

/**
 * El formulario del contacto. Lo usan la pestaña Datos de la ficha y el alta
 * (`app/(panel)/contacts/[id]/page.tsx` con id "new") — el mismo componente,
 * no una copia.
 */
export function ContactFormBody({
  form,
  kind,
  country,
  setCountry,
  tenant,
}: {
  form: UseFormReturn<ContactFormValues>
  kind: "persona" | "empresa"
  country: CountryCode
  setCountry: (c: CountryCode) => void
  /**
   * Bootstrap del TENANT — no confundir con `country`, que es el país del
   * TELÉFONO. De acá salen los nombres de los documentos del país (RUC, CUIT,
   * DNI…) y si hay taxonomía de tipo; ver `lib/contact-id-types.ts`.
   */
  tenant: TenantLocaleConfig | null | undefined
}) {
  const { data: priceLists } = usePriceLists()
  const isCreditable = form.watch("isCreditable")
  const idType = form.watch("idType")
  const idTypes = contactIdTypesFor(tenant)
  const taxCopy = taxIdFieldCopy(tenant)
  const personalCopy = personalIdFieldCopy(tenant, idType)

  // Lookup del RUC en el padrón (backend: /v1/contacts?resource=taxpayer).
  // Completa la razón social del campo que corresponda al tipo de contacto:
  // una empresa la lleva en `fiscalName`, una persona en `name`.
  const lookupTaxpayer = useTaxpayerLookup()
  function handleLookupRuc() {
    const raw = (form.getValues("tin") ?? "").trim()
    if (!raw) {
      toast.warning("Ingresá un RUC para buscar")
      return
    }
    lookupTaxpayer.mutate(raw, {
      onSuccess: (data) => {
        form.setValue("tin", data.ruc, { shouldValidate: true, shouldDirty: true })
        form.setValue(kind === "empresa" ? "fiscalName" : "name", data.name, {
          shouldValidate: true,
          shouldDirty: true,
        })
        toast.success(data.status ? `${data.name} · ${data.status}` : data.name)
      },
      onError: (err) =>
        toast.error(
          err instanceof ApiError && err.status === 404
            ? "RUC no encontrado"
            : "No se pudo consultar el RUC",
        ),
    })
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      {/* Columna izquierda — quién es el contacto (identidad/fiscal) */}
      <div className="flex flex-col gap-6">
      <Section title="Identificación">
        <FormField
          control={form.control}
          name="kind"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Tipo</FormLabel>
              <FormControl>
                <Tabs value={field.value} onValueChange={field.onChange}>
                  <TabsList className="grid w-full grid-cols-2">
                    <TabsTrigger value="persona">Persona</TabsTrigger>
                    <TabsTrigger value="empresa">Empresa</TabsTrigger>
                  </TabsList>
                </Tabs>
              </FormControl>
            </FormItem>
          )}
        />

        {kind === "persona" ? (
          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Nombre y apellido</FormLabel>
                <FormControl>
                  <Input placeholder="Ej: Ana García" autoComplete="name" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        ) : (
          <>
            <FormField
              control={form.control}
              name="fiscalName"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Razón social</FormLabel>
                  <FormControl>
                    <Input placeholder="Ej: Empresa SA" autoComplete="organization" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Persona de contacto</FormLabel>
                  <FormControl>
                    <Input placeholder="Ej: Ana García (opcional)" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </>
        )}

        {/* Tipo de documento — solo en países con taxonomía propia (hoy PY:
            Tabla 3 de la SET). Gatea también el label/placeholder del campo
            de abajo (personalCopy). Ver lib/contact-id-types.ts */}
        {/* Solo para PERSONA: una empresa es contribuyente por definición y se
            identifica con su RUC. `SaleToFePyMapper::buildClient()` ignora
            el idType en la rama 'contribuyente'. */}
        {idTypes.length > 0 && kind === "persona" && (
          <FormField
            control={form.control}
            name="idType"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Tipo de documento</FormLabel>
                <Select
                  value={String(field.value ?? 12)}
                  onValueChange={(v) => field.onChange(Number(v))}
                >
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {idTypes.map((t) => (
                      <SelectItem key={t.code} value={String(t.code)}>
                        {t.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
        )}

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField
            control={form.control}
            name="tin"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{taxCopy.label}</FormLabel>
                <div className="flex gap-2">
                  <FormControl>
                    <Input placeholder={taxCopy.placeholder} className="tabular-nums" {...field} />
                  </FormControl>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="shrink-0"
                    onClick={handleLookupRuc}
                    disabled={lookupTaxpayer.isPending}
                    title="Buscar datos del RUC"
                    aria-label="Buscar datos del RUC"
                  >
                    {lookupTaxpayer.isPending ? (
                      <Loader2 className="size-4 animate-spin" />
                    ) : (
                      <SearchCode className="size-4" />
                    )}
                  </Button>
                </div>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="ci"
            render={({ field }) => (
              <FormItem>
                {/* Label/placeholder acompañan el tipo elegido arriba (CI,
                    pasaporte, carnet diplomático...) — mismo campo/columna
                    (contactCI) para los 6 tipos no-RUC. */}
                <FormLabel>{personalCopy.label}</FormLabel>
                <FormControl>
                  <Input placeholder={personalCopy.placeholder} className="tabular-nums" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </div>

        <FormField
          control={form.control}
          name="bday"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Cumpleaños</FormLabel>
              <FormControl>
                <DatePicker value={field.value ?? ""} onChange={field.onChange} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>
      </div>

      {/* Columna derecha — cómo contactarlo + configuración comercial */}
      <div className="flex flex-col gap-6">
      {/* Contacto */}
      <Section title="Contacto">
        <FormField
          control={form.control}
          name="phone"
          render={({ field, fieldState }) => (
            <FormItem>
              <FormLabel>Teléfono</FormLabel>
              <FormControl>
                <PhoneInput
                  value={field.value ?? ""}
                  country={country}
                  onChange={(v) => {
                    field.onChange(v.e164 ?? v.value)
                    setCountry(v.country)
                  }}
                  aria-invalid={!!fieldState.error}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="email"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Email</FormLabel>
              <FormControl>
                <Input
                  type="email"
                  placeholder="cliente@empresa.com"
                  autoComplete="email"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="note"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Notas</FormLabel>
              <FormControl>
                <Textarea rows={3} placeholder="Observaciones internas" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>

      {/* Comercial — estado de la cuenta, lista de precios y crédito.
          Antes "Activo" y "Lista de precios" vivían en Identificación y
          "Crédito" era una sección aparte, todo apilado en una sola
          columna (el motivo del scroll largo reportado por el owner).
          Agrupados acá por afinidad: configuración comercial del
          contacto, no datos de quién es ni cómo contactarlo. */}
      <Section title="Comercial">
        <FormField
          control={form.control}
          name="status"
          render={({ field }) => (
            <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
              <FormLabel>Activo</FormLabel>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="priceListId"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Lista de precios</FormLabel>
              <Select
                value={field.value ?? "__none__"}
                onValueChange={(v) => field.onChange(v === "__none__" ? null : v)}
              >
                <FormControl>
                  <SelectTrigger>
                    <SelectValue placeholder="Precio base (sin lista)" />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectItem value="__none__">Precio base (sin lista)</SelectItem>
                  {(priceLists ?? [])
                    .filter((pl) => pl.status)
                    .map((pl) => (
                      <SelectItem key={pl.priceListId} value={pl.priceListId}>
                        {pl.priceListName}
                        {pl.defaultAdjustment !== 0 && (
                          <span className="ml-1 text-xs text-muted-foreground">
                            ({pl.defaultAdjustment > 0 ? "+" : "−"}{Math.abs(pl.defaultAdjustment)}%)
                          </span>
                        )}
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="isCreditable"
          render={({ field }) => (
            <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
              <FormLabel>Puede comprar a crédito</FormLabel>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="creditLine"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Línea de crédito</FormLabel>
              <FormControl>
                <MoneyInput
                  value={field.value}
                  onChange={field.onChange}
                  disabled={!isCreditable}
                  placeholder="0"
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>
      </div>
    </div>
  )
}

// ── Direcciones (sección de Datos) ─────────────────────────────────────────────────────────────

type AddressFormState = {
  name: string
  address: string
  location: string
  city: string
  reference: string
  lat: number | null
  lng: number | null
}

const emptyAddressForm = (): AddressFormState => ({
  name: "", address: "", location: "", city: "", reference: "", lat: null, lng: null,
})

function AddressesSection({ contactId }: { contactId: string }) {
  const { data: addresses, isLoading } = useCustomerAddresses(contactId)
  const addAddress = useAddAddress()
  const updateAddress = useUpdateAddress()
  const setDefault = useSetDefaultAddress()
  const deleteAddress = useDeleteAddress()

  // Alta/edición en <Dialog> (Regla #2.2 de context/14-ui-conventions.md —
  // nunca Sheet/Drawer). `editing` guarda la dirección en edición; null =
  // el diálogo abierto está creando una nueva.
  const [dialogOpen, setDialogOpen] = React.useState(false)
  const [editing, setEditing] = React.useState<CustomerAddress | null>(null)
  const [form, setForm] = React.useState<AddressFormState>(emptyAddressForm())

  const serializeForm = (f: AddressFormState) => ({
    name: f.name,
    address: f.address,
    location: f.location,
    city: f.city,
    reference: f.reference,
    lat: f.lat,
    lng: f.lng,
  })

  const openCreate = () => {
    setEditing(null)
    setForm(emptyAddressForm())
    setDialogOpen(true)
  }

  const openEdit = (addr: CustomerAddress) => {
    setEditing(addr)
    setForm({
      name: addr.name, address: addr.address, location: addr.location, city: addr.city,
      reference: addr.reference ?? "",
      lat: addr.lat !== null ? Number(addr.lat) : null,
      lng: addr.lng !== null ? Number(addr.lng) : null,
    })
    setDialogOpen(true)
  }

  const handleSubmit = async () => {
    try {
      if (editing) {
        await updateAddress.mutateAsync({ addressId: editing.id, customerId: contactId, ...serializeForm(form) })
        toast.success("Dirección actualizada")
      } else {
        await addAddress.mutateAsync({ customerId: contactId, ...serializeForm(form) })
        toast.success("Dirección agregada")
      }
      setDialogOpen(false)
    } catch (e) {
      toast.error(editing ? "No se pudo actualizar" : "No se pudo agregar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const handleSetDefault = async (addr: CustomerAddress) => {
    try {
      await setDefault.mutateAsync({ addressId: addr.id, customerId: contactId })
      toast.success("Dirección predeterminada actualizada")
    } catch (e) {
      toast.error("Error", { description: e instanceof Error ? e.message : undefined })
    }
  }

  const handleDelete = async (addr: CustomerAddress) => {
    try {
      await deleteAddress.mutateAsync({ addressId: addr.id, customerId: contactId })
      toast.success("Dirección eliminada")
    } catch (e) {
      toast.error("No se pudo eliminar", { description: e instanceof Error ? e.message : undefined })
    }
  }

  const isSaving = addAddress.isPending || updateAddress.isPending

  if (isLoading) {
    return (
      <div className="flex flex-col gap-3">
        {[1, 2].map((i) => <Skeleton key={i} className="h-24 w-full rounded-lg" />)}
      </div>
    )
  }

  // Sección dentro de Datos: menos de 10 filas = `divide-y` (context/84
  // T18), y el vacío es una línea con el link a la acción, no un EmptyState
  // de página (T7).
  return (
    <div className="flex flex-col gap-3">
      {!addresses || addresses.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Sin direcciones.{" "}
          <Button type="button" variant="link" className="h-auto p-0" onClick={openCreate}>
            Agregar
          </Button>
        </p>
      ) : (
        <>
          <div className="flex flex-col divide-y rounded-lg border">
            {addresses.map((addr) => (
              <div key={addr.id} className="flex items-start justify-between gap-3 px-3 py-2.5">
                <div className="flex min-w-0 flex-col gap-0.5">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium">{addr.name || "Sin nombre"}</span>
                    {addr.default && <Badge variant="secondary">Predeterminada</Badge>}
                  </div>
                  {addr.address && <span className="text-sm text-muted-foreground">{addr.address}</span>}
                  {(addr.location || addr.city) && (
                    <span className="text-xs text-muted-foreground">
                      {[addr.location, addr.city].filter(Boolean).join(", ")}
                    </span>
                  )}
                  {addr.reference && (
                    <span className="text-xs text-muted-foreground">Referencia: {addr.reference}</span>
                  )}
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  {!addr.default && (
                    <Button variant="ghost" size="sm" className="text-xs text-muted-foreground"
                      onClick={() => handleSetDefault(addr)} disabled={setDefault.isPending}>
                      Predeterminar
                    </Button>
                  )}
                  <Button variant="ghost" size="icon" aria-label="Editar dirección" onClick={() => openEdit(addr)}>
                    <Pencil className="size-4" />
                  </Button>
                  <AlertDialog>
                    <AlertDialogTrigger asChild>
                      <Button variant="ghost" size="icon" aria-label="Eliminar dirección" className="text-muted-foreground hover:text-destructive">
                        <Trash2 className="size-4" />
                      </Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                      <AlertDialogHeader>
                        <AlertDialogTitle>¿Eliminar esta dirección?</AlertDialogTitle>
                        <AlertDialogDescription>
                          Se eliminará &quot;{addr.name || addr.address}&quot;. Si una orden anterior la usó, esa
                          orden sigue mostrando a dónde fue — solo deja de aparecer acá.
                        </AlertDialogDescription>
                      </AlertDialogHeader>
                      <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                          onClick={() => handleDelete(addr)} disabled={deleteAddress.isPending}
                          className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                          Eliminar
                        </AlertDialogAction>
                      </AlertDialogFooter>
                    </AlertDialogContent>
                  </AlertDialog>
                </div>
              </div>
            ))}
          </div>
          <Button type="button" size="sm" variant="outline" className="w-fit" onClick={openCreate}>
            Nueva dirección
          </Button>
        </>
      )}

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editing ? "Editar dirección" : "Nueva dirección"}</DialogTitle>
          </DialogHeader>
          <div className="flex flex-col gap-3">
            <AddressFormFields form={form} onChange={setForm} />
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setDialogOpen(false)}>Cancelar</Button>
            <Button onClick={handleSubmit} disabled={isSaving}>
              {isSaving && <Loader2 className="mr-2 size-4 animate-spin" />}
              Guardar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function AddressFormFields({
  form,
  onChange,
}: {
  form: AddressFormState
  onChange: (f: AddressFormState) => void
}) {
  return (
    <>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <Label>Nombre / etiqueta</Label>
          <Input placeholder="Casa, Trabajo, Depósito..."
            value={form.name} onChange={(e) => onChange({ ...form, name: e.target.value })} />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Ciudad</Label>
          <Input placeholder="Asunción"
            value={form.city} onChange={(e) => onChange({ ...form, city: e.target.value })} />
        </div>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Dirección</Label>
        <AddressAutocompleteInput
          placeholder="Calle y número"
          value={form.address}
          onValueChange={(v) => onChange({ ...form, address: v })}
          onSelect={(s: GeoSuggestion) =>
            onChange({
              ...form,
              address: s.street ?? form.address,
              city: s.city ?? form.city,
              location: s.neighborhood ?? form.location,
              lat: s.lat,
              lng: s.lng,
            })
          }
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Referencia</Label>
        <Input placeholder="Portón negro, timbre 2..."
          value={form.reference} onChange={(e) => onChange({ ...form, reference: e.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Barrio / zona</Label>
        <Input placeholder="Carmelitas, San Lorenzo..."
          value={form.location} onChange={(e) => onChange({ ...form, location: e.target.value })} />
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <Label>Latitud</Label>
          <Input type="number" inputMode="decimal" step="0.0000001" placeholder="-25.2867"
            value={form.lat ?? ""} className="tabular-nums"
            onChange={(e) => onChange({ ...form, lat: e.target.value === "" ? null : Number(e.target.value) })} />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Longitud</Label>
          <Input type="number" inputMode="decimal" step="0.0000001" placeholder="-57.6478"
            value={form.lng ?? ""} className="tabular-nums"
            onChange={(e) => onChange({ ...form, lng: e.target.value === "" ? null : Number(e.target.value) })} />
        </div>
      </div>
      <AddressMapParser
        onParsed={({ lat, lng, address, city, neighborhood, placeName }) => {
          // Conservador: cada campo solo se completa si estaba vacío — nunca
          // pisa lo que el usuario ya escribió. `address`/`city`/`neighborhood`
          // vienen de reverse geocoding (Photon) sobre las coordenadas del
          // link, no del texto crudo — un link "place" puede traer el nombre
          // de un comercio ahí, y eso va a Referencia (`placeName`), nunca a
          // Dirección.
          onChange({
            ...form,
            lat,
            lng,
            address: !form.address.trim() && address ? address : form.address,
            city: !form.city.trim() && city ? city : form.city,
            location: !form.location.trim() && neighborhood ? neighborhood : form.location,
            reference: !form.reference.trim() && placeName ? placeName : form.reference,
          })
          toast.success(`Coordenadas: ${lat}, ${lng}`)
        }}
      />
      <AddressMapPreview lat={form.lat} lng={form.lng} />
    </>
  )
}

const OFM_STYLE_LIGHT = "https://tiles.openfreemap.org/styles/positron"
const OFM_STYLE_DARK = "https://tiles.openfreemap.org/styles/fiord"

/**
 * Preview de la dirección con MapLibre GL + estilos vectoriales de OpenFreeMap.
 *  - light → positron (gris claro tipo Carto)
 *  - dark  → fiord (oscuro azulado)
 *
 * Sin API key. El estilo se cambia en runtime con setStyle cuando alterna el
 * tema; el marker se reaplica en cada style change porque MapLibre limpia
 * markers al cambiar de style.
 */
function AddressMapPreview({ lat, lng }: { lat: number | null; lng: number | null }) {
  const { resolvedTheme } = useTheme()
  const isDark = resolvedTheme === "dark"
  const containerRef = React.useRef<HTMLDivElement | null>(null)
  const mapRef = React.useRef<MapLibreMap | null>(null)
  const markerRef = React.useRef<MapLibreMarker | null>(null)

  const valid = lat !== null && lng !== null && !Number.isNaN(lat) && !Number.isNaN(lng)

  React.useEffect(() => {
    if (!valid || !containerRef.current) return
    let cancelled = false

    void import("maplibre-gl").then((mod) => {
      if (cancelled || !containerRef.current) return
      const maplibregl = mod.default ?? mod
      if (mapRef.current) return

      const map = new maplibregl.Map({
        container: containerRef.current,
        style: isDark ? OFM_STYLE_DARK : OFM_STYLE_LIGHT,
        center: [lng as number, lat as number],
        zoom: 15,
        attributionControl: { compact: true },
      })
      mapRef.current = map
      map.on("load", () => {
        if (cancelled) return
        markerRef.current = new maplibregl.Marker({ color: "var(--primary)" })
          .setLngLat([lng as number, lat as number])
          .addTo(map)
      })
    })

    return () => {
      cancelled = true
      markerRef.current?.remove()
      markerRef.current = null
      mapRef.current?.remove()
      mapRef.current = null
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [valid])

  // Cambio de tema → setStyle + re-aplicar el marker en 'styledata'.
  React.useEffect(() => {
    if (!mapRef.current) return
    const map = mapRef.current
    const styleUrl = isDark ? OFM_STYLE_DARK : OFM_STYLE_LIGHT
    map.setStyle(styleUrl)
    const handler = () => {
      if (!valid) return
      void import("maplibre-gl").then((mod) => {
        const maplibregl = mod.default ?? mod
        markerRef.current?.remove()
        markerRef.current = new maplibregl.Marker({ color: "var(--primary)" })
          .setLngLat([lng as number, lat as number])
          .addTo(map)
      })
      map.off("styledata", handler)
    }
    map.on("styledata", handler)
  }, [isDark, lat, lng, valid])

  // Cambio de coords → mover el centro y el marker (sin recrear el mapa).
  React.useEffect(() => {
    if (!mapRef.current || !valid) return
    mapRef.current.setCenter([lng as number, lat as number])
    markerRef.current?.setLngLat([lng as number, lat as number])
  }, [lat, lng, valid])

  if (!valid) {
    return (
      <div className="flex h-40 items-center justify-center rounded-lg border border-dashed border-border bg-muted/30 text-xs text-muted-foreground">
        Ingresá latitud y longitud para previsualizar el mapa.
      </div>
    )
  }
  const externalUrl = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=17/${lat}/${lng}`
  return (
    <div className="flex flex-col gap-1.5">
      <div ref={containerRef} className="h-48 w-full overflow-hidden rounded-lg border bg-muted" />
      <a
        href={externalUrl}
        target="_blank"
        rel="noopener noreferrer"
        className="self-end text-xs text-muted-foreground hover:text-foreground hover:underline"
      >
        Abrir en OpenStreetMap →
      </a>
    </div>
  )
}

// ── PacksTab ─────────────────────────────────────────────────────────────────

function PacksTab({ contactId }: { contactId: string }) {
  const { data: packs, isLoading } = useContactPacks(contactId)
  if (isLoading) {
    return (
      <div className="flex flex-col gap-3">
        {[1, 2, 3].map((i) => <Skeleton key={i} className="h-32 w-full rounded-lg" />)}
      </div>
    )
  }
  if (!packs || packs.length === 0) {
    return <EmptyLine label="Sin packs activos." />
  }
  return (
    <div className="flex flex-col gap-4">
      {packs.map((pack) => <PackCard key={pack.soldPackId} pack={pack} />)}
    </div>
  )
}

function PackCard({ pack }: { pack: SoldPack }) {
  const statusInfo = (() => {
    if (pack.status === 2) return { label: "Consumido", variant: "secondary" as const }
    if (pack.status === 0) return { label: "Vencido / bloqueado", variant: "destructive" as const }
    return { label: "Activo", variant: "default" as const }
  })()
  const expiresAt = new Date(pack.expiresAt)
  const now = new Date()
  const daysLeft = Math.ceil((expiresAt.getTime() - now.getTime()) / (1000 * 60 * 60 * 24))
  return (
    <Card>
      <CardHeader className="pb-2">
        <div className="flex items-start justify-between gap-2">
          <CardTitle>{pack.packName}</CardTitle>
          <div className="flex items-center gap-2 shrink-0">
            <Badge variant={statusInfo.variant} className="text-xs">{statusInfo.label}</Badge>
          </div>
        </div>
        <p className="text-xs text-muted-foreground">
          {pack.status === 1 && daysLeft > 0
            ? `Vence ${formatDate(pack.expiresAt ?? "")} (${daysLeft} día${daysLeft !== 1 ? "s" : ""})`
            : `Venció ${formatDate(pack.expiresAt ?? "")}`}
        </p>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {pack.components.map((comp) => {
          const pct = comp.componentQty > 0 ? Math.round((comp.remaining / comp.componentQty) * 100) : 0
          return (
            <div key={comp.packComponentId} className="flex flex-col gap-1">
              <div className="flex items-center justify-between text-sm">
                <span className="truncate">{comp.name}</span>
                <span className="ml-2 shrink-0 text-xs text-muted-foreground tabular-nums">
                  {comp.remaining} / {comp.componentQty}
                </span>
              </div>
              <div className="h-1.5 w-full rounded-full bg-muted">
                <div
                  className={cn(
                    "h-full rounded-full transition-all",
                    pct > 50 ? "bg-chart-1" : pct > 20 ? "bg-chart-3" : "bg-destructive",
                  )}
                  style={{ width: `${pct}%` }}
                />
              </div>
            </div>
          )
        })}
      </CardContent>
    </Card>
  )
}

// ── SummaryTab ────────────────────────────────────────────────────────────────
//
// Referencia visual: el dashboard de Ventas (context/84 §2.1). Números en
// StatTile gris; contenido (gráfico, rankings) en cards blancas con
// CardTitle. El segmento, "cliente desde" y "última visita" NO van acá: son
// metadata del encabezado de la ficha.

function SummaryTab({
  analytics,
  isLoading,
  bootstrap,
}: {
  analytics: ContactAnalytics | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const totals = analytics?.totals
  const visits = analytics?.visits
  const monthSeries = analytics?.byMonth ?? []
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3">
        <StatsRow>
          <StatTile label="Total gastado" value={formatMoney(totals?.spent, bootstrap)} emphasis isLoading={isLoading} />
          <StatTile label="Compras" value={formatInt(totals?.purchases, bootstrap)} isLoading={isLoading} />
          <StatTile label="Artículos" value={formatInt(totals?.itemsBought, bootstrap)} isLoading={isLoading} />
          <StatTile label="Ticket promedio" value={formatMoney(totals?.avgTicket, bootstrap)} isLoading={isLoading} />
        </StatsRow>
        <StatsRow>
          <StatTile
            label="Primera operación"
            value={visits?.firstAt ? formatDate(visits.firstAt) : "Sin operaciones"}
            isLoading={isLoading}
          />
          <StatTile
            label="Frecuencia promedio"
            value={freqLabel(visits?.avgDaysBetween ?? null)}
            isLoading={isLoading}
          />
          <StatTile
            label="Descuento acumulado"
            value={formatMoney(totals?.discountTotal, bootstrap)}
            isLoading={isLoading}
          />
        </StatsRow>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Compras por mes</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? <Skeleton className="h-[220px] w-full" /> :
            monthSeries.length === 0 ? <EmptyLine label="Sin operaciones registradas." /> : (
              <ResponsiveContainer width="100%" height={220}>
                <LineChart data={monthSeries} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                  <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="month" tickFormatter={(v: string) => formatBucketTick(`${v}-01`, "month")} tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} />
                  <YAxis tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} width={36} tickFormatter={(v: number) => compactNum(v)} />
                  <Tooltip cursor={{ stroke: "var(--accent)", strokeWidth: 1 }}
                    contentStyle={{ background: "var(--popover)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }}
                    labelFormatter={(v) => formatBucketLabel(`${v}-01`, "month")}
                    formatter={(v) => formatMoney(Number(v), bootstrap)} />
                  <Line type="monotone" dataKey="total" stroke="var(--chart-1)" strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
                </LineChart>
              </ResponsiveContainer>
            )}
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        <TopItemsCard items={analytics?.topItems ?? []} isLoading={isLoading} bootstrap={bootstrap} />
        <TopCategoriesCard items={analytics?.topCategories ?? []} isLoading={isLoading} bootstrap={bootstrap} />
      </div>
    </div>
  )
}

// ── BehaviorTab ───────────────────────────────────────────────────────────────

function BehaviorTab({
  analytics,
  isLoading,
  bootstrap,
}: {
  analytics: ContactAnalytics | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const hourSeries  = analytics?.byHour ?? []
  const dowSeries   = analytics?.byDayOfWeek ?? []
  const paymentMix  = analytics?.paymentMix ?? []
  const outlets     = analytics?.byOutlet ?? []
  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Horarios preferidos</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-[180px] w-full" /> :
              hourSeries.length === 0 ? <EmptyLine label="Sin datos de horario." /> : (
                <ResponsiveContainer width="100%" height={180}>
                  <BarChart data={hourSeries} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                    <XAxis dataKey="hour" tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} />
                    <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} width={28} />
                    <Tooltip cursor={{ fill: "var(--accent)", opacity: 0.5 }}
                      contentStyle={{ background: "var(--popover)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }} />
                    <Bar dataKey="count" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Día de la semana</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-[180px] w-full" /> :
              dowSeries.length === 0 ? <EmptyLine label="Sin datos por día." /> : (
                <ResponsiveContainer width="100%" height={180}>
                  <BarChart data={dowSeries} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                    <XAxis dataKey="label" tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} />
                    <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} width={28} />
                    <Tooltip cursor={{ fill: "var(--accent)", opacity: 0.5 }}
                      contentStyle={{ background: "var(--popover)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }} />
                    <Bar dataKey="count" fill="var(--chart-3)" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
          </CardContent>
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Forma de pago</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-[200px] w-full" /> :
              paymentMix.length === 0 ? <EmptyLine label="Sin operaciones." /> : (
                <div className="flex items-center gap-4">
                  <div className="relative size-36 shrink-0">
                    <ResponsiveContainer width="100%" height="100%">
                      <PieChart>
                        <Pie data={paymentMix} dataKey="total" cx="50%" cy="50%"
                          innerRadius="62%" outerRadius="100%" paddingAngle={2} strokeWidth={0}>
                          {paymentMix.map((_, i) => <Cell key={i} fill={`var(--chart-${(i % 5) + 1})`} />)}
                        </Pie>
                        <Tooltip contentStyle={{ background: "var(--popover)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }}
                          formatter={(v) => formatMoney(Number(v), bootstrap)} />
                      </PieChart>
                    </ResponsiveContainer>
                  </div>
                  <div className="flex flex-1 flex-col gap-2">
                    {paymentMix.map((p, i) => (
                      <div key={p.type} className="flex items-center justify-between gap-2 text-xs">
                        <span className="flex items-center gap-1.5 text-muted-foreground">
                          <span className="size-2 rounded-full" style={{ background: `var(--chart-${(i % 5) + 1})` }} />
                          {p.label}
                        </span>
                        <span className="font-medium tabular-nums">{formatMoney(p.total, bootstrap)}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Sucursales preferidas</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-32 w-full" /> :
              outlets.length === 0 ? <EmptyLine label="Sin datos por sucursal." /> : (
                <div className="flex flex-col divide-y divide-border">
                  {outlets.map((o) => (
                    <div key={o.outletId} className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0">
                      <span className="truncate">{o.name}</span>
                      <span className="text-xs text-muted-foreground tabular-nums">
                        {o.count} ops · {formatMoney(o.total, bootstrap)}
                      </span>
                    </div>
                  ))}
                </div>
              )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

// ── FinancialTab ──────────────────────────────────────────────────────────────
//
// UN solo set de KPIs arriba (owner 2026-09-18): antes "Cuentas por cobrar"
// arriba y "Deuda total" dentro del estado de cuenta mostraban el MISMO número
// dos veces, y "Estado de crédito" era un bloque entero para un solo badge.
// Ahora el estado de crédito es un atributo junto a la línea de crédito, el
// número de la deuda sale una sola vez (del estado de cuenta, la fuente que
// también usa la tabla de abajo) y el contenido —bolsillos, facturas a
// crédito con su "Cobrar crédito", cobros aplicados— va en cards blancas.

function FinancialTab({
  customerId,
  contactName,
  analytics,
  isLoading,
  bootstrap,
  variant,
  wallet,
}: {
  customerId: string
  contactName: string
  analytics: ContactAnalytics | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
  /** "panel" | "pos" — pasado tal cual a `AccountStatementSection` para que
   *  el click de fila no navegue fuera del POS. Ver docblock del archivo. */
  variant: "panel" | "pos"
  /** Saldo de la wallet (context/74). `null` = no se muestra (ver el gate). */
  wallet: { canManage: boolean } | null
}) {
  const f = analytics?.financial
  const statement = useContactStatement(customerId, 1)
  const summary = statement.data?.summary
  return (
    <div className="flex flex-col gap-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <StatTile
          label="Cuentas por cobrar"
          value={formatMoney(summary?.totalDebt, bootstrap)}
          emphasis
          isLoading={statement.isLoading}
        />
        <StatTile
          label="Facturado a crédito"
          value={formatMoney(summary?.totalCredited, bootstrap)}
          isLoading={statement.isLoading}
        />
        <StatTile
          label="Cobrado"
          value={formatMoney(summary?.totalPaid, bootstrap)}
          isLoading={statement.isLoading}
        />
        <StatTile
          label="Línea de crédito"
          value={
            <span className="flex flex-wrap items-center gap-2">
              {formatMoney(f?.creditLine, bootstrap)}
              <Badge variant={f?.isCreditable ? "default" : "secondary"}>
                {f?.isCreditable ? "Habilitado" : "Sin crédito"}
              </Badge>
            </span>
          }
          isLoading={isLoading}
        />
        <StatTile label="Crédito a favor" value={formatMoney(f?.storeCredit, bootstrap)} isLoading={isLoading} />
        <StatTile label="Loyalty acumulado" value={formatMoney(f?.loyalty, bootstrap)} isLoading={isLoading} />
      </div>
      {wallet && <WalletSection contactId={customerId} canManage={wallet.canManage} />}
      <AccountStatementSection
        contactId={customerId}
        contactType={1}
        contactName={contactName}
        variant={variant}
      />
    </div>
  )
}

// ── Helpers compartidos ───────────────────────────────────────────────────────
// `AccountStatementSection` vive en su propio archivo — se reusa tal cual
// desde el detalle por contacto del reporte de cuentas por cobrar/pagar
// (`/reports/open-invoices`).

function TopItemsCard({
  items, isLoading, bootstrap,
}: {
  items: ContactAnalytics["topItems"]; isLoading: boolean; bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Productos preferidos</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-5 w-full" />)}</div>
        ) : items.length === 0 ? <EmptyLine label="Sin compras registradas." /> : (
          <div className="flex flex-col divide-y divide-border">
            {items.map((it) => (
              <div key={it.itemId} className="flex items-center justify-between gap-2 py-1.5 text-sm first:pt-0 last:pb-0">
                <span className="truncate">{it.name}</span>
                <span className="text-xs text-muted-foreground tabular-nums">
                  {Math.round(it.count)} {it.uom || "ud"} · {formatMoney(it.total, bootstrap)}
                </span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function TopCategoriesCard({
  items, isLoading, bootstrap,
}: {
  items: ContactAnalytics["topCategories"]; isLoading: boolean; bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Categorías favoritas</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-5 w-full" />)}</div>
        ) : items.length === 0 ? <EmptyLine label="Sin categorías registradas." /> : (
          <div className="flex flex-col divide-y divide-border">
            {items.map((c) => (
              <div key={c.taxonomyId} className="flex items-center justify-between gap-2 py-1.5 text-sm first:pt-0 last:pb-0">
                <span className="truncate">{c.name}</span>
                <span className="text-xs text-muted-foreground tabular-nums">{formatMoney(c.total, bootstrap)}</span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

/** Vacío de una card de la ficha: una línea, no un EmptyState de página (T7). */
function EmptyLine({ label }: { label: string }) {
  return <p className="text-sm text-muted-foreground">{label}</p>
}

// ── Shared pure helpers ───────────────────────────────────────────────────────

function segmentVariant(key: string | undefined): "default" | "secondary" | "destructive" | "outline" {
  switch (key) {
    case "vip":       return "default"
    case "activo":    return "default"
    case "nuevo":     return "secondary"
    case "en_riesgo": return "outline"
    case "inactivo":  return "destructive"
    default:          return "secondary"
  }
}

function lastVisitLabel(iso: string | null | undefined, daysSince: number | null | undefined): string {
  if (!iso) return "Sin operaciones"
  if (daysSince === null || daysSince === undefined) return formatDate(iso ?? "")
  if (daysSince === 0) return "Hoy"
  if (daysSince === 1) return "Ayer"
  if (daysSince < 7)  return `Hace ${daysSince} días`
  if (daysSince < 30) return `Hace ${Math.round(daysSince / 7)} semanas`
  if (daysSince < 365) return `Hace ${Math.round(daysSince / 30)} meses`
  return `Hace ${Math.round(daysSince / 365)} años`
}

function freqLabel(avgDays: number | null): string {
  if (avgDays === null || avgDays <= 0) return "Sin datos suficientes"
  if (avgDays < 1)   return "Múltiples veces al día"
  if (avgDays < 2)   return "Diaria"
  if (avgDays < 14)  return `Cada ${Math.round(avgDays)} días`
  if (avgDays < 60)  return `Cada ${Math.round(avgDays / 7)} semanas`
  return `Cada ${Math.round(avgDays / 30)} meses`
}

function compactNum(v: number): string {
  if (v >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (v >= 1_000)     return `${(v / 1_000).toFixed(1)}K`
  return String(v)
}

export function emptyContactValues(): ContactFormValues {
  return {
    kind: "persona",
    name: "",
    fiscalName: "",
    tin: "",
    ci: "",
    // 12 explícito (no null): el Select mostraba "Cédula" por fallback
    // cosmético sin llamar a onChange — lo visible y lo enviado divergían.
    idType: 12,
    bday: "",
    phone: null,
    email: "",
    note: "",
    status: true,
    priceListId: null,
    isCreditable: false,
    creditLine: null,
  }
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <FormSection title={title}>{children}</FormSection>
}
