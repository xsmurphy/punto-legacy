"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import Link from "next/link"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { ArrowLeft, Loader2, Trash2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import { Skeleton } from "@/components/ui/skeleton"
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
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import {
  useCreateOutlet,
  useDeleteOutlet,
  useOutlet,
  useUpdateOutlet,
} from "@/hooks/use-outlets"
import type { OutletFormValues } from "@/lib/types/outlet"

const outletSchema = z.object({
  name: z.string().min(1, "El nombre es requerido"),
  address: z.string(),
  phone: z.string(),
  email: z.union([z.string().email("Email inválido"), z.literal("")]),
  description: z.string(),
  status: z.boolean(),
  billingName: z.string(),
  ruc: z.string(),
  whatsApp: z.string(),
  purchaseOrderNo: z.number().int().nonnegative().nullable(),
  // Lat/Lng: columnas numéricas con rango válido de coordenadas geográficas.
  lat: z.number().min(-90).max(90).nullable(),
  lng: z.number().min(-180).max(180).nullable(),
  taxId: z.string(),
  ecom: z.boolean(),
  taxIncluded: z.boolean(),
})

export default function OutletEditPage() {
  const params = useParams<{ id: string }>()
  const id = params.id
  // Modo create: el route param es la string literal "new" (Next dynamic
  // routes matchean cualquier string al [id]; usamos esa convención
  // para no duplicar el form en /outlets/new). Skip de useOutlet en este
  // caso — no hay nada que fetchear.
  const isNew = id === "new"
  const router = useRouter()
  const { data, isLoading, error } = useOutlet(isNew ? undefined : id)
  const create = useCreateOutlet()
  const update = useUpdateOutlet()
  const remove = useDeleteOutlet()

  const form = useForm<OutletFormValues>({
    resolver: zodResolver(outletSchema),
    defaultValues: emptyValues(),
  })

  // Reset form cuando llegan los datos del backend (sólo en edit).
  React.useEffect(() => {
    if (isNew || !data) return
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
    })
  }, [data, form, isNew])

  const onSubmit = async (values: OutletFormValues) => {
    try {
      if (isNew) {
        const { id: newId } = await create.mutateAsync(values)
        toast.success("Sucursal creada")
        router.push(`/outlets/${newId}`)
      } else {
        await update.mutateAsync({ id, values })
        toast.success("Sucursal actualizada")
      }
    } catch (e) {
      toast.error(isNew ? "No se pudo crear" : "No se pudo guardar", {
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
        <BackLink />
        <Card>
          <CardContent className="p-8 text-center text-sm text-muted-foreground">
            No se pudo cargar la sucursal. {error.message}
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
        <header className="flex items-end justify-between gap-4">
          <div className="flex flex-col gap-1">
            <BackLink />
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-semibold">
                {isNew ? (
                  "Nueva sucursal"
                ) : isLoading ? (
                  <Skeleton className="h-7 w-48" />
                ) : (
                  data?.name || "Sucursal"
                )}
              </h1>
            </div>
          </div>
          <div className="flex items-center gap-2">
            {!isNew && (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="ghost" size="icon" className="text-muted-foreground hover:text-destructive">
                  <Trash2 className="size-4" />
                </Button>
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
            )}

            <Button
              type="submit"
              disabled={(isNew ? create.isPending : update.isPending) || (isLoading && !isNew)}
            >
              {(isNew ? create.isPending : update.isPending) && (
                <Loader2 className="mr-2 size-4 animate-spin" />
              )}
              {isNew ? "Crear sucursal" : "Guardar"}
            </Button>
          </div>
        </header>

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          {/* General */}
          <Section title="General">
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
                  <div>
                    <FormLabel className="text-sm">Sucursal activa</FormLabel>
                    <FormDescription className="text-xs">
                      Si está apagada no aparece en la caja ni en reportes.
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
              name="ecom"
              render={({ field }) => (
                <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                  <div>
                    <FormLabel className="text-sm">E-commerce</FormLabel>
                    <FormDescription className="text-xs">
                      Marca para sucursales sin punto físico (solo online).
                    </FormDescription>
                  </div>
                  <FormControl>
                    <Switch checked={field.value} onCheckedChange={field.onChange} />
                  </FormControl>
                </FormItem>
              )}
            />
          </Section>

          {/* Datos fiscales */}
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
                      {(data?.availableTaxes ?? []).map((tax) => (
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
                  <div>
                    <FormLabel className="text-sm">Precio incluye impuesto</FormLabel>
                    <FormDescription className="text-xs">
                      Si está prendido, el precio de venta ya incluye el IVA.
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

          {/* Contacto */}
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
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Teléfono</FormLabel>
                  <FormControl>
                    <Input type="tel" placeholder="021 600 600" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="whatsApp"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>WhatsApp</FormLabel>
                  <FormControl>
                    <Input type="tel" placeholder="0981 123 456" {...field} />
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

          {/* Ubicación */}
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

            <GoogleMapsLinkParser
              onParsed={(lat, lng) => {
                form.setValue("lat", lat, { shouldDirty: true })
                form.setValue("lng", lng, { shouldDirty: true })
                toast.success(`Coordenadas extraídas: ${lat}, ${lng}`)
              }}
            />
          </Section>
        </div>
      </form>
    </Form>
  )
}

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
  }
}

/**
 * Helper de UX: el usuario pega un link de Google Maps o un string crudo
 * "lat,lng" y los inputs lat/lng se completan. Cubre los formatos típicos:
 *   https://www.google.com/maps/place/.../@-25.2867,-57.6478,17z/...
 *   https://maps.app.goo.gl/...?q=-25.2867,-57.6478
 *   -25.2867, -57.6478
 *   -25.2867,-57.6478
 *
 * Los links cortos `maps.app.goo.gl` requieren resolver el redirect — eso
 * no se puede hacer client-side por CORS. Mensaje al usuario para que
 * abra el link primero y pegue el largo.
 */
function GoogleMapsLinkParser({
  onParsed,
}: {
  onParsed: (lat: number, lng: number) => void
}) {
  const [text, setText] = React.useState("")

  const parse = () => {
    if (!text.trim()) return
    // Patrones: `@<lat>,<lng>` en URLs de Google Maps; `lat,lng` crudo;
    // `q=<lat>,<lng>` en query params.
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
        if (
          Number.isFinite(lat) && lat >= -90 && lat <= 90 &&
          Number.isFinite(lng) && lng >= -180 && lng <= 180
        ) {
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

function BackLink() {
  return (
    <Link
      href="/outlets"
      className="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
    >
      <ArrowLeft className="size-3.5" />
      Volver a sucursales
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
