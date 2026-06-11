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
  latLng: z.string(),
  taxId: z.string(),
  ecom: z.boolean(),
  taxIncluded: z.boolean(),
})

export default function OutletEditPage() {
  const params = useParams<{ id: string }>()
  const id = params.id
  const router = useRouter()
  const { data, isLoading, error } = useOutlet(id)
  const update = useUpdateOutlet()
  const remove = useDeleteOutlet()

  const form = useForm<OutletFormValues>({
    resolver: zodResolver(outletSchema),
    defaultValues: emptyValues(),
  })

  // Reset form cuando llegan los datos del backend.
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
      latLng: data.latLng ?? "",
      taxId: data.taxId ?? "",
      ecom: data.ecom ?? false,
      taxIncluded: data.taxIncluded ?? false,
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
                {isLoading ? <Skeleton className="h-7 w-48" /> : data?.name || "Sucursal"}
              </h1>
            </div>
          </div>
          <div className="flex items-center gap-2">
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

            <Button type="submit" disabled={update.isPending || isLoading}>
              {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
              Guardar
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
            <FormField
              control={form.control}
              name="latLng"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Coordenadas / Link de Google Maps</FormLabel>
                  <FormControl>
                    <Input placeholder="-25.27, -57.59" {...field} />
                  </FormControl>
                  <FormDescription className="text-xs">
                    Pegá un link de Google Maps o ingresá latitud,longitud separadas por coma.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
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
    latLng: "",
    taxId: "",
    ecom: false,
    taxIncluded: false,
  }
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
