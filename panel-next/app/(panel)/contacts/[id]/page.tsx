"use client"

import * as React from "react"
import { useParams, useRouter, useSearchParams } from "next/navigation"
import Link from "next/link"
import { useForm, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import {
  ArrowLeft, Loader2, Archive, User2, BarChart3, Wallet,
  ClipboardList, ShoppingBag, Layers, TrendingUp,
  CalendarDays, MapPin, Sparkles, Inbox,
} from "lucide-react"
import { EmptyState as EmptyStateBlock } from "@/components/empty-state"
import { toast } from "sonner"
import type { CountryCode } from "libphonenumber-js"
import {
  Bar, BarChart, CartesianGrid, Cell, Line, LineChart,
  Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts"

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
import { PhoneInput } from "@/components/forms/phone-input"
import {
  useArchiveContact,
  useContact,
  useContactAnalytics,
  useContactPacks,
  useCreateContact,
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
import type { ContactAnalytics, ContactFormValues, ContactFull, CustomerAddress, SoldPack } from "@/lib/types/contact"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

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
    {
      message: "El nombre es requerido",
      path: ["name"], // se muestra bajo el campo nombre/razón
    },
  )

export default function ContactEditPage() {
  const params = useParams<{ id: string }>()
  const id = params.id
  const isNew = id === "new"
  const router = useRouter()
  const searchParams = useSearchParams()
  // `?type=2` = proveedor (default 1 = cliente). Solo aplica al crear; al
  // editar el type ya está fijado en el row y no se cambia.
  const contactType = searchParams.get("type") === "2" ? 2 : 1
  const isSupplier = contactType === 2
  const { data, isLoading, error } = useContact(isNew ? undefined : id)
  const create = useCreateContact()
  const update = useUpdateContact()
  const archive = useArchiveContact()
  const [country, setCountry] = React.useState<CountryCode>(DEFAULT_COUNTRY)

  const form = useForm<ContactFormValues>({
    resolver: zodResolver(contactSchema),
    defaultValues: emptyValues(),
  })

  React.useEffect(() => {
    if (isNew || !data) return
    // Heurística: si tiene fullname (= contactSecondName), data.name es razón
    // social → kind=empresa; sino kind=persona.
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
  }, [data, form, isNew])

  const onSubmit = async (values: ContactFormValues) => {
    try {
      if (isNew) {
        const created = await create.mutateAsync({ values, type: contactType as 1 | 2 })
        toast.success(contactType === 2 ? "Proveedor creado" : "Cliente creado")
        router.push(`/contacts/${created.id}`)
      } else {
        await update.mutateAsync({ id, values })
        toast.success("Contacto actualizado")
      }
    } catch (e) {
      toast.error(isNew ? "No se pudo crear" : "No se pudo guardar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const onArchive = async () => {
    try {
      await archive.mutateAsync(id)
      toast.success("Contacto archivado")
      router.push("/contacts")
    } catch (e) {
      toast.error("No se pudo archivar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <Card>
          <CardContent className="p-8 text-center text-sm text-muted-foreground">
            No se pudo cargar el contacto. {error.message}
          </CardContent>
        </Card>
      </div>
    )
  }

  const kind = form.watch("kind")
  const [tab, setTab] = React.useState<"summary" | "behavior" | "financial" | "data" | "addresses" | "packs">(
    isNew ? "data" : "summary",
  )
  // Solo pedimos analytics cuando el contacto existe y el tenant lo abrió en
  // un tab que las consume. Caching por TanStack Query maneja el resto.
  const analytics = useContactAnalytics(
    !isNew && tab !== "data" ? id : undefined,
    isSupplier ? 2 : 1,
  )
  const { data: bootstrap } = useBootstrap()

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
        <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div className="flex flex-col gap-1">
            <BackLink />
            <h1 className="text-2xl font-semibold">
              {isNew ? "Nuevo contacto" : isLoading ? (
                <Skeleton className="h-7 w-48" />
              ) : (
                data?.name || "Contacto"
              )}
            </h1>
          </div>
          <div className="flex items-center gap-2">
            {!isNew && (
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
            {/* Solo mostramos Guardar en el tab "Datos" (es el único que escribe
                al form). En los demás tabs el submit no aplica — esconderlo
                evita "guardar accidental" cuando el user está mirando KPIs. */}
            {tab === "data" && (
              <Button
                type="submit"
                disabled={(isNew ? create.isPending : update.isPending) || (isLoading && !isNew)}
              >
                {(isNew ? create.isPending : update.isPending) && (
                  <Loader2 className="mr-2 size-4 animate-spin" />
                )}
                {isNew ? "Crear contacto" : "Guardar"}
              </Button>
            )}
          </div>
        </header>

        {/* Tabs solo cuando estamos editando un contacto existente. Para "nuevo"
            no hay analytics que mostrar — el form se renderiza directo. */}
        {isNew ? (
          <ContactFormBody form={form} kind={kind} country={country} setCountry={setCountry} />
        ) : (
          <Tabs value={tab} onValueChange={(v) => setTab(v as typeof tab)}>
            <TabsList className="flex h-auto w-full flex-wrap gap-1 justify-start bg-transparent p-0">
              <TabsTrigger value="summary" className="gap-1.5 rounded-md border border-transparent data-[state=active]:border-border data-[state=active]:bg-background data-[state=active]:shadow-sm">
                <BarChart3 className="size-3.5" />
                Resumen
              </TabsTrigger>
              <TabsTrigger value="behavior" className="gap-1.5 rounded-md border border-transparent data-[state=active]:border-border data-[state=active]:bg-background data-[state=active]:shadow-sm">
                <Sparkles className="size-3.5" />
                Comportamiento
              </TabsTrigger>
              <TabsTrigger value="financial" className="gap-1.5 rounded-md border border-transparent data-[state=active]:border-border data-[state=active]:bg-background data-[state=active]:shadow-sm">
                <Wallet className="size-3.5" />
                Financiero
              </TabsTrigger>
              <TabsTrigger value="packs" className="gap-1.5 rounded-md border border-transparent data-[state=active]:border-border data-[state=active]:bg-background data-[state=active]:shadow-sm">
                <Layers className="size-3.5" />
                Packs
              </TabsTrigger>
              <TabsTrigger value="addresses" className="gap-1.5 rounded-md border border-transparent data-[state=active]:border-border data-[state=active]:bg-background data-[state=active]:shadow-sm">
                <MapPin className="size-3.5" />
                Direcciones
              </TabsTrigger>
              <TabsTrigger value="data" className="gap-1.5 rounded-md border border-transparent data-[state=active]:border-border data-[state=active]:bg-background data-[state=active]:shadow-sm">
                <User2 className="size-3.5" />
                Datos
              </TabsTrigger>
            </TabsList>

            <TabsContent value="summary" className="mt-6">
              <SummaryTab
                contact={data}
                analytics={analytics.data}
                isLoading={analytics.isLoading || isLoading}
                bootstrap={bootstrap}
              />
            </TabsContent>
            <TabsContent value="behavior" className="mt-6">
              <BehaviorTab
                analytics={analytics.data}
                isLoading={analytics.isLoading}
                bootstrap={bootstrap}
              />
            </TabsContent>
            <TabsContent value="financial" className="mt-6">
              <FinancialTab
                analytics={analytics.data}
                isLoading={analytics.isLoading}
                bootstrap={bootstrap}
              />
            </TabsContent>
            <TabsContent value="packs" className="mt-6">
              <PacksTab contactId={id} />
            </TabsContent>
            <TabsContent value="addresses" className="mt-6">
              <AddressesTab contactId={id} />
            </TabsContent>
            <TabsContent value="data" className="mt-6">
              <ContactFormBody form={form} kind={kind} country={country} setCountry={setCountry} />
            </TabsContent>
          </Tabs>
        )}
      </form>
    </Form>
  )
}

/**
 * El form de edición (Identificación + Contacto + Dirección) — extraído del
 * return principal para reusarlo entre el modo "nuevo" (sin tabs) y el tab
 * "Datos" del modo edición.
 */
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
                      <Input
                        placeholder="Ej: 80012345-6"
                        className="tabular-nums"
                        {...field}
                      />
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
                      <Input
                        placeholder="Ej: 1234567"
                        className="tabular-nums"
                        {...field}
                      />
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
                    <DatePicker
                      value={field.value ?? ""}
                      onChange={field.onChange}
                    />
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
                    value={field.value ?? ""}
                    onValueChange={(v) => field.onChange(v === "" ? null : v)}
                  >
                    <FormControl>
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder="Precio base (sin lista)" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="">Precio base (sin lista)</SelectItem>
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
                    <Textarea
                      rows={3}
                      placeholder="Observaciones internas"
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </Section>

        </div>
  )
}

// ── TAB: Direcciones ────────────────────────────────────────────────────────

type AddressFormState = {
  name: string
  address: string
  location: string
  city: string
  lat: number | null
  lng: number | null
}

const emptyAddressForm = (): AddressFormState => ({ name: "", address: "", location: "", city: "", lat: null, lng: null })

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

      {/* Formulario nueva dirección */}
      {showForm && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Nueva dirección</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <AddressFormFields form={form} onChange={setForm} />
            <div className="flex gap-2 justify-end">
              <Button variant="ghost" size="sm" onClick={() => setShowForm(false)}>
                Cancelar
              </Button>
              <Button size="sm" onClick={handleAdd} disabled={addAddress.isPending}>
                {addAddress.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Guardar
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Lista de direcciones */}
      {addresses?.length === 0 && !showForm && (
        <EmptyStateBlock
          icon={MapPin}
          title="Sin direcciones"
          description="Agregá una dirección de entrega para este cliente."
        />
      )}

      {addresses?.map((addr) => (
        <Card key={addr.id}>
          <CardContent className="p-4">
            {editing === addr.id ? (
              <div className="flex flex-col gap-3">
                <AddressFormFields form={form} onChange={setForm} />
                <div className="flex gap-2 justify-end">
                  <Button variant="ghost" size="sm" onClick={() => setEditing(null)}>
                    Cancelar
                  </Button>
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
                    {addr.default && (
                      <Badge variant="secondary" className="text-xs">Predeterminada</Badge>
                    )}
                  </div>
                  {addr.address && (
                    <span className="text-sm text-muted-foreground">{addr.address}</span>
                  )}
                  {(addr.location || addr.city) && (
                    <span className="text-xs text-muted-foreground">
                      {[addr.location, addr.city].filter(Boolean).join(", ")}
                    </span>
                  )}
                </div>
                <div className="flex items-center gap-1 shrink-0">
                  {!addr.default && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="text-xs text-muted-foreground"
                      onClick={() => handleSetDefault(addr)}
                      disabled={setDefault.isPending}
                    >
                      Predeterminar
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    onClick={() => {
                      setEditing(addr.id)
                      setForm({
                        name: addr.name,
                        address: addr.address,
                        location: addr.location,
                        city: addr.city,
                        lat: addr.lat !== null ? Number(addr.lat) : null,
                        lng: addr.lng !== null ? Number(addr.lng) : null,
                      })
                    }}
                  >
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
                          onClick={() => handleDelete(addr)}
                          disabled={deleteAddress.isPending}
                          className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
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
          <Input
            placeholder="Casa, Trabajo, Depósito..."
            value={form.name}
            onChange={(e) => onChange({ ...form, name: e.target.value })}
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Ciudad</label>
          <Input
            placeholder="Asunción"
            value={form.city}
            onChange={(e) => onChange({ ...form, city: e.target.value })}
          />
        </div>
      </div>
      <div className="flex flex-col gap-1.5">
        <label className="text-sm font-medium">Dirección</label>
        <Input
          placeholder="Calle y número"
          value={form.address}
          onChange={(e) => onChange({ ...form, address: e.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <label className="text-sm font-medium">Barrio / zona</label>
        <Input
          placeholder="Carmelitas, San Lorenzo..."
          value={form.location}
          onChange={(e) => onChange({ ...form, location: e.target.value })}
        />
      </div>

      {/* Coordenadas */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Latitud</label>
          <Input
            type="number"
            inputMode="decimal"
            step="0.0000001"
            placeholder="-25.2867"
            value={form.lat ?? ""}
            onChange={(e) => onChange({ ...form, lat: e.target.value === "" ? null : Number(e.target.value) })}
            className="tabular-nums"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Longitud</label>
          <Input
            type="number"
            inputMode="decimal"
            step="0.0000001"
            placeholder="-57.6478"
            value={form.lng ?? ""}
            onChange={(e) => onChange({ ...form, lng: e.target.value === "" ? null : Number(e.target.value) })}
            className="tabular-nums"
          />
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
        <Input
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="https://www.google.com/maps/@-25.28,-57.64,17z"
          className="text-xs"
        />
        <Button type="button" variant="outline" size="sm" onClick={parse}>
          Extraer
        </Button>
      </div>
    </div>
  )
}

// ── TAB: Packs ──────────────────────────────────────────────────────────────

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
      <EmptyStateBlock
        icon={Layers}
        title="Sin packs activos"
        description="Cuando este cliente compre un pack de servicios aparecerá aquí con el saldo disponible."
      />
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

// ── TAB: Resumen ────────────────────────────────────────────────────────────

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
      {/* Banda superior: badge de segmento + última visita */}
      <Card>
        <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs uppercase tracking-wide text-muted-foreground">
              Segmento
            </span>
            {isLoading ? (
              <Skeleton className="h-5 w-20" />
            ) : (
              <Badge variant={segmentVariant(segment?.key)}>
                {segment?.label ?? "—"}
              </Badge>
            )}
            {contact?.date && (
              <span className="ml-2 text-xs text-muted-foreground">
                Cliente desde {niceDate(contact.date)}
              </span>
            )}
          </div>
          <div className="flex flex-col items-end text-xs text-muted-foreground">
            <span>Última visita</span>
            {isLoading ? (
              <Skeleton className="h-4 w-24" />
            ) : (
              <span className="font-medium text-foreground">
                {lastVisitLabel(visits?.lastAt, visits?.daysSinceLast)}
              </span>
            )}
          </div>
        </CardContent>
      </Card>

      {/* KPIs principales */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard
          icon={<Wallet className="size-4 text-muted-foreground" />}
          label="Total gastado"
          value={isLoading ? null : formatMoney(totals?.spent, bootstrap)}
          hint={bootstrap?.currency}
        />
        <KpiCard
          icon={<ShoppingBag className="size-4 text-muted-foreground" />}
          label="Compras"
          value={isLoading ? null : formatInt(totals?.purchases, bootstrap)}
        />
        <KpiCard
          icon={<Layers className="size-4 text-muted-foreground" />}
          label="Artículos"
          value={isLoading ? null : formatInt(totals?.itemsBought, bootstrap)}
        />
        <KpiCard
          icon={<ClipboardList className="size-4 text-muted-foreground" />}
          label="Ticket promedio"
          value={isLoading ? null : formatMoney(totals?.avgTicket, bootstrap)}
          hint={bootstrap?.currency}
        />
      </div>

      {/* Detalles: frecuencia + primera visita + descuento aplicado */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">Actividad</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <DetailRow
            icon={<CalendarDays className="size-4 text-muted-foreground" />}
            label="Primera operación"
            value={isLoading ? null : niceDate(visits?.firstAt ?? null)}
          />
          <DetailRow
            icon={<TrendingUp className="size-4 text-muted-foreground" />}
            label="Frecuencia promedio"
            value={isLoading ? null : freqLabel(visits?.avgDaysBetween ?? null)}
          />
          <DetailRow
            icon={<Wallet className="size-4 text-muted-foreground" />}
            label="Descuento acumulado"
            value={isLoading ? null : formatMoney(totals?.discountTotal, bootstrap)}
          />
        </CardContent>
      </Card>

      {/* Top items y categorías — vista rápida del Resumen, profundizamos en Comportamiento */}
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        <TopItemsCard items={analytics?.topItems ?? []} isLoading={isLoading} bootstrap={bootstrap} />
        <TopCategoriesCard items={analytics?.topCategories ?? []} isLoading={isLoading} bootstrap={bootstrap} />
      </div>

      {/* Acciones rápidas — links a reportes filtrados por este contacto. */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">Acciones</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-2">
          <Button asChild variant="outline" size="sm">
            <Link href={`/reports?ci=${contact?.id ?? ""}`}>
              Historial de transacciones
            </Link>
          </Button>
          <Button asChild variant="outline" size="sm">
            <Link href={`/reports?ci=${contact?.id ?? ""}&view=products`}>
              Artículos adquiridos
            </Link>
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}

// ── TAB: Comportamiento ─────────────────────────────────────────────────────

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
      {/* Historial mensual — trend chart 12m */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">
            Compras por mes (12 meses)
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-[220px] w-full" />
          ) : monthSeries.length === 0 ? (
            <EmptyState label="Sin operaciones registradas." />
          ) : (
            <ResponsiveContainer width="100%" height={220}>
              <LineChart data={monthSeries} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="month" tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                  tickLine={false} axisLine={false} />
                <YAxis tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} tickLine={false}
                  axisLine={false} width={36}
                  tickFormatter={(v: number) => compactNum(v)} />
                <Tooltip
                  cursor={{ stroke: "var(--accent)", strokeWidth: 1 }}
                  contentStyle={{
                    background: "var(--popover)",
                    border: "1px solid var(--border)",
                    borderRadius: 8,
                    fontSize: 12,
                  }}
                  formatter={(v) => formatMoney(Number(v), bootstrap)}
                />
                <Line type="monotone" dataKey="total" stroke="var(--chart-1)" strokeWidth={2}
                  dot={{ r: 3 }} activeDot={{ r: 5 }} />
              </LineChart>
            </ResponsiveContainer>
          )}
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        {/* Horarios preferidos */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold tracking-tight">Horarios preferidos</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-[180px] w-full" />
            ) : hourSeries.length === 0 ? (
              <EmptyState label="Sin datos de horario." />
            ) : (
              <ResponsiveContainer width="100%" height={180}>
                <BarChart data={hourSeries} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="hour" tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                    tickLine={false} axisLine={false} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                    tickLine={false} axisLine={false} width={28} />
                  <Tooltip
                    cursor={{ fill: "var(--accent)", opacity: 0.5 }}
                    contentStyle={{
                      background: "var(--popover)",
                      border: "1px solid var(--border)",
                      borderRadius: 8,
                      fontSize: 12,
                    }}
                  />
                  <Bar dataKey="count" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>

        {/* Día de la semana */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold tracking-tight">Día de la semana</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-[180px] w-full" />
            ) : dowSeries.length === 0 ? (
              <EmptyState label="Sin datos por día." />
            ) : (
              <ResponsiveContainer width="100%" height={180}>
                <BarChart data={dowSeries} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="label" tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                    tickLine={false} axisLine={false} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                    tickLine={false} axisLine={false} width={28} />
                  <Tooltip
                    cursor={{ fill: "var(--accent)", opacity: 0.5 }}
                    contentStyle={{
                      background: "var(--popover)",
                      border: "1px solid var(--border)",
                      borderRadius: 8,
                      fontSize: 12,
                    }}
                  />
                  <Bar dataKey="count" fill="var(--chart-3)" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        {/* Mix de pago (donut) */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold tracking-tight">Forma de pago</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-[200px] w-full" />
            ) : paymentMix.length === 0 ? (
              <EmptyState label="Sin operaciones." />
            ) : (
              <div className="flex items-center gap-4">
                <div className="relative size-36 shrink-0">
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={paymentMix}
                        dataKey="total"
                        cx="50%"
                        cy="50%"
                        innerRadius="62%"
                        outerRadius="100%"
                        paddingAngle={2}
                        strokeWidth={0}
                      >
                        {paymentMix.map((_, i) => (
                          <Cell key={i} fill={`var(--chart-${(i % 5) + 1})`} />
                        ))}
                      </Pie>
                      <Tooltip
                        contentStyle={{
                          background: "var(--popover)",
                          border: "1px solid var(--border)",
                          borderRadius: 8,
                          fontSize: 12,
                        }}
                        formatter={(v) => formatMoney(Number(v), bootstrap)}
                      />
                    </PieChart>
                  </ResponsiveContainer>
                </div>
                <div className="flex flex-1 flex-col gap-2">
                  {paymentMix.map((p, i) => (
                    <div key={p.type} className="flex items-center justify-between gap-2 text-xs">
                      <span className="flex items-center gap-1.5 text-muted-foreground">
                        <span
                          className="size-2 rounded-full"
                          style={{ background: `var(--chart-${(i % 5) + 1})` }}
                        />
                        {p.label}
                      </span>
                      <span className="font-medium tabular-nums">
                        {formatMoney(p.total, bootstrap)}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Sucursales preferidas */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-sm font-medium">
              <MapPin className="size-4 text-muted-foreground" />
              Sucursales preferidas
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-32 w-full" />
            ) : outlets.length === 0 ? (
              <EmptyState label="Sin datos por sucursal." />
            ) : (
              <div className="flex flex-col divide-y divide-border">
                {outlets.map((o) => (
                  <div key={o.outletId}
                    className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0">
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

// ── TAB: Financiero ─────────────────────────────────────────────────────────

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
        <KpiCard
          icon={<Wallet className="size-4 text-muted-foreground" />}
          label="Crédito a favor"
          value={isLoading ? null : formatMoney(f?.storeCredit, bootstrap)}
          hint={bootstrap?.currency}
        />
        <KpiCard
          icon={<Sparkles className="size-4 text-muted-foreground" />}
          label="Loyalty acumulado"
          value={isLoading ? null : formatMoney(f?.loyalty, bootstrap)}
          hint={bootstrap?.currency}
        />
        <KpiCard
          icon={<ClipboardList className="size-4 text-muted-foreground" />}
          label="Línea de crédito"
          value={isLoading ? null : formatMoney(f?.creditLine, bootstrap)}
          hint={bootstrap?.currency}
        />
        <KpiCard
          icon={<TrendingUp className="size-4 text-muted-foreground" />}
          label="Cuentas por cobrar"
          value={isLoading ? null : formatMoney(f?.openInvoices, bootstrap)}
          hint={bootstrap?.currency}
        />
      </div>
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">Estado de crédito</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-5 w-40" />
          ) : (
            <div className="flex items-center gap-2 text-sm">
              <Badge variant={f?.isCreditable ? "default" : "secondary"}>
                {f?.isCreditable ? "Habilitado para crédito" : "Sin crédito habilitado"}
              </Badge>
              <span className="text-xs text-muted-foreground">
                Configurable desde el form de Datos.
              </span>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

// ── Helpers compartidos ─────────────────────────────────────────────────────

function KpiCard({
  icon,
  label,
  value,
  hint,
}: {
  icon: React.ReactNode
  label: string
  value: React.ReactNode
  hint?: string
}) {
  return (
    <Card>
      <CardContent className="flex flex-col gap-1 p-4">
        <div className="flex items-center gap-1.5 text-xs uppercase tracking-wide text-muted-foreground">
          {icon}
          {label}
        </div>
        {value === null ? (
          <Skeleton className="h-7 w-24" />
        ) : (
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
  icon,
  label,
  value,
}: {
  icon: React.ReactNode
  label: string
  value: React.ReactNode
}) {
  return (
    <div className="flex items-center gap-2 text-sm">
      {icon}
      <div className="flex flex-col">
        <span className="text-xs text-muted-foreground">{label}</span>
        {value === null ? (
          <Skeleton className="h-4 w-20" />
        ) : (
          <span className="font-medium">{value}</span>
        )}
      </div>
    </div>
  )
}

function TopItemsCard({
  items,
  isLoading,
  bootstrap,
}: {
  items: ContactAnalytics["topItems"]
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <ShoppingBag className="size-4 text-muted-foreground" />
          Productos preferidos
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">
            {[1, 2, 3].map((i) => <Skeleton key={i} className="h-6 w-full" />)}
          </div>
        ) : items.length === 0 ? (
          <EmptyState label="Sin compras registradas." />
        ) : (
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
  items,
  isLoading,
  bootstrap,
}: {
  items: ContactAnalytics["topCategories"]
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <Layers className="size-4 text-muted-foreground" />
          Categorías favoritas
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">
            {[1, 2, 3].map((i) => <Skeleton key={i} className="h-6 w-full" />)}
          </div>
        ) : items.length === 0 ? (
          <EmptyState label="Sin categorías registradas." />
        ) : (
          <div className="flex flex-col divide-y divide-border">
            {items.map((c) => (
              <div key={c.taxonomyId} className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0">
                <span className="truncate">{c.name}</span>
                <span className="text-xs text-muted-foreground tabular-nums">
                  {formatMoney(c.total, bootstrap)}
                </span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

/**
 * Wrapper local para los 7 empty states de los analytics tabs de un contacto.
 * Mantiene la API `<EmptyState label="..." />` original mientras delega al
 * componente compartido `<EmptyStateBlock>`. `showMarquee=false` porque vive
 * adentro de cards de analytics chicos — el marquee se vería desproporcionado.
 */
function EmptyState({ label }: { label: string }) {
  return (
    <div className="flex min-h-32 items-center justify-center">
      <EmptyStateBlock
        icon={Inbox}
        title={label}
        showMarquee={false}
        className="border-0 p-3"
      />
    </div>
  )
}

function segmentVariant(key: string | undefined): "default" | "secondary" | "destructive" | "outline" {
  switch (key) {
    case "vip":           return "default"
    case "activo":        return "default"
    case "nuevo":         return "secondary"
    case "en_riesgo":     return "outline"
    case "inactivo":      return "destructive"
    default:              return "secondary"
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

function BackLink() {
  return (
    <Link
      href="/contacts"
      className="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
    >
      <ArrowLeft className="size-3.5" />
      Volver a contactos
    </Link>
  )
}

// Alias local a FormSection compartido — jerarquía visual consistente.
function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <FormSection title={title}>{children}</FormSection>
}
