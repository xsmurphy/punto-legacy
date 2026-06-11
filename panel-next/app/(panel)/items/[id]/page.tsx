"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import Link from "next/link"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { ArrowLeft, Loader2, Archive } from "lucide-react"
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
  useArchiveItem,
  useCreateItem,
  useItem,
  useTaxonomiesByType,
  useUpdateItem,
} from "@/hooks/use-items"
import type { ItemFormValues, ItemType } from "@/lib/types/item"

const itemSchema = z.object({
  name: z.string().min(1, "El nombre es requerido"),
  sku: z.string(),
  type: z.enum(["product", "service", "discount", "combo", "giftcard"]),
  description: z.string(),
  price: z.number().nonnegative().nullable(),
  cost: z.number().nonnegative().nullable(),
  discount: z.number().min(0).max(100).nullable(),
  taxId: z.string(),
  taxIncluded: z.boolean(),
  trackInventory: z.boolean(),
  canSale: z.boolean(),
  production: z.boolean(),
  uom: z.string(),
  categoryId: z.string(),
  brandId: z.string(),
  status: z.boolean(),
})

export default function ItemEditPage() {
  const params = useParams<{ id: string }>()
  const id = params.id
  const isNew = id === "new"
  const router = useRouter()
  const { data, isLoading, error } = useItem(isNew ? undefined : id)
  const { data: categories } = useTaxonomiesByType("category")
  const { data: brands } = useTaxonomiesByType("brand")
  const { data: taxes } = useTaxonomiesByType("tax")
  const create = useCreateItem()
  const update = useUpdateItem()
  const archive = useArchiveItem()

  const form = useForm<ItemFormValues>({
    resolver: zodResolver(itemSchema),
    defaultValues: emptyValues(),
  })

  React.useEffect(() => {
    if (isNew || !data) return
    form.reset({
      name: data.itemName ?? "",
      sku: data.itemSKU ?? "",
      type: (data.itemType ?? "product") as ItemType,
      description: data.itemDescription ?? "",
      price: toNum(data.itemPrice),
      cost: toNum(data.itemCost),
      discount: toNum(data.itemDiscount),
      taxId: data.taxId ?? "",
      taxIncluded: !!data.itemTaxIncluded,
      trackInventory: !!data.itemTrackInventory,
      canSale: data.itemCanSale === null || data.itemCanSale === undefined ? true : !!data.itemCanSale,
      production: !!data.itemProduction,
      uom: data.itemUOM ?? "",
      categoryId: data.categoryId ?? "",
      brandId: data.brandId ?? "",
      status: (data.itemStatus ?? 1) === 1,
    })
  }, [data, form, isNew])

  const onSubmit = async (values: ItemFormValues) => {
    try {
      if (isNew) {
        const created = await create.mutateAsync(values)
        toast.success("Artículo creado")
        router.push(`/items/${created.itemId}`)
      } else {
        await update.mutateAsync({ id, values })
        toast.success("Artículo actualizado")
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
      toast.success("Artículo archivado")
      router.push("/items")
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
            No se pudo cargar el artículo. {error.message}
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
            <h1 className="text-2xl font-semibold">
              {isNew ? "Nuevo artículo" : isLoading ? (
                <Skeleton className="h-7 w-48" />
              ) : (
                data?.itemName || "Artículo"
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
                    <AlertDialogTitle>¿Archivar este artículo?</AlertDialogTitle>
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
            <Button
              type="submit"
              disabled={(isNew ? create.isPending : update.isPending) || (isLoading && !isNew)}
            >
              {(isNew ? create.isPending : update.isPending) && (
                <Loader2 className="mr-2 size-4 animate-spin" />
              )}
              {isNew ? "Crear artículo" : "Guardar"}
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
                    <Input placeholder="Ej: Café Espresso" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="sku"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>SKU</FormLabel>
                    <FormControl>
                      <Input placeholder="Código interno" className="tabular-nums" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="type"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tipo</FormLabel>
                    <Select onValueChange={field.onChange} value={field.value}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="product">Producto</SelectItem>
                        <SelectItem value="service">Servicio</SelectItem>
                        <SelectItem value="discount">Descuento</SelectItem>
                        <SelectItem value="combo">Combo</SelectItem>
                        <SelectItem value="giftcard">Gift card</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>
            <FormField
              control={form.control}
              name="description"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Descripción</FormLabel>
                  <FormControl>
                    <Textarea
                      rows={3}
                      placeholder="Notas internas o detalles del producto"
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
                      Apagado = archivado, no aparece en la caja.
                    </FormDescription>
                  </div>
                  <FormControl>
                    <Switch checked={field.value} onCheckedChange={field.onChange} />
                  </FormControl>
                </FormItem>
              )}
            />
          </Section>

          {/* Precios */}
          <Section title="Precios">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="price"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Precio de venta</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        inputMode="decimal"
                        step="0.01"
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
              <FormField
                control={form.control}
                name="cost"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Costo</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        inputMode="decimal"
                        step="0.01"
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
            </div>
            <FormField
              control={form.control}
              name="taxId"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Impuesto</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value || ""}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Sin impuesto" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {taxes.map((t) => (
                        <SelectItem key={t.id} value={t.id}>
                          {t.name}%
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
              name="discount"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Descuento por defecto (%)</FormLabel>
                  <FormControl>
                    <Input
                      type="number"
                      inputMode="decimal"
                      step="0.01"
                      min={0}
                      max={100}
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

          {/* Inventario */}
          <Section title="Inventario">
            <FormField
              control={form.control}
              name="trackInventory"
              render={({ field }) => (
                <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                  <div>
                    <FormLabel className="text-sm">Lleva stock</FormLabel>
                    <FormDescription className="text-xs">
                      Si está prendido, el sistema descuenta inventario en cada venta.
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
              name="canSale"
              render={({ field }) => (
                <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                  <div>
                    <FormLabel className="text-sm">Se puede vender</FormLabel>
                    <FormDescription className="text-xs">
                      Apagado = solo se usa como ingrediente / componente.
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
              name="production"
              render={({ field }) => (
                <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                  <div>
                    <FormLabel className="text-sm">Se produce</FormLabel>
                    <FormDescription className="text-xs">
                      Activá si el artículo pasa por el módulo de producción/recetas.
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
              name="uom"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Unidad de medida</FormLabel>
                  <FormControl>
                    <Input placeholder="Ej: unidad, kg, litro, hora" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </Section>

          {/* Categorización */}
          <Section title="Categorización">
            <FormField
              control={form.control}
              name="categoryId"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Categoría</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value || ""}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Sin categoría" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {categories.map((c) => (
                        <SelectItem key={c.id} value={c.id}>
                          {c.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormDescription className="text-xs">
                    Las categorías se gestionan desde el panel legacy por ahora.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="brandId"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Marca</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value || ""}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Sin marca" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {brands.map((b) => (
                        <SelectItem key={b.id} value={b.id}>
                          {b.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
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

function toNum(v: unknown): number | null {
  if (v === null || v === undefined || v === "") return null
  const n = typeof v === "string" ? parseFloat(v) : (v as number)
  return Number.isFinite(n) ? n : null
}

function emptyValues(): ItemFormValues {
  return {
    name: "",
    sku: "",
    type: "product",
    description: "",
    price: null,
    cost: null,
    discount: null,
    taxId: "",
    taxIncluded: true,
    trackInventory: false,
    canSale: true,
    production: false,
    uom: "",
    categoryId: "",
    brandId: "",
    status: true,
  }
}

function BackLink() {
  return (
    <Link
      href="/items"
      className="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
    >
      <ArrowLeft className="size-3.5" />
      Volver a artículos
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
