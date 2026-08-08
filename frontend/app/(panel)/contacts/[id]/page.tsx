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
import { ContactDetailView } from "@/components/domain/contacts/contact-detail-view"
import { useAgentPageSnapshot } from "@/lib/agent/use-agent-page-snapshot"
import { ApiError } from "@/lib/api-client"

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
    isCreditable: z.boolean(),
    creditLine: z.number().nullable(),
  })
  .refine(
    (v) => (v.kind === "persona" ? v.name.trim() !== "" : v.fiscalName.trim() !== ""),
    {
      message: "El nombre es requerido",
      path: ["name"],
    },
  )

export default function ContactEditPage() {
  // useSearchParams() requiere Suspense boundary (Next App Router) — mismo
  // patrón que items/page.tsx; ver comentario en pos/layout.tsx.
  return (
    <React.Suspense fallback={null}>
      <ContactEditPageInner />
    </React.Suspense>
  )
}

function ContactEditPageInner() {
  const params = useParams<{ id: string }>()
  const id = params.id
  const isNew = id === "new"
  const router = useRouter()
  const searchParams = useSearchParams()
  const contactType = searchParams.get("type") === "2" ? 2 : 1
  const isSupplier = contactType === 2
  const { data, isLoading, error } = useContact(isNew ? undefined : id)
  const create = useCreateContact()
  const update = useUpdateContact()
  const archive = useArchiveContact()
  const [country, setCountry] = React.useState<CountryCode>(DEFAULT_COUNTRY)

  useAgentPageSnapshot(
    isNew
      ? {
          route: "/contacts/new",
          routeLabel: contactType === 2 ? "Creando proveedor nuevo" : "Creando cliente nuevo",
          summary: { tipo: contactType === 2 ? "proveedor" : "cliente" },
        }
      : data
      ? {
          route: `/contacts/${id}`,
          routeLabel: `Editando ${contactType === 2 ? "proveedor" : "cliente"}: ${data.fullname ?? data.name}`,
          summary: {
            contactId: id,
            nombre: data.fullname ?? data.name,
            tipo: contactType === 2 ? "proveedor" : "cliente",
            telefono: data.phone ?? null,
            email: data.email ?? null,
          },
        }
      : null,
    [id, isNew, contactType, data?.name, data?.fullname, data?.phone, data?.email],
  )

  const form = useForm<ContactFormValues>({
    resolver: zodResolver(contactSchema),
    defaultValues: emptyValues(),
  })

  React.useEffect(() => {
    if (isNew || !data) return
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
        router.push(`/contacts/${created.id}?type=${contactType}`)
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

  const kind = form.watch("kind")
  const [tab, setTab] = React.useState<"summary" | "behavior" | "financial" | "data" | "addresses" | "packs">(
    isNew ? "data" : "summary",
  )
  const analytics = useContactAnalytics(
    !isNew && tab !== "data" ? id : undefined,
    isSupplier ? 2 : 1,
  )
  const { data: bootstrap } = useBootstrap()

  if (error) {
    const isNotFound = error instanceof ApiError && error.status === 404
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <Card>
          <CardContent className="p-8 text-center text-sm text-muted-foreground">
            {isNotFound ? "Contacto no encontrado." : `No se pudo cargar el contacto. ${error.message}`}
          </CardContent>
        </Card>
      </div>
    )
  }

  // Contacto existente → delegar a ContactDetailView
  if (!isNew) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <ContactDetailView customerId={id} variant="panel" nav="tabs" />
      </div>
    )
  }

  // Nuevo contacto → formulario inline (sin tabs, sin DetailView)
  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
        <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div className="flex flex-col gap-1">
            <BackLink />
            <h1 className="text-2xl font-semibold">Nuevo contacto</h1>
          </div>
          <div className="flex items-center gap-2">
            <Button type="submit" disabled={create.isPending}>
              {create.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
              Crear contacto
            </Button>
          </div>
        </header>
        <ContactFormBody form={form} kind={kind} country={country} setCountry={setCountry} />
      </form>
    </Form>
  )
}

/**
 * El form de edición (Identificación + Contacto) — solo para el modo "nuevo".
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
    isCreditable: false,
    creditLine: null,
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

// Alias local a FormSection compartido
function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <FormSection title={title}>{children}</FormSection>
}
