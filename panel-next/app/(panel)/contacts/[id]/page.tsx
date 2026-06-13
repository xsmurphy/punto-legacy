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
  CalendarDays, MapPin, Sparkles,
} from "lucide-react"
import { toast } from "sonner"
import type { CountryCode } from "libphonenumber-js"
import {
  Bar, BarChart, CartesianGrid, Cell, Line, LineChart,
  Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
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
  useCreateContact,
  useUpdateContact,
} from "@/hooks/use-contacts"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import { formatInt, formatMoney } from "@/lib/format"
import { cn } from "@/lib/utils"
import type { ContactAnalytics, ContactFormValues, ContactFull } from "@/lib/types/contact"

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
    city: z.string(),
    location: z.string(),
    country: z.string(),
    address: z.string(),
    address2: z.string(),
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
      city: data.city ?? "",
      location: data.location ?? "",
      country: data.country ?? "",
      address: data.address ?? "",
      address2: data.address2 ?? "",
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
  const [tab, setTab] = React.useState<"summary" | "behavior" | "financial" | "data">(
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
            <TabsList>
              <TabsTrigger value="summary" className="gap-1.5">
                <BarChart3 className="size-3.5" />
                Resumen
              </TabsTrigger>
              <TabsTrigger value="behavior" className="gap-1.5">
                <Sparkles className="size-3.5" />
                Comportamiento
              </TabsTrigger>
              <TabsTrigger value="financial" className="gap-1.5">
                <Wallet className="size-3.5" />
                Financiero
              </TabsTrigger>
              <TabsTrigger value="data" className="gap-1.5">
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
                    <Input
                      type="date"
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

          {/* Dirección */}
          <Section title="Dirección">
            <FormField
              control={form.control}
              name="address"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Dirección principal</FormLabel>
                  <FormControl>
                    <Input placeholder="Calle y número" autoComplete="street-address" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="address2"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Dirección 2 (referencia)</FormLabel>
                  <FormControl>
                    <Input placeholder="Apto, piso, entre calles" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="city"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Ciudad</FormLabel>
                    <FormControl>
                      <Input placeholder="Asunción" autoComplete="address-level2" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="location"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Barrio / zona</FormLabel>
                    <FormControl>
                      <Input placeholder="Carmelitas" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>
            <FormField
              control={form.control}
              name="country"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>País</FormLabel>
                  <FormControl>
                    <Input placeholder="Paraguay" autoComplete="country-name" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </Section>
        </div>
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
          <CardTitle className="text-sm font-medium">Actividad</CardTitle>
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
          <CardTitle className="text-sm font-medium">Acciones</CardTitle>
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
          <CardTitle className="text-sm font-medium">
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
            <CardTitle className="text-sm font-medium">Horarios preferidos</CardTitle>
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
            <CardTitle className="text-sm font-medium">Día de la semana</CardTitle>
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
            <CardTitle className="text-sm font-medium">Forma de pago</CardTitle>
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
          <CardTitle className="text-sm font-medium">Estado de crédito</CardTitle>
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

function EmptyState({ label }: { label: string }) {
  return (
    <p className="flex h-32 items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
      {label}
    </p>
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
    city: "",
    location: "",
    country: "",
    address: "",
    address2: "",
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

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">{children}</CardContent>
    </Card>
  )
}
