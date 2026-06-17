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
  ShoppingBag, Layers,
  CalendarDays, MapPin, Sparkles, Inbox, X,
  ClipboardList as OrdersIcon,
} from "lucide-react"
import { EmptyState as EmptyStateBlock } from "@/components/empty-state"
import { toast } from "sonner"
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
} from "@/hooks/use-contacts"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePriceLists } from "@/hooks/use-price-lists"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import { formatInt, formatMoney } from "@/lib/format"
import { cn } from "@/lib/utils"
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
import { ContactOrdersCompact } from "@/components/domain/contacts/contact-orders-compact"
import { ContactScheduleCompact } from "@/components/domain/contacts/contact-schedule-compact"

// ── Zod schema (igual que el de la page original) ────────────────────────────

const contactSchema = z
  .object({
    kind: z.enum(["persona", "empresa"]),
    name: z.string(),
    fiscalName: z.string(),
    tin: z.string(),
    ci: z.string(),
    bday: z.string(),
    phone: z.string().nullable(),
    email: z.union([z.string().email("Email inválido"), z.literal("")]),
    note: z.string(),
    status: z.boolean(),
    priceListId: z.string().nullable(),
  })
  .refine(
    (v) => (v.kind === "persona" ? v.name.trim() !== "" : v.fiscalName.trim() !== ""),
    { message: "El nombre es requerido", path: ["name"] },
  )

// ── Types ─────────────────────────────────────────────────────────────────────

type TabKey = "summary" | "behavior" | "financial" | "data" | "addresses" | "packs" | "orders" | "schedule"

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
      bday: data.bday ?? "",
      phone: data.phone ?? null,
      email: data.email ?? "",
      note: data.note ?? "",
      status: (data.status ?? 1) === 1,
      priceListId: data.priceListId ?? null,
    })
  }, [data, form])

  const [tab, setTab] = React.useState<TabKey>("summary")

  const analytics = useContactAnalytics(
    tab !== "data" ? customerId : undefined,
    1,
  )
  const { data: bootstrap } = useBootstrap()

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

  // Secciones para la navegación (tabs o sidebar)
  const sections: { key: TabKey; label: string; icon: React.ReactNode }[] = [
    { key: "summary",  label: "Resumen",      icon: <BarChart3 className="size-3.5" /> },
    { key: "behavior", label: "Comportamiento", icon: <Sparkles className="size-3.5" /> },
    { key: "financial",label: "Financiero",    icon: <Wallet className="size-3.5" /> },
    { key: "packs",    label: "Packs",         icon: <Layers className="size-3.5" /> },
    { key: "addresses",label: "Direcciones",   icon: <MapPin className="size-3.5" /> },
    { key: "orders",   label: "Órdenes",       icon: <OrdersIcon className="size-3.5" /> },
    { key: "schedule", label: "Agenda",        icon: <CalendarDays className="size-3.5" /> },
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
          analytics={analytics.data}
          isLoading={analytics.isLoading}
          bootstrap={bootstrap}
        />
      )}
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
          isPos ? "px-4 pt-4 pb-2" : "pb-2",
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
                    data?.phone ?? null,
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
                    <AlertDialogAction onClick={onArchive} disabled={archive.isPending}>
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
            <TabsList className="flex h-auto w-full flex-wrap gap-1 justify-start bg-transparent p-0 shrink-0">
              {sections.map((s) => (
                <TabsTrigger
                  key={s.key}
                  value={s.key}
                  className="gap-1.5 rounded-md border border-transparent data-[state=active]:border-border data-[state=active]:bg-background data-[state=active]:shadow-sm"
                >
                  {s.icon}
                  {s.label}
                </TabsTrigger>
              ))}
            </TabsList>

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
          <div className="shrink-0 border-t p-4 flex justify-end">
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
}: {
  form: UseFormReturn<ContactFormValues>
  kind: "persona" | "empresa"
  country: CountryCode
  setCountry: (c: CountryCode) => void
}) {
  const { data: priceLists } = usePriceLists()
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      {/* Identificación */}
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

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField
            control={form.control}
            name="tin"
            render={({ field }) => (
              <FormItem>
                <FormLabel>RUC</FormLabel>
                <FormControl>
                  <Input placeholder="Ej: 80012345-6" className="tabular-nums" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="ci"
            render={({ field }) => (
              <FormItem>
                <FormLabel>CI</FormLabel>
                <FormControl>
                  <Input placeholder="Ej: 1234567" className="tabular-nums" {...field} />
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
                  <SelectTrigger className="w-full">
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
      </Section>

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
                    field.onChange(v.e164)
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
    </div>
  )
}

// ── AddressesTab ─────────────────────────────────────────────────────────────

type AddressFormState = {
  name: string
  address: string
  location: string
  city: string
  lat: number | null
  lng: number | null
}

const emptyAddressForm = (): AddressFormState => ({
  name: "", address: "", location: "", city: "", lat: null, lng: null,
})

function AddressesTab({ contactId }: { contactId: string }) {
  const { data: addresses, isLoading } = useCustomerAddresses(contactId)
  const addAddress = useAddAddress()
  const updateAddress = useUpdateAddress()
  const setDefault = useSetDefaultAddress()
  const deleteAddress = useDeleteAddress()

  const [showForm, setShowForm] = React.useState(false)
  const [editing, setEditing] = React.useState<string | null>(null)
  const [form, setForm] = React.useState<AddressFormState>(emptyAddressForm())

  const serializeForm = (f: AddressFormState) => ({
    name: f.name,
    address: f.address,
    location: f.location,
    city: f.city,
    latLng: f.lat !== null && f.lng !== null ? `${f.lat},${f.lng}` : "",
  })

  const handleAdd = async () => {
    try {
      await addAddress.mutateAsync({ customerId: contactId, ...serializeForm(form) })
      toast.success("Dirección agregada")
      setShowForm(false)
      setForm(emptyAddressForm())
    } catch (e) {
      toast.error("No se pudo agregar", { description: e instanceof Error ? e.message : undefined })
    }
  }

  const handleUpdate = async (addr: CustomerAddress) => {
    try {
      await updateAddress.mutateAsync({ addressId: addr.id, customerId: contactId, ...serializeForm(form) })
      toast.success("Dirección actualizada")
      setEditing(null)
    } catch (e) {
      toast.error("No se pudo actualizar", { description: e instanceof Error ? e.message : undefined })
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
        {!showForm && (
          <Button size="sm" variant="outline" onClick={() => { setShowForm(true); setForm(emptyAddressForm()) }}>
            + Nueva dirección
          </Button>
        )}
      </div>

      {showForm && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Nueva dirección</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <AddressFormFields form={form} onChange={setForm} />
            <div className="flex gap-2 justify-end">
              <Button variant="ghost" size="sm" onClick={() => setShowForm(false)}>Cancelar</Button>
              <Button size="sm" onClick={handleAdd} disabled={addAddress.isPending}>
                {addAddress.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Guardar
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {addresses?.length === 0 && !showForm && (
        <EmptyStateBlock icon={MapPin} title="Sin direcciones" description="Agregá una dirección de entrega para este cliente." />
      )}

      {addresses?.map((addr) => (
        <Card key={addr.id}>
          <CardContent className="p-4">
            {editing === addr.id ? (
              <div className="flex flex-col gap-3">
                <AddressFormFields form={form} onChange={setForm} />
                <div className="flex gap-2 justify-end">
                  <Button variant="ghost" size="sm" onClick={() => setEditing(null)}>Cancelar</Button>
                  <Button size="sm" onClick={() => handleUpdate(addr)} disabled={updateAddress.isPending}>
                    {updateAddress.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                    Guardar
                  </Button>
                </div>
              </div>
            ) : (
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
                </div>
                <div className="flex items-center gap-1 shrink-0">
                  {!addr.default && (
                    <Button variant="ghost" size="sm" className="text-xs text-muted-foreground"
                      onClick={() => handleSetDefault(addr)} disabled={setDefault.isPending}>
                      Predeterminar
                    </Button>
                  )}
                  <Button variant="ghost" size="icon" className="size-8"
                    onClick={() => {
                      setEditing(addr.id)
                      setForm({
                        name: addr.name, address: addr.address, location: addr.location,
                        city: addr.city,
                        lat: addr.lat !== null ? Number(addr.lat) : null,
                        lng: addr.lng !== null ? Number(addr.lng) : null,
                      })
                    }}>
                    <svg xmlns="http://www.w3.org/2000/svg" className="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </Button>
                  <AlertDialog>
                    <AlertDialogTrigger asChild>
                      <Button variant="ghost" size="icon" className="size-8 text-muted-foreground hover:text-destructive">
                        <svg xmlns="http://www.w3.org/2000/svg" className="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                      </Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                      <AlertDialogHeader>
                        <AlertDialogTitle>¿Eliminar esta dirección?</AlertDialogTitle>
                        <AlertDialogDescription>
                          Se eliminará &quot;{addr.name || addr.address}&quot; permanentemente.
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
            )}
          </CardContent>
        </Card>
      ))}
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
          <label className="text-sm font-medium">Nombre / etiqueta</label>
          <Input placeholder="Casa, Trabajo, Depósito..."
            value={form.name} onChange={(e) => onChange({ ...form, name: e.target.value })} />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Ciudad</label>
          <Input placeholder="Asunción"
            value={form.city} onChange={(e) => onChange({ ...form, city: e.target.value })} />
        </div>
      </div>
      <div className="flex flex-col gap-1.5">
        <label className="text-sm font-medium">Dirección</label>
        <Input placeholder="Calle y número"
          value={form.address} onChange={(e) => onChange({ ...form, address: e.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <label className="text-sm font-medium">Barrio / zona</label>
        <Input placeholder="Carmelitas, San Lorenzo..."
          value={form.location} onChange={(e) => onChange({ ...form, location: e.target.value })} />
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Latitud</label>
          <Input type="number" inputMode="decimal" step="0.0000001" placeholder="-25.2867"
            value={form.lat ?? ""} className="tabular-nums"
            onChange={(e) => onChange({ ...form, lat: e.target.value === "" ? null : Number(e.target.value) })} />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Longitud</label>
          <Input type="number" inputMode="decimal" step="0.0000001" placeholder="-57.6478"
            value={form.lng ?? ""} className="tabular-nums"
            onChange={(e) => onChange({ ...form, lng: e.target.value === "" ? null : Number(e.target.value) })} />
        </div>
      </div>
      <AddressMapParser
        onParsed={(lat, lng) => {
          onChange({ ...form, lat, lng })
          toast.success(`Coordenadas: ${lat}, ${lng}`)
        }}
      />
    </>
  )
}

function AddressMapParser({ onParsed }: { onParsed: (lat: number, lng: number) => void }) {
  const [text, setText] = React.useState("")
  const parse = () => {
    if (!text.trim()) return
    const patterns = [
      /@(-?\d+\.\d+),(-?\d+\.\d+)/,
      /q=(-?\d+\.\d+),(-?\d+\.\d+)/,
      /^(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)$/,
    ]
    for (const re of patterns) {
      const m = text.match(re)
      if (m) {
        const lat = Number(m[1])
        const lng = Number(m[2])
        if (Number.isFinite(lat) && lat >= -90 && lat <= 90 && Number.isFinite(lng) && lng >= -180 && lng <= 180) {
          onParsed(lat, lng)
          setText("")
          return
        }
      }
    }
    toast.error("No pude extraer coordenadas", {
      description: "Pegá un link largo de Google Maps o el texto 'lat,lng'.",
    })
  }
  return (
    <div className="flex flex-col gap-2 rounded-md border border-dashed p-3">
      <label className="text-xs text-muted-foreground">
        Pegar link de Google Maps o &quot;lat,lng&quot;
      </label>
      <div className="flex gap-2">
        <Input value={text} onChange={(e) => setText(e.target.value)}
          placeholder="https://www.google.com/maps/@-25.28,-57.64,17z" className="text-xs" />
        <Button type="button" variant="outline" size="sm" onClick={parse}>Extraer</Button>
      </div>
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
            ? `Vence ${niceDate(pack.expiresAt)} (${daysLeft} día${daysLeft !== 1 ? "s" : ""})`
            : `Venció ${niceDate(pack.expiresAt)}`}
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
    <div className="flex flex-col gap-4">
      <Card>
        <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs uppercase tracking-wide text-muted-foreground">Segmento</span>
            {isLoading ? <Skeleton className="h-5 w-20" /> : (
              <Badge variant={segmentVariant(segment?.key)}>{segment?.label ?? "—"}</Badge>
            )}
            {contact?.date && (
              <span className="ml-2 text-xs text-muted-foreground">Cliente desde {niceDate(contact.date)}</span>
            )}
          </div>
          <div className="flex flex-col items-end text-xs text-muted-foreground">
            <span>Última visita</span>
            {isLoading ? <Skeleton className="h-4 w-24" /> : (
              <span className="font-medium text-foreground">
                {lastVisitLabel(visits?.lastAt, visits?.daysSinceLast)}
              </span>
            )}
          </div>
        </CardContent>
      </Card>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard label="Total gastado" value={isLoading ? null : formatMoney(totals?.spent, bootstrap)} hint={bootstrap?.currency} />
        <KpiCard label="Compras" value={isLoading ? null : formatInt(totals?.purchases, bootstrap)} />
        <KpiCard label="Artículos" value={isLoading ? null : formatInt(totals?.itemsBought, bootstrap)} />
        <KpiCard label="Ticket promedio" value={isLoading ? null : formatMoney(totals?.avgTicket, bootstrap)} hint={bootstrap?.currency} />
      </div>

      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">Actividad</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <DetailRow label="Primera operación" value={isLoading ? null : niceDate(visits?.firstAt ?? null)} />
          <DetailRow label="Frecuencia promedio" value={isLoading ? null : freqLabel(visits?.avgDaysBetween ?? null)} />
          <DetailRow label="Descuento acumulado" value={isLoading ? null : formatMoney(totals?.discountTotal, bootstrap)} />
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
  analytics,
  isLoading,
  bootstrap,
}: {
  analytics: ContactAnalytics | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const f = analytics?.financial
  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard label="Crédito a favor" value={isLoading ? null : formatMoney(f?.storeCredit, bootstrap)} hint={bootstrap?.currency} />
        <KpiCard label="Loyalty acumulado" value={isLoading ? null : formatMoney(f?.loyalty, bootstrap)} hint={bootstrap?.currency} />
        <KpiCard label="Línea de crédito" value={isLoading ? null : formatMoney(f?.creditLine, bootstrap)} hint={bootstrap?.currency} />
        <KpiCard label="Cuentas por cobrar" value={isLoading ? null : formatMoney(f?.openInvoices, bootstrap)} hint={bootstrap?.currency} />
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
    </div>
  )
}

// ── Helpers compartidos ───────────────────────────────────────────────────────

function KpiCard({
  label, value, hint,
}: {
  label: string; value: React.ReactNode; hint?: string
}) {
  return (
    <Card>
      <CardContent className="flex flex-col gap-1 p-4">
        <div className="text-xs uppercase tracking-wide text-muted-foreground">
          {label}
        </div>
        {value === null ? <Skeleton className="h-7 w-24" /> : (
          <div className="flex items-baseline gap-1.5">
            {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
            <span className="text-xl font-semibold tabular-nums">{value}</span>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

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
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <ShoppingBag className="size-4 text-muted-foreground" /> Productos preferidos
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-6 w-full" />)}</div>
        ) : items.length === 0 ? <EmptyState label="Sin compras registradas." /> : (
          <div className="flex flex-col divide-y divide-border">
            {items.map((it) => (
              <div key={it.itemId} className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0">
                <span className="truncate">{it.name}</span>
                <span className="text-xs text-muted-foreground tabular-nums">
                  {Math.round(it.count)} ud · {formatMoney(it.total, bootstrap)}
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
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <Layers className="size-4 text-muted-foreground" /> Categorías favoritas
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-6 w-full" />)}</div>
        ) : items.length === 0 ? <EmptyState label="Sin categorías registradas." /> : (
          <div className="flex flex-col divide-y divide-border">
            {items.map((c) => (
              <div key={c.taxonomyId} className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0">
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

function niceDate(iso: string | null): string {
  if (!iso) return "—"
  const d = new Date(iso)
  if (isNaN(d.getTime())) return "—"
  return d.toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" })
}

function lastVisitLabel(iso: string | null | undefined, daysSince: number | null | undefined): string {
  if (!iso) return "Sin operaciones"
  if (daysSince === null || daysSince === undefined) return niceDate(iso)
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
    bday: "",
    phone: null,
    email: "",
    note: "",
    status: true,
    priceListId: null,
  }
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <FormSection title={title}>{children}</FormSection>
}
