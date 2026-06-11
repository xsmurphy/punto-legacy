"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import Link from "next/link"
import { useForm, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import {
  ArrowLeft,
  Loader2,
  Archive,
  ExternalLink,
  Boxes,
  Settings as SettingsIcon,
  User,
  ChefHat,
} from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
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
import { useOutlets } from "@/hooks/use-outlets"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatMoney } from "@/lib/format"
import {
  inferKind,
  KIND_META,
  type ItemFormValues,
  type ItemKind,
  type KindFieldVisibility,
} from "@/lib/types/item"

const itemSchema = z.object({
  kind: z.enum([
    "producto",
    "servicio",
    "insumo_stock",
    "insumo_sin_stock",
    "produccion_previa",
    "produccion_directa",
    "combo",
    "descuento",
    "giftcard",
  ]),
  name: z.string().min(1, "El nombre es requerido"),
  sku: z.string(),
  description: z.string(),
  price: z.number().nonnegative().nullable(),
  cost: z.number().nonnegative().nullable(),
  discount: z.number().min(0).max(100).nullable(),
  taxId: z.string(),
  taxIncluded: z.boolean(),
  uom: z.string(),
  categoryId: z.string(),
  brandId: z.string(),
  status: z.boolean(),
  outletId: z.string(),
  supplierId: z.string(),
  waste: z.number().min(0).max(100).nullable(),
  sort: z.number().int().nullable(),
  commission: z.number().min(0).nullable(),
  commissionType: z.enum(["percent", "fixed"]),
  pricePercent: z.number().min(0).nullable(),
  priceType: z.enum(["fixed", "percent"]),
  ecom: z.boolean(),
  featured: z.boolean(),
})

type KindGroup = "Items de venta" | "Insumos" | "Producción" | "Otros"
const KIND_GROUPS: Array<{ label: KindGroup; kinds: ItemKind[] }> = [
  { label: "Items de venta", kinds: ["producto", "servicio"] },
  { label: "Insumos", kinds: ["insumo_stock", "insumo_sin_stock"] },
  { label: "Producción", kinds: ["produccion_previa", "produccion_directa"] },
  { label: "Otros", kinds: ["combo", "descuento", "giftcard"] },
]

export default function ItemEditPage() {
  const params = useParams<{ id: string }>()
  const id = params.id
  const isNew = id === "new"
  const router = useRouter()
  const { data, isLoading, error } = useItem(isNew ? undefined : id)
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
      kind: inferKind(data),
      name: data.itemName ?? "",
      sku: data.itemSKU ?? "",
      description: data.itemDescription ?? "",
      price: toNum(data.itemPrice),
      cost: toNum(data.itemCost),
      discount: toNum(data.itemDiscount),
      taxId: data.taxId ?? "",
      taxIncluded: !!data.itemTaxIncluded,
      uom: data.itemUOM ?? "",
      categoryId: data.categoryId ?? "",
      brandId: data.brandId ?? "",
      status: (data.itemStatus ?? 1) === 1,
      outletId: data.outletId ?? "",
      supplierId: data.supplierId ?? "",
      waste: toNum(data.itemWaste),
      sort: toNum(data.itemSort) ?? 99999,
      commission: toNum(data.itemComissionPercent),
      commissionType: data.itemComissionType === "1" ? "fixed" : "percent",
      pricePercent: toNum(data.itemPricePercent),
      priceType: data.itemPriceType ? "percent" : "fixed",
      ecom: !!data.itemEcom,
      featured: !!data.itemFeatured,
    })
  }, [data, form, isNew])

  const kind = form.watch("kind")
  const visibility = KIND_META[kind].fields

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

        <Tabs defaultValue="perfil" className="w-full">
          <TabsList className="grid w-full grid-cols-4 max-w-2xl">
            <TabsTrigger value="perfil" className="gap-1.5">
              <User className="size-3.5" />
              Perfil
            </TabsTrigger>
            <TabsTrigger value="config" className="gap-1.5">
              <SettingsIcon className="size-3.5" />
              Configuración
            </TabsTrigger>
            <TabsTrigger value="stock" className="gap-1.5" disabled={isNew}>
              <Boxes className="size-3.5" />
              Stock
            </TabsTrigger>
            <TabsTrigger value="produccion" className="gap-1.5" disabled={isNew}>
              <ChefHat className="size-3.5" />
              Producción
            </TabsTrigger>
          </TabsList>

          <TabsContent value="perfil" className="mt-6">
            <PerfilTab form={form} visibility={visibility} kind={kind} />
          </TabsContent>
          <TabsContent value="config" className="mt-6">
            <ConfigTab form={form} visibility={visibility} kind={kind} />
          </TabsContent>
          <TabsContent value="stock" className="mt-6">
            <StockTab id={id} isNew={isNew} />
          </TabsContent>
          <TabsContent value="produccion" className="mt-6">
            <ProduccionTab id={id} isNew={isNew} visibility={visibility} />
          </TabsContent>
        </Tabs>
      </form>
    </Form>
  )
}

// ── PERFIL TAB ──────────────────────────────────────────────────────────────

function PerfilTab({
  form,
  visibility,
}: {
  form: UseFormReturn<ItemFormValues>
  visibility: KindFieldVisibility
  kind: ItemKind
}) {
  const { data: bootstrap } = useBootstrap()
  const price = form.watch("price") ?? 0
  const cost = form.watch("cost") ?? 0
  // Cálculos de markup / margen / ganancia.
  const ganancia = price - cost
  const markup = cost > 0 ? ((price - cost) / cost) * 100 : 0
  const margen = price > 0 ? ((price - cost) / price) * 100 : 0

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="text-sm font-medium">Datos básicos</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <FormField
            control={form.control}
            name="kind"
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
                    {KIND_GROUPS.map((g) => (
                      <SelectGroup key={g.label}>
                        <SelectLabel>{g.label}</SelectLabel>
                        {g.kinds.map((k) => (
                          <SelectItem key={k} value={k}>
                            {KIND_META[k].label}
                          </SelectItem>
                        ))}
                      </SelectGroup>
                    ))}
                  </SelectContent>
                </Select>
                <FormDescription className="text-xs">
                  {KIND_META[field.value as ItemKind].description}
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
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
          <FormField
            control={form.control}
            name="sku"
            render={({ field }) => (
              <FormItem>
                <FormLabel>SKU / Código</FormLabel>
                <FormControl>
                  <Input placeholder="Código interno" className="tabular-nums" {...field} />
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
                  <Textarea rows={3} placeholder="Notas internas o detalles del producto" {...field} />
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
        </CardContent>
      </Card>

      {(visibility.showPrice || visibility.showCost) && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">Precio y costo</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {visibility.showPrice && (
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
                        className="tabular-nums text-lg font-semibold"
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            )}
            {visibility.showCost && (
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
                    <FormDescription className="text-xs">
                      Costo promedio (COGS). Se actualiza solo con movimientos de inventario.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            )}
            {visibility.showPrice && visibility.showCost && (
              <div className="grid grid-cols-3 gap-2 rounded-md border bg-muted/30 p-3 text-center text-xs">
                <div>
                  <div className="text-muted-foreground">Markup</div>
                  <div className="text-base font-semibold tabular-nums">
                    {markup.toFixed(0)}%
                  </div>
                </div>
                <div>
                  <div className="text-muted-foreground">Margen</div>
                  <div className="text-base font-semibold tabular-nums">
                    {margen.toFixed(0)}%
                  </div>
                </div>
                <div>
                  <div className="text-muted-foreground">Ganancia</div>
                  <div className="text-base font-semibold tabular-nums">
                    {formatMoney(ganancia, bootstrap)}
                  </div>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}

// ── CONFIGURACIÓN TAB ───────────────────────────────────────────────────────

function ConfigTab({
  form,
  visibility,
  kind,
}: {
  form: UseFormReturn<ItemFormValues>
  visibility: KindFieldVisibility
  kind: ItemKind
}) {
  const { data: categories } = useTaxonomiesByType("category")
  const { data: brands } = useTaxonomiesByType("brand")
  const { data: taxes } = useTaxonomiesByType("tax")
  const { data: outlets } = useOutlets()

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      {/* Categorización */}
      {visibility.showCategorization && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">Categorización</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
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
            <FormField
              control={form.control}
              name="outletId"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Sucursal</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value || ""}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Todas las sucursales" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="">Todas las sucursales</SelectItem>
                      {(outlets?.rows ?? []).map((o) => (
                        <SelectItem key={o.id} value={o.id}>
                          {o.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormDescription className="text-xs">
                    Si seleccionás una, el artículo solo aparece en esa caja.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
            {visibility.showUOM && (
              <FormField
                control={form.control}
                name="uom"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Unidad de medida</FormLabel>
                    <FormControl>
                      <Input
                        placeholder={
                          kind === "servicio"
                            ? "Ej: hora, sesión, mensual"
                            : "Ej: unidad, kg, ml, litro"
                        }
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            )}
          </CardContent>
        </Card>
      )}

      {/* Impuestos y descuentos */}
      {(visibility.showTax || visibility.showDiscount) && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">Impuestos y descuentos</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {visibility.showTax && (
              <>
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
                        <FormLabel className="text-sm">IVA incluido</FormLabel>
                        <FormDescription className="text-xs">
                          El precio de venta ya incluye el impuesto.
                        </FormDescription>
                      </div>
                      <FormControl>
                        <Switch checked={field.value} onCheckedChange={field.onChange} />
                      </FormControl>
                    </FormItem>
                  )}
                />
              </>
            )}
            {visibility.showDiscount && (
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
            )}
          </CardContent>
        </Card>
      )}

      {/* Comportamiento del precio (avanzado) */}
      {visibility.showPrice && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">Comportamiento del precio</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <FormField
              control={form.control}
              name="priceType"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Tipo de precio</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="fixed">Fijo (definido arriba)</SelectItem>
                      <SelectItem value="percent">% sobre el costo</SelectItem>
                    </SelectContent>
                  </Select>
                  <FormDescription className="text-xs">
                    En modo % sobre costo, el precio se recalcula automáticamente cuando
                    cambia el costo promedio.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
            {form.watch("priceType") === "percent" && (
              <FormField
                control={form.control}
                name="pricePercent"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>% sobre costo</FormLabel>
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
            )}
            <div className="grid grid-cols-[1fr_120px] items-end gap-2">
              <FormField
                control={form.control}
                name="commission"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Comisión por venta</FormLabel>
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
                name="commissionType"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel className="text-xs">&nbsp;</FormLabel>
                    <Select onValueChange={field.onChange} value={field.value}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="percent">%</SelectItem>
                        <SelectItem value="fixed">Gs</SelectItem>
                      </SelectContent>
                    </Select>
                  </FormItem>
                )}
              />
            </div>
          </CardContent>
        </Card>
      )}

      {/* Inventario y orden */}
      <Card>
        <CardHeader>
          <CardTitle className="text-sm font-medium">Otros ajustes</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {visibility.showInventoryInfo && (
            <FormField
              control={form.control}
              name="waste"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Merma (%)</FormLabel>
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
                  <FormDescription className="text-xs">
                    % de pérdida estimada en producción o manipulación.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
          )}
          <FormField
            control={form.control}
            name="sort"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Prioridad de ordenamiento</FormLabel>
                <FormControl>
                  <Input
                    type="number"
                    inputMode="numeric"
                    step="1"
                    placeholder="99999"
                    value={field.value ?? ""}
                    onChange={(e) => {
                      const v = e.target.value
                      field.onChange(v === "" ? null : Number(v))
                    }}
                    className="tabular-nums"
                  />
                </FormControl>
                <FormDescription className="text-xs">
                  Menor valor = aparece más arriba en la caja. Default 99999.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="ecom"
            render={({ field }) => (
              <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                <div>
                  <FormLabel className="text-sm">Online</FormLabel>
                  <FormDescription className="text-xs">
                    Disponible en el catálogo de e-commerce.
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
            name="featured"
            render={({ field }) => (
              <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                <div>
                  <FormLabel className="text-sm">Destacado</FormLabel>
                  <FormDescription className="text-xs">
                    Resalta el artículo en el catálogo y en la caja.
                  </FormDescription>
                </div>
                <FormControl>
                  <Switch checked={field.value} onCheckedChange={field.onChange} />
                </FormControl>
              </FormItem>
            )}
          />
        </CardContent>
      </Card>
    </div>
  )
}

// ── STOCK TAB ────────────────────────────────────────────────────────────────

function StockTab({ id, isNew }: { id: string; isNew: boolean }) {
  if (isNew) {
    return (
      <Card>
        <CardContent className="p-8 text-center text-sm text-muted-foreground">
          Primero creá el artículo. Una vez guardado, podés cargar stock inicial,
          ver el historial de movimientos y ajustar los depósitos.
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StockKpi label="Precio de compra" value="Próximamente" />
        <StockKpi label="Costo promedio" value="Próximamente" />
        <StockKpi label="Valor total del stock" value="Próximamente" />
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-sm font-medium">Ajustes e historial</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <p className="rounded-md border border-dashed p-3 text-xs text-muted-foreground">
            El editor de movimientos de stock (ajustes +/- y historial) requiere un
            endpoint backend nuevo (`/v1/items/{`{id}`}/inventory-movements`). Por ahora
            usá el editor del panel legacy.
          </p>
          <Button asChild variant="outline" size="sm" className="w-fit">
            <a
              href={`https://panel-legacy.punto.la/@#items/edit/${id}`}
              target="_blank"
              rel="noreferrer"
            >
              <ExternalLink className="size-3.5" />
              Editar stock en panel legacy
            </a>
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-sm font-medium">Depósitos donde vive este artículo</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <p className="text-xs text-muted-foreground">
            Marcá los depósitos donde se almacena el stock. En cada sucursal, elegí el
            <em> default</em> que se usa al vender o producir si no se especifica otro.
          </p>
          <p className="rounded-md border border-dashed p-3 text-xs text-muted-foreground">
            El editor de depósitos por ítem aterriza cuando portemos
            `/v1/items/{`{id}`}/locations` al editor de panel-next.
          </p>
        </CardContent>
      </Card>
    </div>
  )
}

function StockKpi({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <CardContent className="p-4 text-center">
        <div className="text-xs uppercase tracking-wide text-muted-foreground">
          {label}
        </div>
        <div className="mt-1 text-2xl font-semibold tabular-nums opacity-50">
          {value}
        </div>
      </CardContent>
    </Card>
  )
}

// ── PRODUCCIÓN TAB ──────────────────────────────────────────────────────────

function ProduccionTab({
  id,
  isNew,
  visibility,
}: {
  id: string
  isNew: boolean
  visibility: KindFieldVisibility
}) {
  if (isNew) {
    return (
      <Card>
        <CardContent className="p-8 text-center text-sm text-muted-foreground">
          Primero creá el artículo. Una vez guardado, podés agregar ingredientes
          (compounds) si el tipo lo requiere.
        </CardContent>
      </Card>
    )
  }

  if (!visibility.showCompounds) {
    return (
      <Card>
        <CardContent className="p-8 text-center text-sm text-muted-foreground">
          Este tipo de artículo no tiene ingredientes ni receta. Si querés agregar
          compounds, cambialo a tipo &quot;Producción previa&quot; o &quot;Producción
          directa&quot; en la pestaña Perfil.
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-sm font-medium">Ingredientes / Receta</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <p className="text-xs text-muted-foreground">
            Los ingredientes definen qué insumos se consumen al producir o vender
            este artículo. El editor de compounds aterriza en panel-next en un slice
            futuro — por ahora usá el editor del panel legacy.
          </p>
          <Button asChild variant="outline" size="sm" className="w-fit">
            <a
              href={`https://panel-legacy.punto.la/@#items/edit/${id}`}
              target="_blank"
              rel="noreferrer"
            >
              <ExternalLink className="size-3.5" />
              Editar ingredientes en panel legacy
            </a>
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}

// ── HELPERS ─────────────────────────────────────────────────────────────────

function toNum(v: unknown): number | null {
  if (v === null || v === undefined || v === "") return null
  const n = typeof v === "string" ? parseFloat(v) : (v as number)
  return Number.isFinite(n) ? n : null
}

function emptyValues(): ItemFormValues {
  return {
    kind: "producto",
    name: "",
    sku: "",
    description: "",
    price: null,
    cost: null,
    discount: null,
    taxId: "",
    taxIncluded: true,
    uom: "",
    categoryId: "",
    brandId: "",
    status: true,
    outletId: "",
    supplierId: "",
    waste: null,
    sort: 99999,
    commission: null,
    commissionType: "percent",
    pricePercent: null,
    priceType: "fixed",
    ecom: false,
    featured: false,
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
