"use client"

/**
 * ContactDetailView — vista reutilizable del perfil de un contacto.
 *
 * Usado en:
 *   - `(panel)/contacts/[id]/page.tsx` → variant="panel" nav="tabs"
 *   - `register/customer-dialog.tsx`   → variant="pos"   nav="sidebar"
 *     (abre desde el botón ⋮ de cada cliente)
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
import {
  Loader2, Archive, BarChart3, Wallet,
  SearchCode, ShoppingBag, Layers,
  CalendarDays, MapPin, Sparkles, Inbox, X,
  ClipboardList as OrdersIcon, Receipt,
  Pencil, Trash2,
} from "lucide-react"
import { EmptyState as EmptyStateBlock } from "@/components/empty-state"
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
  Tabs, TabsContent, TabsList, TabsTrigger,
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
  FormDescription,
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
import { useModules } from "@/hooks/use-modules"
import { usePriceLists } from "@/hooks/use-price-lists"
import { ApiError } from "@/lib/api-client"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import { CONTACT_ID_TYPES, ciFieldCopyForIdType } from "@/lib/contact-id-types"
import { formatInt, formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
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
import { KpiCard } from "@/components/domain/contacts/kpi-card"
import { ContactOrdersCompact } from "@/components/domain/contacts/contact-orders-compact"
import { ContactScheduleCompact } from "@/components/domain/contacts/contact-schedule-compact"
import { ContactTransactionsTab } from "@/components/domain/contacts/contact-transactions-tab"
import { formatPhone } from "@/lib/phone"

// ── Zod schema (igual que el de la page original) ────────────────────────────

const contactSchema = z
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

type TabKey = "summary" | "behavior" | "financial" | "data" | "addresses" | "packs" | "orders" | "schedule" | "transactions"

export interface ContactDetailViewProps {
  customerId: string
  variant?: "panel" | "pos"
  nav?: "tabs" | "sidebar"
  onClose?: () => void
  onSelectForSale?: (contact: PosCustomer) => void
}

// ── Component ─────────────────────────────────────────────────────────────────

function initials(name: string | null | undefined): string {
  if (!name) return "?"
  return name
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join("")
}

export function ContactDetailView({
  customerId,
  variant = "panel",
  nav = "tabs",
  onClose,
  onSelectForSale,
}: ContactDetailViewProps) {
  const router = useRouter()
  const { data, isLoading, error } = useContact(customerId)
  const update = useUpdateContact()
  const archive = useArchiveContact()
  const [country, setCountry] = React.useState<CountryCode>(DEFAULT_COUNTRY)

  const form = useForm<ContactFormValues>({
    resolver: zodResolver(contactSchema),
    defaultValues: emptyValues(),
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

  const [tab, setTab] = React.useState<TabKey>("summary")

  const analytics = useContactAnalytics(
    tab !== "data" ? customerId : undefined,
    1,
  )
  const { data: bootstrap } = useBootstrap()
  // Tab "Agenda" (ContactScheduleCompact) depende del módulo calendar — mismo
  // criterio conservador que panel-auth-guard/pos-sidebar: oculto mientras
  // carga o está apagado, para no ofrecer una sección sin datos relevantes.
  const { data: modules, isLoading: modulesLoading } = useModules()
  const calendarEnabled = !modulesLoading && modules?.calendar?.enabled === true

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
        router.push("/contacts")
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

  if (error) {
    return (
      <Card>
        <CardContent className="p-8 text-center text-sm text-muted-foreground">
          No se pudo cargar el contacto. {error.message}
        </CardContent>
      </Card>
    )
  }

  // Secciones para la navegación (tabs o sidebar).
  // "transactions" es panel-only: pega contra /v1/reports/transactions, que
  // solo acepta realm `panel` (apiAuthTenant(['panel'])). El customer-dialog
  // del POS (variant="pos") corre con el Bearer del device (realm pos-app) —
  // si se mostrara ahí, el fetch devolvería 401 "Token de otro realm".
  const sections: { key: TabKey; label: string; icon: React.ReactNode }[] = [
    { key: "summary",  label: "Resumen",      icon: <BarChart3 className="size-3.5" /> },
    { key: "behavior", label: "Comportamiento", icon: <Sparkles className="size-3.5" /> },
    { key: "financial",label: "Financiero",    icon: <Wallet className="size-3.5" /> },
    ...(variant === "panel"
      ? [{ key: "transactions" as const, label: "Transacciones", icon: <Receipt className="size-3.5" /> }]
      : []),
    { key: "packs",    label: "Packs",         icon: <Layers className="size-3.5" /> },
    { key: "addresses",label: "Direcciones",   icon: <MapPin className="size-3.5" /> },
    { key: "orders",   label: "Órdenes",       icon: <OrdersIcon className="size-3.5" /> },
    ...(calendarEnabled
      ? [{ key: "schedule" as const, label: "Agenda", icon: <CalendarDays className="size-3.5" /> }]
      : []),
    { key: "data",     label: "Datos",         icon: <ShoppingBag className="size-3.5" /> },
  ]

  // Contenido del tab activo (compartido entre nav=tabs y nav=sidebar)
  const tabContent = (
    <>
      {tab === "summary" && (
        <SummaryTab
          contact={data}
          analytics={analytics.data}
          isLoading={analytics.isLoading || isLoading}
          bootstrap={bootstrap}
        />
      )}
      {tab === "behavior" && (
        <BehaviorTab
          analytics={analytics.data}
          isLoading={analytics.isLoading}
          bootstrap={bootstrap}
        />
      )}
      {tab === "financial" && (
        <FinancialTab
          customerId={customerId}
          contactName={data?.name || "Cliente"}
          analytics={analytics.data}
          isLoading={analytics.isLoading}
          bootstrap={bootstrap}
          variant={variant}
        />
      )}
      {tab === "transactions" && <ContactTransactionsTab customerId={customerId} />}
      {tab === "packs" && <PacksTab contactId={customerId} />}
      {tab === "addresses" && <AddressesTab contactId={customerId} />}
      {tab === "orders" && <ContactOrdersCompact customerId={customerId} />}
      {tab === "schedule" && <ContactScheduleCompact customerId={customerId} />}
      {tab === "data" && (
        <ContactFormBody
          form={form}
          kind={kind}
          country={country}
          setCountry={setCountry}
          isPyTenant={bootstrap?.country === "PY"}
        />
      )}
    </>
  )

  const isPos = variant === "pos"

  return (
    <Form {...form}>
      <form
        onSubmit={form.handleSubmit(onSubmit)}
        className={cn(
          "flex flex-col",
          isPos ? "h-full" : "gap-6",
        )}
      >
        {/* Header */}
        <header className={cn(
          "flex items-start justify-between gap-3 shrink-0",
          isPos ? "px-6 pt-6 pb-2" : "pb-2",
        )}>
          <div className="flex items-center gap-2.5 min-w-0">
            <Avatar className="size-9 shrink-0">
              <AvatarFallback className="text-xs font-medium">
                {isLoading ? "…" : initials(data?.name)}
              </AvatarFallback>
            </Avatar>
            <div className="flex flex-col min-w-0">
              <h2 className="text-sm font-medium leading-tight truncate">
                {isLoading ? <Skeleton className="h-4 w-40" /> : (data?.name || "Contacto")}
              </h2>
              {isLoading ? (
                <Skeleton className="h-3 w-56 mt-1" />
              ) : (
                <p className="text-xs text-muted-foreground truncate">
                  {[
                    data?.tin ? `RUC ${data.tin}` : null,
                    // formatPhone: la BD guarda E.164 sin '+' y el subtítulo
                    // lo pintaba crudo ("595991742353"). Esta vista la monta
                    // también el POS (customer-dialog.tsx), así que el cajero
                    // veía el número sin formato en la ficha del cliente.
                    formatPhone(data?.phone) || null,
                    data?.email ?? null,
                  ].filter(Boolean).join(" · ") || "Sin datos de contacto"}
                </p>
              )}
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            {!isPos && (
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button variant="ghost" size="icon" className="text-muted-foreground hover:text-destructive">
                    <Archive className="size-4" />
                  </Button>
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
            )}
            {tab === "data" && (
              <Button
                type="submit"
                size="sm"
                disabled={update.isPending || isLoading}
              >
                {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Guardar
              </Button>
            )}
            {isPos && onClose && (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={onClose}
                aria-label="Cerrar"
              >
                <X className="size-4" />
              </Button>
            )}
          </div>
        </header>

        {/* Nav + Contenido */}
        {nav === "tabs" ? (
          <Tabs value={tab} onValueChange={(v) => setTab(v as TabKey)} className={isPos ? "flex-1 overflow-hidden flex flex-col" : ""}>
            <div className="-mx-2 overflow-x-auto px-2">
              <TabsList className="w-fit min-w-full justify-start gap-1 sm:gap-0">
                {sections.map((s) => (
                  <TabsTrigger
                    key={s.key}
                    value={s.key}
                    className="gap-1.5"
                  >
                    {s.icon}
                    {s.label}
                  </TabsTrigger>
                ))}
              </TabsList>
            </div>

            {sections.map((s) => (
              <TabsContent key={s.key} value={s.key} className="mt-6">
                {tab === s.key && tabContent}
              </TabsContent>
            ))}
          </Tabs>
        ) : (
          /* nav="sidebar" — paritario con app/(panel)/settings/page.tsx */
          <div className="grid flex-1 min-h-0 grid-cols-[220px_1fr]">
            <nav
              aria-label="Secciones del cliente"
              className="flex shrink-0 flex-col gap-0.5 border-r bg-card p-3"
            >
              {sections.map((s) => (
                <button
                  key={s.key}
                  type="button"
                  onClick={() => setTab(s.key)}
                  className={cn(
                    "flex w-full shrink-0 items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm transition-colors",
                    tab === s.key
                      ? "bg-accent font-medium text-accent-foreground"
                      : "text-muted-foreground hover:bg-accent/50 hover:text-foreground",
                  )}
                  aria-current={tab === s.key ? "page" : undefined}
                >
                  {s.icon}
                  <span>{s.label}</span>
                </button>
              ))}
            </nav>
            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
              <header className="flex items-center gap-2 border-b py-3 pl-6 pr-4 text-sm">
                <span className="text-muted-foreground">Cliente</span>
                <span className="text-muted-foreground/50">›</span>
                <span className="text-foreground">{sections.find((s) => s.key === tab)?.label ?? ""}</span>
              </header>
              <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                {tabContent}
              </div>
            </div>
          </div>
        )}

        {/* Footer fijo — solo variant="pos" */}
        {isPos && onSelectForSale && (
          <div className="shrink-0 border-t px-6 py-4 flex justify-end">
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

function ContactFormBody({
  form,
  kind,
  country,
  setCountry,
  isPyTenant,
}: {
  form: UseFormReturn<ContactFormValues>
  kind: "persona" | "empresa"
  country: CountryCode
  setCountry: (c: CountryCode) => void
  /** País del TENANT (Bootstrap.country === "PY") — no confundir con `country`,
   *  que es el país del teléfono. Gate exclusivo de contactIdType. */
  isPyTenant: boolean
}) {
  const { data: priceLists } = usePriceLists()
  const isCreditable = form.watch("isCreditable")
  const idType = form.watch("idType")
  const ciCopy = ciFieldCopyForIdType(idType)

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
                  <FormDescription className="text-xs">
                    Nombre y apellido de quien atiende los contactos en la empresa.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
          </>
        )}

        {/* Tipo de documento — exclusivo de Paraguay (Tabla 3 SET). Gatea
            también el label/placeholder del campo de abajo (ciCopy). */}
        {/* Solo para PERSONA: una empresa es contribuyente por definición y se
            identifica con su RUC. `SaleToInvoiceMapper::buildClient()` ignora
            el idType en la rama 'contribuyente'. */}
        {isPyTenant && kind === "persona" && (
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
                    {CONTACT_ID_TYPES.map((t) => (
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
                <FormLabel>RUC</FormLabel>
                <div className="flex gap-2">
                  <FormControl>
                    <Input placeholder="Ej: 80012345-6" className="tabular-nums" {...field} />
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
                <FormLabel>{isPyTenant ? ciCopy.label : "CI"}</FormLabel>
                <FormControl>
                  <Input placeholder={isPyTenant ? ciCopy.placeholder : "Ej: 1234567"} className="tabular-nums" {...field} />
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
              <div>
                <FormLabel className="text-sm">Activo</FormLabel>
                <FormDescription className="text-xs">
                  Apagado = archivado, no aparece en búsquedas de la caja.
                </FormDescription>
              </div>
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
              <div>
                <FormLabel className="text-sm">Puede comprar a crédito</FormLabel>
                <FormDescription className="text-xs">
                  Habilita venta a crédito para este cliente en la caja.
                </FormDescription>
              </div>
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
              <FormDescription className="text-xs">
                Tope de saldo pendiente permitido para este cliente.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>
      </div>
    </div>
  )
}

// ── AddressesTab ─────────────────────────────────────────────────────────────

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

function AddressesTab({ contactId }: { contactId: string }) {
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

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          {addresses?.length
            ? `${addresses.length} dirección${addresses.length !== 1 ? "es" : ""} registrada${addresses.length !== 1 ? "s" : ""}`
            : "Sin direcciones registradas"}
        </p>
        <Button size="sm" variant="outline" onClick={openCreate}>
          + Nueva dirección
        </Button>
      </div>

      {addresses?.length === 0 && (
        <EmptyStateBlock icon={MapPin} title="Sin direcciones" description="Agregá una dirección de entrega para este cliente." />
      )}

      {addresses?.map((addr) => (
        <div key={addr.id} className="rounded-lg border bg-card px-3 py-2.5">
          <div className="flex items-start justify-between gap-3">
            <div className="flex flex-col gap-0.5">
              <div className="flex items-center gap-2">
                <span className="font-medium text-sm">{addr.name || "Sin nombre"}</span>
                {addr.default && <Badge variant="secondary" className="text-xs">Predeterminada</Badge>}
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
            <div className="flex items-center gap-1 shrink-0">
              {!addr.default && (
                <Button variant="ghost" size="sm" className="text-xs text-muted-foreground"
                  onClick={() => handleSetDefault(addr)} disabled={setDefault.isPending}>
                  Predeterminar
                </Button>
              )}
              <Button variant="ghost" size="icon" className="size-8" onClick={() => openEdit(addr)}>
                <Pencil className="size-4" />
              </Button>
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button variant="ghost" size="icon" className="size-8 text-muted-foreground hover:text-destructive">
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
        </div>
      ))}

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
    return (
      <EmptyStateBlock icon={Layers} title="Sin packs activos"
        description="Cuando este cliente compre un pack de servicios aparecerá aquí con el saldo disponible." />
    )
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
          <CardTitle className="text-base">{pack.packName}</CardTitle>
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

function SummaryTab({
  contact,
  analytics,
  isLoading,
  bootstrap,
}: {
  contact: ContactFull | undefined
  analytics: ContactAnalytics | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const totals = analytics?.totals
  const visits = analytics?.visits
  const segment = analytics?.segment
  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 rounded-lg border bg-card px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[11px] uppercase tracking-wide text-muted-foreground">Segmento</span>
          {isLoading ? <Skeleton className="h-5 w-20" /> : (
            <Badge variant={segmentVariant(segment?.key)}>{segment?.label ?? "—"}</Badge>
          )}
          {contact?.date && (
            <span className="ml-1 text-xs text-muted-foreground">Cliente desde {formatDate(contact.date ?? "")}</span>
          )}
        </div>
        <div className="flex items-baseline gap-1.5 text-xs">
          <span className="text-muted-foreground">Última visita</span>
          {isLoading ? <Skeleton className="h-4 w-24" /> : (
            <span className="font-medium text-foreground">
              {lastVisitLabel(visits?.lastAt, visits?.daysSinceLast)}
            </span>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <KpiCard label="Total gastado" value={isLoading ? null : formatMoney(totals?.spent, bootstrap)} />
        <KpiCard label="Compras" value={isLoading ? null : formatInt(totals?.purchases, bootstrap)} />
        <KpiCard label="Artículos" value={isLoading ? null : formatInt(totals?.itemsBought, bootstrap)} />
        <KpiCard label="Ticket promedio" value={isLoading ? null : formatMoney(totals?.avgTicket, bootstrap)} />
      </div>

      <div className="flex flex-col gap-2 rounded-lg border bg-card px-3 py-2.5">
        <div className="text-[11px] uppercase tracking-wide text-muted-foreground">Actividad</div>
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
          <DetailRow label="Primera operación" value={isLoading ? null : formatDate(visits?.firstAt ?? "")} />
          <DetailRow label="Frecuencia promedio" value={isLoading ? null : freqLabel(visits?.avgDaysBetween ?? null)} />
          <DetailRow label="Descuento acumulado" value={isLoading ? null : formatMoney(totals?.discountTotal, bootstrap)} />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
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
  const monthSeries = analytics?.byMonth ?? []
  const hourSeries  = analytics?.byHour ?? []
  const dowSeries   = analytics?.byDayOfWeek ?? []
  const paymentMix  = analytics?.paymentMix ?? []
  const outlets     = analytics?.byOutlet ?? []
  return (
    <div className="flex flex-col gap-4">
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">Compras por mes (12 meses)</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? <Skeleton className="h-[220px] w-full" /> :
            monthSeries.length === 0 ? <EmptyState label="Sin operaciones registradas." /> : (
              <ResponsiveContainer width="100%" height={220}>
                <LineChart data={monthSeries} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                  <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="month" tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} />
                  <YAxis tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} tickLine={false} axisLine={false} width={36} tickFormatter={(v: number) => compactNum(v)} />
                  <Tooltip cursor={{ stroke: "var(--accent)", strokeWidth: 1 }}
                    contentStyle={{ background: "var(--popover)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }}
                    formatter={(v) => formatMoney(Number(v), bootstrap)} />
                  <Line type="monotone" dataKey="total" stroke="var(--chart-1)" strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
                </LineChart>
              </ResponsiveContainer>
            )}
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold tracking-tight">Horarios preferidos</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-[180px] w-full" /> :
              hourSeries.length === 0 ? <EmptyState label="Sin datos de horario." /> : (
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
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold tracking-tight">Día de la semana</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-[180px] w-full" /> :
              dowSeries.length === 0 ? <EmptyState label="Sin datos por día." /> : (
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
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold tracking-tight">Forma de pago</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-[200px] w-full" /> :
              paymentMix.length === 0 ? <EmptyState label="Sin operaciones." /> : (
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
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-sm font-medium">
              <MapPin className="size-4 text-muted-foreground" />
              Sucursales preferidas
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-32 w-full" /> :
              outlets.length === 0 ? <EmptyState label="Sin datos por sucursal." /> : (
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

function FinancialTab({
  customerId,
  contactName,
  analytics,
  isLoading,
  bootstrap,
  variant,
}: {
  customerId: string
  contactName: string
  analytics: ContactAnalytics | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
  /** "panel" | "pos" — pasado tal cual a `AccountStatementSection` para que
   *  el click de fila no navegue fuera del POS. Ver docblock del archivo. */
  variant: "panel" | "pos"
}) {
  const f = analytics?.financial
  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <KpiCard label="Crédito a favor" value={isLoading ? null : formatMoney(f?.storeCredit, bootstrap)} />
        <KpiCard label="Loyalty acumulado" value={isLoading ? null : formatMoney(f?.loyalty, bootstrap)} />
        <KpiCard label="Línea de crédito" value={isLoading ? null : formatMoney(f?.creditLine, bootstrap)} />
        <KpiCard label="Cuentas por cobrar" value={isLoading ? null : formatMoney(f?.openInvoices, bootstrap)} />
      </div>
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">Estado de crédito</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? <Skeleton className="h-5 w-40" /> : (
            <div className="flex items-center gap-2 text-sm">
              <Badge variant={f?.isCreditable ? "default" : "secondary"}>
                {f?.isCreditable ? "Habilitado para crédito" : "Sin crédito habilitado"}
              </Badge>
              <span className="text-xs text-muted-foreground">Configurable desde el form de Datos.</span>
            </div>
          )}
        </CardContent>
      </Card>
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
// `KpiCard` y `AccountStatementSection` viven en archivos propios
// (components/domain/contacts/kpi-card.tsx, account-statement-section.tsx) —
// se extrajeron para reusarlos tal cual desde el detalle por contacto del
// reporte de cuentas por cobrar/pagar (`/reports/open-invoices`).

function DetailRow({
  label, value,
}: {
  label: string; value: React.ReactNode
}) {
  return (
    <div className="flex items-start gap-2 text-sm">
      <div className="flex flex-col">
        <span className="text-xs text-muted-foreground">{label}</span>
        {value === null ? <Skeleton className="h-4 w-20" /> : <span className="font-medium">{value}</span>}
      </div>
    </div>
  )
}

function TopItemsCard({
  items, isLoading, bootstrap,
}: {
  items: ContactAnalytics["topItems"]; isLoading: boolean; bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <div className="flex flex-col gap-2 rounded-lg border bg-card px-3 py-2.5">
      <div className="text-[11px] uppercase tracking-wide text-muted-foreground">Productos preferidos</div>
      {isLoading ? (
        <div className="flex flex-col gap-2">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-5 w-full" />)}</div>
      ) : items.length === 0 ? <EmptyState label="Sin compras registradas." /> : (
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
    </div>
  )
}

function TopCategoriesCard({
  items, isLoading, bootstrap,
}: {
  items: ContactAnalytics["topCategories"]; isLoading: boolean; bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <div className="flex flex-col gap-2 rounded-lg border bg-card px-3 py-2.5">
      <div className="text-[11px] uppercase tracking-wide text-muted-foreground">Categorías favoritas</div>
      {isLoading ? (
        <div className="flex flex-col gap-2">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-5 w-full" />)}</div>
      ) : items.length === 0 ? <EmptyState label="Sin categorías registradas." /> : (
        <div className="flex flex-col divide-y divide-border">
          {items.map((c) => (
            <div key={c.taxonomyId} className="flex items-center justify-between gap-2 py-1.5 text-sm first:pt-0 last:pb-0">
              <span className="truncate">{c.name}</span>
              <span className="text-xs text-muted-foreground tabular-nums">{formatMoney(c.total, bootstrap)}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function EmptyState({ label }: { label: string }) {
  return (
    <div className="flex min-h-32 items-center justify-center">
      <EmptyStateBlock icon={Inbox} title={label} showMarquee={false} className="border-0 p-3" />
    </div>
  )
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

function emptyValues(): ContactFormValues {
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
