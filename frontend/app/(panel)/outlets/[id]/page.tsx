"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Loader2, Pencil, Star, Trash2 } from "lucide-react"
import { isValidPhoneNumber } from "libphonenumber-js"
import { PhoneInput } from "@/components/forms/phone-input"
import { useTenantPhoneCountry } from "@/hooks/use-tenant-phone-country"
import type { CountryCode } from "libphonenumber-js"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { FormSection, FormSectionColumns } from "@/components/forms/form-section"
import { RowActions } from "@/components/data-table/row-actions"
import { RegistersTab } from "@/components/outlets/registers-tab"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import { Skeleton } from "@/components/ui/skeleton"
import { AddressMapParser } from "@/components/geo/address-map-parser"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
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
import { Label } from "@/components/ui/label"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import {
  useDeleteOutlet,
  useOutlet,
  useUpdateOutlet,
} from "@/hooks/use-outlets"
import { useAgentPageSnapshot } from "@/lib/agent/use-agent-page-snapshot"
import { usePriceLists } from "@/hooks/use-price-lists"
import {
  useOutletLocations,
  useCreateLocation,
  useUpdateLocation,
  useDeleteLocation,
  useSetDefaultLocation,
  type OutletLocation,
} from "@/hooks/use-outlet-locations"
import { BackLink } from "@/components/page/back-link"
import { EntityShell } from "@/components/page/entity-shell"
import { OutletSummaryTab } from "@/components/outlets/outlet-summary-tab"
import { useRegistersAdmin } from "@/hooks/use-registers-admin"
import type { OutletFormValues } from "@/lib/types/outlet"

const outletSchema = z.object({
  name: z.string().min(1, "El nombre es requerido"),
  address: z.string(),
  phone: z.string().refine(
    (v) => v === "" || isValidPhoneNumber(v),
    { message: "Teléfono inválido" }
  ),
  email: z.union([z.string().email("Email inválido"), z.literal("")]),
  description: z.string(),
  status: z.boolean(),
  billingName: z.string(),
  ruc: z.string(),
  whatsApp: z.string().refine(
    (v) => v === "" || isValidPhoneNumber(v),
    { message: "WhatsApp inválido" }
  ),
  purchaseOrderNo: z.number().int().nonnegative().nullable(),
  // Lat/Lng: columnas numéricas con rango válido de coordenadas geográficas.
  lat: z.number().min(-90).max(90).nullable(),
  lng: z.number().min(-180).max(180).nullable(),
  taxId: z.string(),
  ecom: z.boolean(),
  taxIncluded: z.boolean(),
  priceListId: z.string().nullable(),
})

/**
 * `?tab=` viejos → pestaña vigente. La ficha ("Sucursal", clave `general`) es
 * la pestaña Datos desde 2026-09-18 (context/84 §3). `depositos` y `cajas`
 * conservan su clave: hay links a `?tab=cajas` desde Facturación electrónica.
 */
const OUTLET_TAB_ALIASES: Record<string, string> = {
  general: "datos",
}

export default function OutletEditPage() {
  // useSearchParams() requiere Suspense boundary (Next App Router) — mismo
  // patrón que contacts/[id]/page.tsx.
  return (
    <React.Suspense fallback={null}>
      <OutletEditPageInner />
    </React.Suspense>
  )
}

function OutletEditPageInner() {
  const params = useParams<{ id: string }>()
  const id = params.id
  const router = useRouter()

  // Esta página es SOLO edición. El modo "new" (el route param literal
  // `new`, que Next matchea contra `[id]`) murió con el paywall del alta:
  // desde 2026-09-11 el comercio no crea sucursales, las PIDE — el alta la
  // habilita Punto al aprobar la solicitud (mig 219, `/v1/outlet-requests`).
  // Se redirige en vez de 404ear porque el link viejo puede estar guardado.
  const isNew = id === "new"
  React.useEffect(() => {
    if (isNew) router.replace("/outlets")
  }, [isNew, router])

  const { data, isLoading, error } = useOutlet(isNew ? undefined : id)
  const update = useUpdateOutlet()
  const remove = useDeleteOutlet()
  const { data: priceLists } = usePriceLists()

  useAgentPageSnapshot(
    data
      ? {
          route: `/outlets/${id}`,
          routeLabel: `Editando sucursal: ${data.name}`,
          summary: {
            outletId: id,
            nombre: data.name,
            activa: data.status === 1,
            direccion: data.address ?? null,
          },
        }
      : null,
    [id, data?.name, data?.status, data?.address],
  )

  const form = useForm<OutletFormValues>({
    resolver: zodResolver(outletSchema),
    defaultValues: emptyValues(),
  })

  const registers = useRegistersAdmin()
  const locations = useOutletLocations(isNew ? "" : id)

  // Reset form cuando llegan los datos del backend (sólo en edit).
  React.useEffect(() => {
    if (!data) return
    form.reset({
      name: data.name ?? "",
      address: data.address ?? "",
      phone: data.phone ?? "",
      email: data.email ?? "",
      description: data.description ?? "",
      status: data.status === 1,
      billingName: data.billingName ?? "",
      ruc: data.ruc ?? "",
      whatsApp: data.whatsApp ?? "",
      purchaseOrderNo: data.purchaseOrderNo,
      lat: data.lat,
      lng: data.lng,
      taxId: data.taxId ?? "",
      ecom: data.ecom ?? false,
      taxIncluded: data.taxIncluded ?? false,
      priceListId: data.priceListId ?? null,
    })
  }, [data, form])

  const onSubmit = async (values: OutletFormValues) => {
    try {
      await update.mutateAsync({ id, values })
      toast.success("Sucursal actualizada")
    } catch (e) {
      toast.error("No se pudo guardar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const onDelete = async () => {
    try {
      await remove.mutateAsync(id)
      toast.success("Sucursal eliminada")
      router.push("/outlets")
    } catch (e) {
      toast.error("No se pudo eliminar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink href="/outlets" label="Volver a sucursales" />
        <Card>
          <CardContent className="p-8 text-center text-sm text-muted-foreground">
            No se pudo cargar la sucursal. {error.message}
          </CardContent>
        </Card>
      </div>
    )
  }

  // Cuántas cajas y depósitos tiene: atributos de la sucursal, en el
  // encabezado (no un bloque del Resumen).
  const registerCount = (registers.data?.registers ?? []).filter((r) => r.outletId === id).length
  const locationCount = locations.data?.length ?? 0
  const facts = [
    data?.address || null,
    registers.data ? `${registerCount} ${registerCount === 1 ? "caja" : "cajas"}` : null,
    locations.data ? `${locationCount} ${locationCount === 1 ? "depósito" : "depósitos"}` : null,
  ]
    .filter(Boolean)
    .join(" · ")

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)}>
        <EntityShell
          back={{ href: "/outlets", label: "Volver a sucursales" }}
          title={data?.name || "Sucursal"}
          isLoading={isLoading}
          status={data && data.status !== 1 ? <Badge variant="outline">Inactiva</Badge> : null}
          subtitle={facts || null}
          actions={
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="outline">Eliminar</Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>¿Eliminar esta sucursal?</AlertDialogTitle>
                  <AlertDialogDescription>
                    Esta acción no se puede deshacer. La sucursal y su historial
                    se mantienen pero quedan inaccesibles desde la operación
                    diaria.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancelar</AlertDialogCancel>
                  <AlertDialogAction onClick={onDelete} disabled={remove.isPending}>
                    {remove.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                    Eliminar
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          }
          summary={<OutletSummaryTab outletId={id} />}
          // Perfil, contacto, datos fiscales y ubicación son LA ficha de la
          // sucursal, no cuatro pantallas: una sola pestaña Datos (antes se
          // llamaba "Sucursal").
          data={
            <FormSectionColumns>
              <GeneralTab form={form} priceLists={priceLists ?? []} />
              <ContactoTab form={form} />
              <FiscalTab form={form} availableTaxes={data?.availableTaxes ?? []} />
              <UbicacionTab form={form} />
            </FormSectionColumns>
          }
          extraTabs={[
            { key: "depositos", label: "Depósitos", content: <LocationsSection outletId={id} /> },
            // `?tab=cajas` deja linkear DERECHO al timbrado de la caja desde
            // otra pantalla — lo usa "Corregir y emitir de nuevo" de
            // Facturación electrónica (context/28 §F7 N2).
            { key: "cajas", label: "Cajas", content: <RegistersTab outletId={id} /> },
          ]}
          tabAliases={OUTLET_TAB_ALIASES}
          save={{ pending: update.isPending }}
        />
      </form>
    </Form>
  )
}

// ─── Tab helpers ─────────────────────────────────────────────────────────────

type FormProp = { form: ReturnType<typeof useForm<OutletFormValues>> }

function GeneralTab({
  form,
  priceLists,
}: FormProp & { priceLists: Array<{ priceListId: string; priceListName: string; status: boolean; defaultAdjustment: number }> }) {
  return (
    <Section title="Perfil">
      <FormField
        control={form.control}
        name="name"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Nombre</FormLabel>
            <FormControl>
              <Input placeholder="Ej: Sucursal Centro" {...field} />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
      <FormField
        control={form.control}
        name="description"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Descripción</FormLabel>
            <FormControl>
              <Textarea
                rows={2}
                placeholder="Opcional — referencia interna"
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
            <FormLabel>Sucursal activa</FormLabel>
            <FormControl>
              <Switch checked={field.value} onCheckedChange={field.onChange} />
            </FormControl>
          </FormItem>
        )}
      />
      <FormField
        control={form.control}
        name="ecom"
        render={({ field }) => (
          <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
            <FormLabel>E-commerce</FormLabel>
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
            <FormLabel>Lista de precios por defecto</FormLabel>
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
                {priceLists
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
  )
}

function FiscalTab({
  form,
  availableTaxes,
}: FormProp & { availableTaxes: Array<{ id: string; name: string; rate: number }> }) {
  return (
    <Section title="Datos fiscales">
      <FormField
        control={form.control}
        name="billingName"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Razón social</FormLabel>
            <FormControl>
              <Input placeholder="Nombre fiscal de la empresa" {...field} />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
      <FormField
        control={form.control}
        name="ruc"
        render={({ field }) => (
          <FormItem>
            <FormLabel>RUC</FormLabel>
            <FormControl>
              <Input placeholder="Ej: 80012345-6" {...field} />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
      <FormField
        control={form.control}
        name="taxId"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Impuesto por defecto</FormLabel>
            <Select onValueChange={field.onChange} value={field.value || ""}>
              <FormControl>
                <SelectTrigger>
                  <SelectValue placeholder="Sin impuesto" />
                </SelectTrigger>
              </FormControl>
              <SelectContent>
                {availableTaxes.map((tax) => (
                  <SelectItem key={tax.id} value={tax.id}>
                    {tax.name} ({tax.rate}%)
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
        name="taxIncluded"
        render={({ field }) => (
          <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
            <FormLabel>Precio incluye impuesto</FormLabel>
            <FormControl>
              <Switch checked={field.value} onCheckedChange={field.onChange} />
            </FormControl>
          </FormItem>
        )}
      />
      <FormField
        control={form.control}
        name="purchaseOrderNo"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Próximo Nº orden de compra</FormLabel>
            <FormControl>
              <Input
                type="number"
                inputMode="numeric"
                placeholder="0"
                value={field.value ?? ""}
                onChange={(e) => {
                  const v = e.target.value
                  field.onChange(v === "" ? null : Number(v))
                }}
                className="tabular-nums"
              />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
    </Section>
  )
}

function ContactoTab({ form }: FormProp) {
  // Los teléfonos de la sucursal son del mismo país que el comercio, no
  // siempre de Paraguay.
  const tenantPhoneCountry = useTenantPhoneCountry()
  return (
    <Section title="Contacto">
      <FormField
        control={form.control}
        name="address"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Dirección</FormLabel>
            <FormControl>
              <Input placeholder="Calle y número" {...field} />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
      <FormField
        control={form.control}
        name="phone"
        render={({ field, fieldState }) => (
          <FormItem>
            <FormLabel>Teléfono</FormLabel>
            <FormControl>
              <PhoneInput
                value={field.value}
                country={tenantPhoneCountry}
                onChange={(v) => field.onChange(v.e164 ?? v.value)}
                onBlur={field.onBlur}
                aria-invalid={!!fieldState.error}
              />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
      <FormField
        control={form.control}
        name="whatsApp"
        render={({ field, fieldState }) => (
          <FormItem>
            <FormLabel>WhatsApp</FormLabel>
            <FormControl>
              <PhoneInput
                value={field.value}
                country={tenantPhoneCountry}
                onChange={(v) => field.onChange(v.e164 ?? v.value)}
                onBlur={field.onBlur}
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
              <Input type="email" placeholder="sucursal@empresa.com" {...field} />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
    </Section>
  )
}

function UbicacionTab({ form }: FormProp) {
  return (
    <Section title="Ubicación">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField
          control={form.control}
          name="lat"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Latitud</FormLabel>
              <FormControl>
                <Input
                  type="number"
                  inputMode="decimal"
                  step="0.0000001"
                  placeholder="-25.2867"
                  value={field.value ?? ""}
                  onChange={(e) => {
                    const v = e.target.value
                    field.onChange(v === "" ? null : Number(v))
                  }}
                  className="tabular-nums"
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="lng"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Longitud</FormLabel>
              <FormControl>
                <Input
                  type="number"
                  inputMode="decimal"
                  step="0.0000001"
                  placeholder="-57.6478"
                  value={field.value ?? ""}
                  onChange={(e) => {
                    const v = e.target.value
                    field.onChange(v === "" ? null : Number(v))
                  }}
                  className="tabular-nums"
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </div>

      <AddressMapParser
        onParsed={({ lat, lng }) => {
          form.setValue("lat", lat, { shouldDirty: true })
          form.setValue("lng", lng, { shouldDirty: true })
          toast.success(`Coordenadas extraídas: ${lat}, ${lng}`)
        }}
      />
    </Section>
  )
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function emptyValues(): OutletFormValues {
  return {
    name: "",
    address: "",
    phone: "",
    email: "",
    description: "",
    status: true,
    billingName: "",
    ruc: "",
    whatsApp: "",
    purchaseOrderNo: null,
    lat: null,
    lng: null,
    taxId: "",
    ecom: false,
    taxIncluded: false,
    priceListId: null,
  }
}


function LocationsSection({ outletId }: { outletId: string }) {
  const { data: locations = [], isLoading } = useOutletLocations(outletId)
  const create = useCreateLocation(outletId)
  const update = useUpdateLocation(outletId)
  const remove = useDeleteLocation(outletId)
  const setDefault = useSetDefaultLocation(outletId)

  async function handleSetDefault(loc: OutletLocation) {
    try {
      await setDefault.mutateAsync(loc.id)
      toast.success(`"${loc.name}" es el depósito por defecto`)
    } catch (err: unknown) {
      toast.error(err instanceof Error ? err.message : "No se pudo cambiar el depósito por defecto")
    }
  }

  const [dialogOpen, setDialogOpen] = React.useState(false)
  const [editing, setEditing] = React.useState<OutletLocation | null>(null)
  const [nameInput, setNameInput] = React.useState("")
  const [nameError, setNameError] = React.useState("")
  const [deleteTarget, setDeleteTarget] = React.useState<OutletLocation | null>(null)

  function openCreate() {
    setEditing(null)
    setNameInput("")
    setNameError("")
    setDialogOpen(true)
  }

  function openEdit(loc: OutletLocation) {
    setEditing(loc)
    setNameInput(loc.name)
    setNameError("")
    setDialogOpen(true)
  }

  async function handleSave() {
    const trimmed = nameInput.trim()
    if (!trimmed) { setNameError("El nombre es requerido"); return }
    try {
      if (editing) {
        await update.mutateAsync({ id: editing.id, name: trimmed })
      } else {
        await create.mutateAsync(trimmed)
      }
      setDialogOpen(false)
    } catch {
      setNameError("Error al guardar")
    }
  }

  async function handleDelete() {
    if (!deleteTarget) return
    try {
      await remove.mutateAsync(deleteTarget.id)
      setDeleteTarget(null)
    } catch (err: unknown) {
      const msg = (err instanceof Error) ? err.message : "No se pudo eliminar"
      toast.error(msg)
      setDeleteTarget(null)
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle>Depósitos</CardTitle>
        <Button type="button" onClick={openCreate} size="sm">Agregar depósito</Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : locations.length === 0 ? (
          <p className="text-sm text-muted-foreground">Sin depósitos.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nombre</TableHead>
                <TableHead className="w-24 text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {locations.map((loc) => (
                <TableRow key={loc.id}>
                  <TableCell>
                    <span className="inline-flex items-center gap-2">
                      {loc.name}
                      {/* El depósito por defecto es donde cae el stock que no
                          eligió otro lugar — se marca para que se sepa cuál es
                          sin abrir el diálogo de ajuste. */}
                      {loc.isDefault && <Badge variant="secondary">Por defecto</Badge>}
                    </span>
                  </TableCell>
                  <TableCell className="text-right">
                    {/* Menú de fila en vez de dos iconos sueltos: es la única
                        forma válida de renderizar acciones de fila y deja el
                        borrado detrás de un click de fricción. */}
                    <RowActions
                      actions={[
                        { label: "Editar", icon: Pencil, onSelect: () => openEdit(loc) },
                        // El default no se puede borrar (la sucursal quedaría
                        // sin depósito): en vez de ofrecer el borrado y fallar
                        // con un 409, se ofrece mover la marca a otro.
                        ...(loc.isDefault
                          ? []
                          : [
                              {
                                // `icon` es requerido por el tipo RowAction.
                                label: "Predeterminado",
                                icon: Star,
                                onSelect: () => handleSetDefault(loc),
                              },
                              {
                                label: "Eliminar",
                                icon: Trash2,
                                variant: "destructive" as const,
                                onSelect: () => setDeleteTarget(loc),
                              },
                            ]),
                      ]}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing ? "Editar depósito" : "Agregar depósito"}</DialogTitle>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="loc-name">Nombre</Label>
            <Input
              id="loc-name"
              value={nameInput}
              onChange={(e) => { setNameInput(e.target.value); setNameError("") }}
              onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); void handleSave() } }}
              autoFocus
            />
            {nameError && <p className="text-sm text-destructive">{nameError}</p>}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>Cancelar</Button>
            <Button type="button" onClick={() => void handleSave()} disabled={create.isPending || update.isPending}>Guardar</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={!!deleteTarget} onOpenChange={(o) => { if (!o) setDeleteTarget(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar depósito</AlertDialogTitle>
            <AlertDialogDescription>
              ¿Eliminar &quot;{deleteTarget?.name}&quot;? Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={() => void handleDelete()} disabled={remove.isPending}>
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  )
}


// Alias local a FormSection compartido — jerarquía visual consistente
// (text-base / 600 + border-b) vs FormLabel (text-sm / 500).
function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <FormSection title={title}>{children}</FormSection>
}
