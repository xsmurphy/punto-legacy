"use client"

import * as React from "react"
import { useParams, useRouter, useSearchParams } from "next/navigation"
import Link from "next/link"
import { useForm, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import {
  ArrowLeft,
  Loader2,
  Archive,
  Boxes,
  Settings as SettingsIcon,
  User,
  ChefHat,
  Calendar,
  Check,
  Coins,
  Images,
  Package2,
  Layers,
} from "lucide-react"
import { toast } from "sonner"

import { cn } from "@/lib/utils"

import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/empty-state"
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { MoneyInput } from "@/components/ui/money-input"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import { Separator } from "@/components/ui/separator"
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
  parseAvailability,
  parseCurrencies,
  useArchiveItem,
  useCreateItem,
  useCurrencies,
  useItem,
  useTaxonomiesByType,
  useUpdateItemCategories,
  useUpdateItemBrands,
  useUpdateItemTags,
  useUpdateItem,
} from "@/hooks/use-items"
import { useOutlets } from "@/hooks/use-outlets"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useTaxes } from "@/hooks/use-taxes"
import { formatMoney } from "@/lib/format"
import {
  inferKind,
  KIND_META,
  DAYS,
  DAY_LABELS,
  DEFAULT_GIFTCARD_COLOR,
  GIFTCARD_COLORS,
  defaultAvailability,
  type DayOfWeek,
  type ItemFormValues,
  type ItemImage,
  type ItemKind,
  type KindFieldVisibility,
} from "@/lib/types/item"
import { useAgentPageSnapshot } from "@/lib/agent/use-agent-page-snapshot"
import { ItemGallery } from "@/components/items/item-gallery"
import { ProductPhoto } from "@/components/items/product-photo"
import { CompoundsEditor } from "@/components/items/compounds-editor"
import { ComboGroupsEditor } from "@/components/items/combo-groups-editor"
import { LocationsEditor } from "@/components/items/locations-editor"
import { ItemStockTab } from "@/components/items/stock-tab"
import { PackComponentsEditor } from "@/components/items/pack-components-editor"
import { CategoriesPicker, type SelectedCategory } from "@/components/items/categories-picker"
import { BrandsPicker, type SelectedBrand } from "@/components/items/brands-picker"
import { TagsPicker } from "@/components/items/tags-picker"
import { useTags } from "@/hooks/use-tags"
import { VariantsTab } from "@/components/items/variants-tab"
import { useItemVariants } from "@/hooks/use-item-variants"
import { api } from "@/lib/api-client"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { useFormTabErrors, TabErrorDot } from "@/hooks/use-form-tab-errors"

const itemSchema = z.object({
  kind: z.enum([
    "producto",
    "servicio",
    "servicio_sesiones",
    "insumo_stock",
    "insumo_sin_stock",
    "insumo_control",
    "produccion_previa",
    "produccion_directa",
    "combo_fijo",
    "combo_dinamico",
    "descuento",
    "giftcard",
    "pack",
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
  waste: z.number().min(0).max(99).nullable(),
  // Umbrales de stock. Nullable a propósito: null es "no se controla por este
  // umbral", que NO es lo mismo que 0 ("avisame al llegar a cero").
  minStock: z.number().min(0).nullable(),
  maxStock: z.number().min(0).nullable(),
  sort: z.number().int().nullable(),
  commission: z.number().min(0).nullable(),
  commissionType: z.enum(["percent", "fixed"]),
  pricePercent: z.number().min(0).nullable(),
  priceType: z.enum(["fixed", "percent"]),
  ecom: z.boolean(),
  featured: z.boolean(),
  procedure: z.string(),
  availability: z.object({
    enabled: z.boolean(),
    days: z.record(
      z.string(),
      z.object({
        enabled: z.boolean(),
        from: z.string(),
        to: z.string(),
      }),
    ),
  }),
  currencies: z.record(z.string(), z.number()),
  validFrom: z.string().nullable(),
  validUntil: z.string().nullable(),
  minDaysBetweenSessions: z.number().int().nonnegative().nullable(),
  giftcardColor: z.string(),
  packDurationDays: z.number().int().positive().nullable(),
  itemSessions: z.number().int().nonnegative().nullable(),
})

type KindGroup = "Items de venta" | "Insumos" | "Producción" | "Otros"
const KIND_GROUPS: Array<{ label: KindGroup; kinds: ItemKind[] }> = [
  { label: "Items de venta", kinds: ["producto", "servicio", "servicio_sesiones"] },
  { label: "Insumos", kinds: ["insumo_stock", "insumo_sin_stock", "insumo_control"] },
  { label: "Producción", kinds: ["produccion_previa", "produccion_directa"] },
  { label: "Otros", kinds: ["combo_fijo", "combo_dinamico", "descuento", "giftcard", "pack"] },
]

export default function ItemEditPage() {
  // useSearchParams() requiere Suspense boundary (Next App Router) — mismo
  // patrón que items/page.tsx; ver comentario en pos/layout.tsx.
  return (
    <React.Suspense fallback={null}>
      <ItemEditPageInner />
    </React.Suspense>
  )
}

function ItemEditPageInner() {
  const params = useParams<{ id: string }>()
  const id = params.id
  const isNew = id === "new"
  const router = useRouter()
  const searchParams = useSearchParams()
  const { data, isLoading, error } = useItem(isNew ? undefined : id)
  const create = useCreateItem()
  const update = useUpdateItem()
  const archive = useArchiveItem()

  const [hasVariants, setHasVariants] = React.useState(false)

  const { data: variantsData } = useItemVariants(isNew ? undefined : id)
  const savedVariantCount = variantsData?.variants?.length ?? 0

  // En modo creación, el dialog que abre el listado pasa ?kind=X para que el
  // form arranque con el tipo elegido. Si el param es inválido, default 'producto'.
  const initialKind: ItemKind = React.useMemo(() => {
    if (!isNew) return "producto"
    const k = searchParams.get("kind") as ItemKind | null
    return k && KIND_META[k] ? k : "producto"
  }, [isNew, searchParams])

  const form = useForm<ItemFormValues>({
    resolver: zodResolver(itemSchema),
    defaultValues: { ...emptyValues(), kind: initialKind },
  })

  const [activeTab, setActiveTab] = React.useState("perfil")
  // "imagenes", "stock" y "variantes" quedan afuera: no tienen campos del
  // form de react-hook-form (galería/stock son sus propios editores, y
  // variantes se renderiza condicional sobre hasVariants).
  const { tabsWithErrors, onInvalid } = useFormTabErrors({
    form,
    fields: {
      perfil: ["name", "sku", "description", "kind", "status", "price", "cost", "packDurationDays", "giftcardColor", "itemSessions"],
      config: ["outletId", "uom", "taxId", "taxIncluded", "discount", "priceType", "pricePercent", "commission", "commissionType", "sort", "ecom", "featured"],
      disponibilidad: ["availability"],
      cotizaciones: ["currencies"],
      produccion: ["procedure"],
    },
    onTabChange: setActiveTab,
    tabLabels: {
      perfil: "Perfil",
      config: "Configuración",
      disponibilidad: "Disponibilidad",
      cotizaciones: "Cotizaciones",
      produccion: "Producción",
    },
  })

  // Para items nuevos: pre-seleccionamos el primer impuesto disponible del
  // tenant para que el form no arranque sin impuesto. taxIncluded ya viene
  // true en emptyValues.
  //
  // Migrado de useTaxonomiesByType("tax") a useTaxes (F0 del plan de
  // impuestos multi-país, context/38) — la tabla `tax` es la fuente única
  // de impuestos. El valor persistido en el item sigue siendo el UUID
  // (taxId), no cambia.
  const taxes = useTaxes()
  const taxList = taxes.data?.taxes ?? []
  React.useEffect(() => {
    if (!isNew) return
    if (form.getValues("taxId")) return
    const firstTax = taxList[0]
    if (firstTax) form.setValue("taxId", firstTax.id, { shouldDirty: false })
  }, [isNew, taxList, form])

  // Estado local del multi-select de categorías (m2m item_category).
  // El form de react-hook-form sigue manejando `categoryId` (legacy 1:1) que
  // se sincroniza con la categoría marcada como isPrimary acá.
  const [selectedCategories, setSelectedCategories] = React.useState<SelectedCategory[]>([])
  const updateItemCategories = useUpdateItemCategories()
  const [selectedBrands, setSelectedBrands] = React.useState<SelectedBrand[]>([])
  const updateItemBrands = useUpdateItemBrands()
  const [selectedTags, setSelectedTags] = React.useState<string[]>([])
  const updateItemTags = useUpdateItemTags()

  useAgentPageSnapshot(
    isNew
      ? {
          route: "/items/new",
          routeLabel: "Creando artículo nuevo",
          summary: {},
        }
      : data
      ? {
          route: `/items/${id}`,
          routeLabel: `Editando artículo: ${data.itemName}`,
          summary: {
            itemId: id,
            nombre: data.itemName,
            tipo: data.kind,
            sku: data.itemSKU,
            precio: data.itemPrice,
            activo: data.itemStatus === 1,
          },
        }
      : null,
    [id, isNew, data?.itemName, data?.kind, data?.itemSKU, data?.itemPrice, data?.itemStatus],
  )

  React.useEffect(() => {
    if (isNew || !data) return
    // El backend a veces devuelve `false` (bool PHP) en campos de texto que
    // estaban null o vacíos en el JSONB legacy. `?? ""` no atrapa false
    // (no es null/undefined), así que el form recibe `false` → input rompe.
    // toStr() coerciona cualquier no-string a "".
    form.reset({
      kind: inferKind(data),
      name: toStr(data.itemName),
      sku: toStr(data.itemSKU),
      description: toStr(data.itemDescription),
      price: toNum(data.itemPrice),
      cost: toNum(data.itemCost),
      discount: toNum(data.itemDiscount),
      taxId: toStr(data.taxId),
      taxIncluded: !!data.itemTaxIncluded,
      uom: toStr(data.itemUOM),
      categoryId: toStr(data.categoryId),
      brandId: toStr(data.brandId),
      status: (toNum(data.itemStatus) ?? 1) === 1,
      outletId: toStr(data.outletId),
      supplierId: toStr(data.supplierId),
      waste: toNum(data.itemWaste),
      minStock: toNum(data.itemMinStock),
      maxStock: toNum(data.itemMaxStock),
      sort: toNum(data.itemSort) ?? 99999,
      commission: toNum(data.itemComissionPercent),
      commissionType: data.itemComissionType === "1" ? "fixed" : "percent",
      pricePercent: toNum(data.itemPricePercent),
      priceType: data.itemPriceType ? "percent" : "fixed",
      ecom: !!data.itemEcom,
      featured: !!data.itemFeatured,
      procedure: toStr(data.itemProcedure),
      availability: parseAvailability(data.itemDateHour),
      currencies: parseCurrencies(data.itemCurrencies),
      validFrom: toStr(data.validFrom) || null,
      validUntil: toStr(data.validUntil) || null,
      minDaysBetweenSessions:
        typeof data.minDaysBetweenSessions === "number"
          ? data.minDaysBetweenSessions
          : null,
      giftcardColor:
        toStr(data.itemGiftcardColor) || DEFAULT_GIFTCARD_COLOR,
      packDurationDays:
        typeof data.packDurationDays === "number" ? data.packDurationDays : null,
      itemSessions:
        typeof data.itemSessions === "number" ? data.itemSessions : null,
    })
    // Hidratar hasVariants.
    const hv = data.hasVariants
    setHasVariants(hv === true || (hv as unknown) === 't' || (hv as unknown) === '1' || (hv as unknown) === 1)
    // Hidratar el multi-select de categorías desde el m2m. Si no hay nada y
    // el legacy `categoryId` apunta a una, la incluimos como única primary.
    if (data.categories && data.categories.length > 0) {
      setSelectedCategories(
        data.categories.map((c) => ({ id: c.id, isPrimary: c.isPrimary })),
      )
    } else if (data.categoryId) {
      setSelectedCategories([{ id: data.categoryId, isPrimary: true }])
    } else {
      setSelectedCategories([])
    }
    // Hidratar marcas — preferir brandsDetail (m2m); fallback al legacy brandId.
    if (data.brandsDetail && data.brandsDetail.length > 0) {
      setSelectedBrands(
        data.brandsDetail.map((b) => ({ id: b.id, isPrimary: b.isPrimary })),
      )
    } else if (data.brandId) {
      setSelectedBrands([{ id: data.brandId, isPrimary: true }])
    } else {
      setSelectedBrands([])
    }
    // Hidratar etiquetas — preferir tagsDetail (m2m); fallback al legacy data.tags.
    if (data.tagsDetail && data.tagsDetail.length > 0) {
      setSelectedTags(data.tagsDetail.map((t) => t.id))
    } else if (Array.isArray(data.tags)) {
      setSelectedTags(data.tags as string[])
    } else {
      setSelectedTags([])
    }
  }, [data, form, isNew])

  const kind: ItemKind = form.watch("kind") ?? "producto"
  const baseVisibility = (KIND_META[kind] ?? KIND_META["producto"]).fields
  const visibility: KindFieldVisibility = hasVariants
    ? { ...baseVisibility, showPrice: false, showCost: false, showInventoryInfo: false }
    : baseVisibility

  // Mantener el legacy form.categoryId apuntando a la primary del m2m. Sin
  // esto, el PUT del item escribiría null sobre item.categoryId y los
  // reports legacy (que leen esa columna) perderían la categoría primaria.
  const primaryCategoryId = React.useMemo(
    () => selectedCategories.find((c) => c.isPrimary)?.id ?? selectedCategories[0]?.id ?? "",
    [selectedCategories],
  )
  React.useEffect(() => {
    if (form.getValues("categoryId") !== primaryCategoryId) {
      form.setValue("categoryId", primaryCategoryId, { shouldDirty: false })
    }
  }, [primaryCategoryId, form])

  const primaryBrandId = React.useMemo(
    () => selectedBrands.find((b) => b.isPrimary)?.id ?? selectedBrands[0]?.id ?? "",
    [selectedBrands],
  )
  React.useEffect(() => {
    if (form.getValues("brandId") !== primaryBrandId) {
      form.setValue("brandId", primaryBrandId, { shouldDirty: false })
    }
  }, [primaryBrandId, form])

  const onSubmit = async (values: ItemFormValues) => {
    try {
      let targetId: string
      if (isNew) {
        const created = await create.mutateAsync(values)
        if (hasVariants) {
          await api.put(`/v1/items?id=${created.itemId}`, { hasVariants: true })
        }
        targetId = created.itemId
      } else {
        await update.mutateAsync({ id, values })
        // Solo emitir el segundo PUT si el toggle hasVariants cambió respecto al valor guardado.
        // Evita el double-write innecesario y la carrera de precio/costo que applyVariantRules fuerza.
        const dbHasVariants = !!(data?.hasVariants)
        if (hasVariants !== dbHasVariants) {
          await api.put(`/v1/items?id=${id}`, { hasVariants })
        }
        targetId = id
      }
      // Persistir el m2m de categorías. El backend reemplaza por completo
      // las categorías del item y sincroniza item.categoryId con la primary.
      // Solo si hay selección — sino el PUT con categories:[] borraría todo.
      if (selectedCategories.length > 0) {
        await updateItemCategories.mutateAsync({
          itemId: targetId,
          categories: selectedCategories,
        })
      }
      // Persistir m2m de marcas.
      if (selectedBrands.length > 0) {
        await updateItemBrands.mutateAsync({
          itemId: targetId,
          brands: selectedBrands,
        })
      }
      // Persistir m2m de etiquetas. Tolerar array vacío: el backend reemplaza
      // (es legítimo querer "borrar todas las etiquetas").
      await updateItemTags.mutateAsync({
        itemId: targetId,
        tags: selectedTags,
      })
      if (isNew) {
        toast.success("Artículo creado")
        router.push(`/items/${targetId}`)
      } else {
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
      <form onSubmit={form.handleSubmit(onSubmit, onInvalid)} className="flex flex-col gap-6">
        <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
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
            {!isNew && kind === "produccion_previa" && (
              <Button variant="outline" size="sm" asChild>
                <Link href={`/produccion?newItemId=${id}`}>
                  <ChefHat className="size-4" />
                  Producir
                </Link>
              </Button>
            )}
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

        <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
          {/* Horizontal scroll en mobile — con 7 tabs, grid-cols-7 dejaría
              cada tab en ~45px y el texto se cortaba. */}
          <div className="-mx-2 overflow-x-auto px-2">
            <TabsList className="w-fit min-w-full justify-start gap-1 sm:gap-0">
              <TabsTrigger value="perfil" className="gap-1.5">
                <User className="size-3.5" />
                Perfil
                {tabsWithErrors.has("perfil") && <TabErrorDot />}
              </TabsTrigger>
              <TabsTrigger value="imagenes" className="gap-1.5" disabled={isNew}>
                <Images className="size-3.5" />
                Imágenes
              </TabsTrigger>
              <TabsTrigger value="config" className="gap-1.5">
                <SettingsIcon className="size-3.5" />
                Configuración
                {tabsWithErrors.has("config") && <TabErrorDot />}
              </TabsTrigger>
              <TabsTrigger value="disponibilidad" className="gap-1.5">
                <Calendar className="size-3.5" />
                Disponibilidad
                {tabsWithErrors.has("disponibilidad") && <TabErrorDot />}
              </TabsTrigger>
              <TabsTrigger value="cotizaciones" className="gap-1.5">
                <Coins className="size-3.5" />
                Cotizaciones
                {tabsWithErrors.has("cotizaciones") && <TabErrorDot />}
              </TabsTrigger>
              <TabsTrigger value="stock" className="gap-1.5" disabled={isNew}>
                <Boxes className="size-3.5" />
                Stock
              </TabsTrigger>
              {/* Con el item todavía sin guardar el tab se muestra DESHABILITADO,
                  no oculto (misma convención que Stock y Producción): las
                  variantes cuelgan de un parentId que aún no existe. Ocultarlo
                  dejaba al usuario activando el switch y sin ninguna pista de
                  dónde cargar las variantes. */}
              {hasVariants && (
                <TabsTrigger value="variantes" className="gap-1.5" disabled={isNew}>
                  <Layers className="size-3.5" />
                  Variantes
                </TabsTrigger>
              )}
              <TabsTrigger value="produccion" className="gap-1.5" disabled={isNew}>
                {kind === "combo_fijo" || kind === "combo_dinamico" || kind === "pack" ? (
                  <>
                    <Package2 className="size-3.5" />
                    Componentes
                  </>
                ) : (
                  <>
                    <ChefHat className="size-3.5" />
                    Producción
                  </>
                )}
                {tabsWithErrors.has("produccion") && <TabErrorDot />}
              </TabsTrigger>
            </TabsList>
          </div>

          <TabsContent value="perfil" className="mt-6">
            <PerfilTab
              form={form}
              visibility={visibility}
              kind={kind}
              itemId={isNew ? "" : id}
              images={(data?.images as ItemImage[] | undefined) ?? []}
              isNew={isNew}
              hasVariants={hasVariants}
              onHasVariantsChange={setHasVariants}
              savedVariantCount={savedVariantCount}
            />
          </TabsContent>
          <TabsContent value="imagenes" className="mt-6">
            <ItemGallery
              itemId={isNew ? "" : id}
              images={(data?.images as ItemImage[] | undefined) ?? []}
              disabled={isNew}
            />
          </TabsContent>
          <TabsContent value="config" className="mt-6">
            <ConfigTab
              form={form}
              visibility={visibility}
              kind={kind}
              selectedCategories={selectedCategories}
              onCategoriesChange={setSelectedCategories}
              selectedBrands={selectedBrands}
              onBrandsChange={setSelectedBrands}
              selectedTags={selectedTags}
              onTagsChange={setSelectedTags}
            />
          </TabsContent>
          <TabsContent value="disponibilidad" className="mt-6">
            <DisponibilidadTab form={form} />
          </TabsContent>
          <TabsContent value="cotizaciones" className="mt-6">
            <CotizacionesTab form={form} />
          </TabsContent>
          <TabsContent value="stock" className="mt-6">
            <StockTab id={id} isNew={isNew} form={form} />
          </TabsContent>
          {hasVariants && !isNew && (
            <TabsContent value="variantes" className="mt-6">
              <VariantsTab parentId={id} />
            </TabsContent>
          )}
          <TabsContent value="produccion" className="mt-6">
            <ProduccionTab form={form} id={id} isNew={isNew} visibility={visibility} kind={kind} />
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
  kind,
  itemId,
  images,
  isNew,
  hasVariants,
  onHasVariantsChange,
  savedVariantCount,
}: {
  form: UseFormReturn<ItemFormValues>
  visibility: KindFieldVisibility
  kind: ItemKind
  itemId: string
  images: ItemImage[]
  isNew: boolean
  hasVariants: boolean
  onHasVariantsChange: (v: boolean) => void
  savedVariantCount: number
}) {
  const { data: bootstrap } = useBootstrap()
  const price = form.watch("price") ?? 0
  const cost = form.watch("cost") ?? 0
  // Cálculos de markup / margen / ganancia.
  const ganancia = price - cost
  const markup = cost > 0 ? ((price - cost) / cost) * 100 : 0
  const margen = price > 0 ? ((price - cost) / price) * 100 : 0

  // La ficha del artículo va SIEMPRE en dos columnas en desktop.
  //
  // Antes el layout dependía de que el tipo tuviera precio Y costo: un "insumo
  // con stock" no vende, así que no muestra precio, y toda la ficha colapsaba a
  // una sola columna. Resultado: "Datos básicos" ocupando el ancho completo con
  // los campos estirados, y el costo empujado tan abajo que había que scrollear
  // para verlo, con media pantalla vacía al costado.
  //
  // Un card con pocos campos al lado de otro no molesta; lo que molesta es una
  // columna de ancho completo con dos campos y el resto del contenido fuera de
  // vista.
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-start">
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">Datos básicos</CardTitle>
          <CardAction>
            <FormField
              control={form.control}
              name="status"
              render={({ field }) => (
                <FormItem className="flex flex-row items-center gap-2 space-y-0">
                  <FormLabel className="cursor-pointer text-xs text-muted-foreground">
                    {field.value ? "Activo" : "Archivado"}
                  </FormLabel>
                  <FormControl>
                    <Switch checked={field.value} onCheckedChange={field.onChange} />
                  </FormControl>
                </FormItem>
              )}
            />
          </CardAction>
        </CardHeader>
        <CardContent className="flex flex-col gap-5">
          {/* Hero: foto + nombre (prominente) + SKU debajo.
              Foto cuadrada un poco más grande para balancear con el nombre,
              y bajada `mt-6` para que el centro quede a la altura del input
              de Nombre (que vive debajo de su label uppercase). */}
          <div className="flex items-start gap-4">
            <ProductPhoto
              itemId={itemId}
              images={images}
              disabled={isNew}
              size={112}
              className="mt-6"
            />
            <div className="flex flex-1 flex-col gap-2.5 pt-1">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem className="space-y-1">
                    <FormLabel className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      Nombre
                    </FormLabel>
                    <FormControl>
                      <Input
                        placeholder="Ej: Café Espresso"
                        className="h-10 text-base font-medium"
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="sku"
                render={({ field }) => (
                  <FormItem className="space-y-1">
                    <FormLabel className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      SKU / Código
                    </FormLabel>
                    <FormControl>
                      <Input
                        placeholder="Código interno"
                        className="h-8 tabular-nums text-sm"
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>
          </div>

          <Separator />

          <FormField
            control={form.control}
            name="kind"
            render={({ field }) => (
              <FormItem className="space-y-1.5">
                <FormLabel className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                  Tipo de artículo
                </FormLabel>
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
                  {(KIND_META[field.value as ItemKind] ?? KIND_META["producto"]).description}
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />

          {/* Duración del pack: solo cuando kind === 'pack'. */}
          {kind === "pack" && (
            <FormField
              control={form.control}
              name="packDurationDays"
              render={({ field }) => (
                <FormItem className="space-y-1.5">
                  <FormLabel className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    Duración (días)
                  </FormLabel>
                  <FormControl>
                    <Input
                      type="number"
                      inputMode="numeric"
                      min={1}
                      placeholder="30"
                      className="h-9 tabular-nums"
                      value={field.value ?? ""}
                      onChange={(e) => {
                        const v = e.target.value
                        field.onChange(v === "" ? null : parseInt(v, 10))
                      }}
                    />
                  </FormControl>
                  <FormDescription className="text-xs">
                    Días desde la venta hasta que el pack vence. Los servicios no
                    consumidos se pierden al vencer.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
          )}

          {/* Sesiones por venta: agenda N citas al vender con cliente. Gated
              por KIND_META (servicio, servicio_sesiones, pack) — no por kind
              directo, para que sumar un kind nuevo no requiera tocar el JSX. */}
          {visibility.showSessions && (
            <FormField
              control={form.control}
              name="itemSessions"
              render={({ field }) => (
                <FormItem className="space-y-1.5">
                  <FormLabel className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    Sesiones por venta
                  </FormLabel>
                  <FormControl>
                    <Input
                      type="number"
                      inputMode="numeric"
                      min={0}
                      placeholder="0"
                      className="h-9 tabular-nums"
                      value={field.value ?? ""}
                      onChange={(e) => {
                        const v = e.target.value
                        field.onChange(v === "" ? null : parseInt(v, 10))
                      }}
                    />
                  </FormControl>
                  <FormDescription className="text-xs">
                    Al venderse con cliente, agenda esta cantidad de citas.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
          )}

          {/* Color de gift card: solo cuando kind === 'giftcard'. La tarjeta
              en el POS usa este color como background. Port del legacy
              a_items.php:608-628 (paleta de 20 colores). */}
          {kind === "giftcard" && (
            <FormField
              control={form.control}
              name="giftcardColor"
              render={({ field }) => (
                <FormItem className="space-y-1.5">
                  <FormLabel className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    Color de la gift card
                  </FormLabel>
                  <FormControl>
                    <div className="flex flex-col gap-3">
                      <div className="grid grid-cols-10 gap-1.5">
                        {GIFTCARD_COLORS.map((c) => {
                          const active = field.value?.toLowerCase() === c.toLowerCase()
                          return (
                            <button
                              key={c}
                              type="button"
                              onClick={() => field.onChange(c)}
                              aria-label={`Color #${c}`}
                              aria-pressed={active}
                              className={cn(
                                "relative aspect-square rounded-full border-2 transition",
                                active
                                  ? "border-foreground ring-2 ring-primary/40"
                                  : "border-transparent hover:scale-110",
                              )}
                              style={{ backgroundColor: `#${c}` }}
                            >
                              {active && (
                                <span className="absolute inset-0 flex items-center justify-center">
                                  <Check className="size-3 text-foreground drop-shadow" />
                                </span>
                              )}
                            </button>
                          )
                        })}
                      </div>
                      <div
                        className="flex h-16 items-center justify-center gap-2 rounded-md text-sm font-medium text-white shadow-inner"
                        style={{ backgroundColor: `#${field.value || DEFAULT_GIFTCARD_COLOR}` }}
                      >
                        <span className="opacity-90">Vista previa de la gift card</span>
                      </div>
                    </div>
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          )}

          <FormField
            control={form.control}
            name="description"
            render={({ field }) => (
              <FormItem className="space-y-1.5">
                <FormLabel className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                  Descripción
                </FormLabel>
                <FormControl>
                  <Textarea
                    rows={3}
                    placeholder="Notas internas o detalles del producto"
                    className="resize-none"
                    {...field}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />

          {kind === "producto" && (
            <>
              <Separator />
              <div className="flex flex-row items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                  <TooltipProvider>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <span className="text-sm font-medium cursor-default">
                          Este item tiene variantes
                        </span>
                      </TooltipTrigger>
                      <TooltipContent className="max-w-60">
                        El item padre no se vende directamente. Las variantes son los
                        productos vendibles (ej. Talle M/L/XL, Color Rojo/Azul).
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                  <p className="text-xs text-muted-foreground">
                    Cuando esta activo, precio y costo se definen por variante.
                  </p>
                  {hasVariants && (
                    <p className="text-xs text-muted-foreground">
                      {isNew
                        ? "Guardá el producto y cargá las variantes desde el tab Variantes."
                        : "Cargá las variantes desde el tab Variantes."}
                    </p>
                  )}
                </div>
                <TooltipProvider>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span>
                        <Switch
                          checked={hasVariants}
                          onCheckedChange={onHasVariantsChange}
                          disabled={savedVariantCount > 0 && hasVariants}
                        />
                      </span>
                    </TooltipTrigger>
                    {savedVariantCount > 0 && hasVariants && (
                      <TooltipContent>
                        Archiva las {savedVariantCount} variantes primero para desactivar.
                      </TooltipContent>
                    )}
                  </Tooltip>
                </TooltipProvider>
              </div>
            </>
          )}
        </CardContent>
      </Card>

      {(visibility.showPrice || visibility.showCost) && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold tracking-tight">Precio y costo</CardTitle>
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
                      <MoneyInput
                        value={field.value}
                        onChange={field.onChange}
                        placeholder="0"
                        className="text-lg font-semibold"
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
                      <MoneyInput
                        value={field.value}
                        onChange={field.onChange}
                        placeholder="0"
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
  selectedCategories,
  onCategoriesChange,
  selectedBrands,
  onBrandsChange,
  selectedTags,
  onTagsChange,
}: {
  form: UseFormReturn<ItemFormValues>
  visibility: KindFieldVisibility
  kind: ItemKind
  selectedCategories: SelectedCategory[]
  onCategoriesChange: (next: SelectedCategory[]) => void
  selectedBrands: SelectedBrand[]
  onBrandsChange: (next: SelectedBrand[]) => void
  selectedTags: string[]
  onTagsChange: (next: string[]) => void
}) {
  const { data: categories } = useTaxonomiesByType("category")
  const { data: brands } = useTaxonomiesByType("brand")
  // Migrado a useTaxes (F0 impuestos multi-país, context/38) — `tax` es la
  // fuente única. El shape difiere (rate/kind numéricos en vez de solo
  // name) pero el render solo usa id/name, sin cambios en el JSX.
  const { data: taxesData } = useTaxes()
  const taxes = taxesData?.taxes ?? []
  const { data: outlets } = useOutlets()
  const { data: tagsData } = useTags()
  const tags = tagsData?.tags ?? []

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      {/* Categorización */}
      {visibility.showCategorization && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold tracking-tight">Categorización</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <FormItem>
              <FormLabel>Categorías</FormLabel>
              <CategoriesPicker
                options={categories.map((c) => ({ id: c.id, name: c.name }))}
                value={selectedCategories}
                onChange={onCategoriesChange}
                placeholder="Sin categoría"
              />
              <FormMessage />
            </FormItem>
            <FormItem>
              <FormLabel>Marcas</FormLabel>
              <BrandsPicker
                options={brands.map((b) => ({ id: b.id, name: b.name }))}
                value={selectedBrands}
                onChange={onBrandsChange}
                placeholder="Sin marca"
              />
              <FormMessage />
            </FormItem>
            <FormItem>
              <FormLabel>Etiquetas</FormLabel>
              <TagsPicker
                options={tags.map((t) => ({ id: t.id, name: t.name }))}
                value={selectedTags}
                onChange={onTagsChange}
                placeholder="Sin etiquetas"
              />
              <FormMessage />
            </FormItem>
            <FormField
              control={form.control}
              name="outletId"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Sucursal</FormLabel>
                  <Select
                    onValueChange={(v) => field.onChange(v === "_all" ? "" : v)}
                    value={field.value || "_all"}
                  >
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Todas las sucursales" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="_all">Todas las sucursales</SelectItem>
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
            <CardTitle className="text-base font-semibold tracking-tight">Impuestos y descuentos</CardTitle>
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
                              {/* El % solo aplica a tasas reales: un impuesto
                                  kind='exempt' con nombre no numérico
                                  ("Exentas") mostraba "Exentas%". */}
                              {t.kind === "exempt" ? t.name : `${t.name}%`}
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
            <CardTitle className="text-base font-semibold tracking-tight">Comportamiento del precio</CardTitle>
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
          <CardTitle className="text-base font-semibold tracking-tight">Otros ajustes</CardTitle>
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
                      max={99}
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
                    % de rendimiento perdido al producir o manipular (ej.: carne
                    con 30% de merma → 1kg crudo rinde 700g útiles).
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

function StockTab({
  id,
  isNew,
  form,
}: {
  id: string
  isNew: boolean
  form: ReturnType<typeof useForm<ItemFormValues>>
}) {
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

  // Se leen del form (no del ítem guardado) para que el semáforo responda
  // mientras se edita el umbral, sin esperar al guardado.
  const minStock = form.watch("minStock")
  const maxStock = form.watch("maxStock")

  return (
    <div className="flex flex-col gap-6">
      <ItemStockTab itemId={id} minStock={minStock} maxStock={maxStock} />

      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">
            Umbrales de stock
          </CardTitle>
          <CardDescription>
            Debajo del mínimo el artículo se marca en el listado como próximo al
            quiebre; por encima del máximo, como sobrestockeado. Dejalos vacíos
            para no controlarlos.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField
            control={form.control}
            name="minStock"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Stock mínimo</FormLabel>
                <FormControl>
                  <Input
                    type="number"
                    min="0"
                    step="any"
                    inputMode="decimal"
                    className="tabular-nums"
                    placeholder="Sin mínimo"
                    value={field.value ?? ""}
                    onChange={(e) =>
                      field.onChange(e.target.value === "" ? null : Number(e.target.value))
                    }
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="maxStock"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Stock máximo</FormLabel>
                <FormControl>
                  <Input
                    type="number"
                    min="0"
                    step="any"
                    inputMode="decimal"
                    className="tabular-nums"
                    placeholder="Sin máximo"
                    value={field.value ?? ""}
                    onChange={(e) =>
                      field.onChange(e.target.value === "" ? null : Number(e.target.value))
                    }
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">Depósitos donde vive este artículo</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <p className="text-xs text-muted-foreground">
            Marcá los depósitos donde se almacena el stock. En cada sucursal,
            elegí el <em>default</em> que se usa al vender o producir si no se
            especifica otro.
          </p>
          <LocationsEditor itemId={id} />
        </CardContent>
      </Card>
    </div>
  )
}

// ── DISPONIBILIDAD TAB ──────────────────────────────────────────────────────

function DisponibilidadTab({ form }: { form: UseFormReturn<ItemFormValues> }) {
  const availability = form.watch("availability")

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">Días y horarios disponibles</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <FormField
          control={form.control}
          name="availability.enabled"
          render={({ field }) => (
            <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
              <div>
                <FormLabel className="text-sm">Limitar disponibilidad</FormLabel>
                <FormDescription className="text-xs">
                  Si está apagado, el ítem se vende todos los días sin restricción
                  horaria. Encendido = solo los días y rangos configurados abajo.
                </FormDescription>
              </div>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />

        {availability?.enabled && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {DAYS.map((day) => (
              <DaySchedule key={day} day={day} form={form} />
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function DaySchedule({
  day,
  form,
}: {
  day: DayOfWeek
  form: UseFormReturn<ItemFormValues>
}) {
  const enabled = form.watch(`availability.days.${day}.enabled`)
  return (
    <div className="flex flex-col gap-2 rounded-md border p-3">
      <div className="flex items-center justify-between">
        <FormLabel className="text-xs font-semibold uppercase tracking-wide">
          {DAY_LABELS[day]}
        </FormLabel>
        <FormField
          control={form.control}
          name={`availability.days.${day}.enabled`}
          render={({ field }) => (
            <Switch checked={field.value} onCheckedChange={field.onChange} />
          )}
        />
      </div>
      {enabled && (
        <div className="flex flex-col gap-2">
          <FormField
            control={form.control}
            name={`availability.days.${day}.from`}
            render={({ field }) => (
              <Input
                type="time"
                className="tabular-nums h-8 text-xs"
                {...field}
              />
            )}
          />
          <FormField
            control={form.control}
            name={`availability.days.${day}.to`}
            render={({ field }) => (
              <Input
                type="time"
                className="tabular-nums h-8 text-xs"
                {...field}
              />
            )}
          />
        </div>
      )}
    </div>
  )
}

// ── COTIZACIONES TAB ────────────────────────────────────────────────────────

function CotizacionesTab({ form }: { form: UseFormReturn<ItemFormValues> }) {
  const { data: currencies, isLoading } = useCurrencies()
  const values = form.watch("currencies")

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">Precio por moneda extranjera</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-xs text-muted-foreground">
          Si vendés a clientes que pagan en moneda extranjera, definí acá el precio
          de venta de este ítem en cada divisa. Cero o vacío = no se ofrece en esa
          moneda. Las tasas de conversión se configuran en Configuración → Monedas.
        </p>

        {isLoading && (
          <div className="flex flex-col gap-2">
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
        )}

        {!isLoading && (!currencies || currencies.length === 0) && (
          <EmptyState
            icon={Coins}
            title="Sin monedas extranjeras configuradas"
            description={
              <>
                Agregalas en <strong>Configuración → Monedas</strong> para que aparezcan acá.
              </>
            }
            showMarquee={false}
            className="border-dashed py-6"
          />
        )}

        {!isLoading && currencies && currencies.length > 0 && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {currencies.map((c, idx) => (
              <div
                key={`${c.ccode}-${c.code}-${idx}`}
                className="flex items-center justify-between gap-3 rounded-md border p-3"
              >
                <div className="flex items-center gap-3 min-w-0">
                  <CountryFlag code={c.ccode} />
                  <div className="flex flex-col min-w-0">
                    <div className="truncate text-sm font-semibold tracking-tight">
                      {countryName(c.ccode)}
                    </div>
                    <div className="text-xs text-muted-foreground tabular-nums">
                      {c.code}
                    </div>
                  </div>
                </div>
                <Input
                  type="number"
                  inputMode="decimal"
                  step="0.01"
                  placeholder="0"
                  value={values?.[c.code] ?? ""}
                  onChange={(e) => {
                    const v = e.target.value
                    form.setValue(`currencies.${c.code}` as never, (v === "" ? 0 : Number(v)) as never, { shouldDirty: true })
                  }}
                  className="tabular-nums w-28 text-right"
                />
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

// ── PRODUCCIÓN TAB ──────────────────────────────────────────────────────────

function ProduccionTab({
  form,
  id,
  isNew,
  visibility,
  kind,
}: {
  form: UseFormReturn<ItemFormValues>
  id: string
  isNew: boolean
  visibility: KindFieldVisibility
  kind: ItemKind
}) {
  if (isNew) {
    return (
      <Card>
        <CardContent className="p-8 text-center text-sm text-muted-foreground">
          Primero creá el artículo. Una vez guardado, podés agregar ingredientes
          (compounds) y el procedimiento si el tipo lo requiere.
        </CardContent>
      </Card>
    )
  }

  // Pack de servicios: usa PackComponentsEditor (tabla pack_component).
  if (kind === "pack") {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">Servicios del pack</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <p className="text-xs text-muted-foreground">
            Agregá los servicios (o productos) que incluye este pack con la cantidad de
            canjes disponibles. El cliente los consume desde el POS dentro del período
            de vigencia configurado en Perfil.
          </p>
          <PackComponentsEditor itemId={id} />
        </CardContent>
      </Card>
    )
  }

  // Combo dinámico: grupos de selección (items específicos o por categoría),
  // min/max por grupo, extraPrice + preselected por item del grupo.
  // NOTA: este branch va ANTES del gate de showCompounds — combo_dinamico
  // tiene showCompounds:false en KIND_META (no usa CompoundsEditor), pero sí
  // necesita su propio editor de grupos.
  if (kind === "combo_dinamico") {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">
            Grupos de selección del combo
          </CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <p className="text-xs text-muted-foreground">
            Cada grupo es una decisión que el cliente toma al armar el combo
            (ej: <em>elegí 1 hamburguesa</em>). Podés definir un mínimo y
            máximo, y el grupo puede ofrecer una lista explícita de items o
            cualquier item de una categoría.
          </p>
          <ComboGroupsEditor itemId={id} />
        </CardContent>
      </Card>
    )
  }

  if (!visibility.showCompounds) {
    return (
      <Card>
        <CardContent className="p-8 text-center text-sm text-muted-foreground">
          Este tipo de artículo no tiene ingredientes ni componentes. Si querés
          agregar una receta o un combo, cambialo a un tipo &quot;Producción&quot;
          o &quot;Combo&quot; en la pestaña Perfil.
        </CardContent>
      </Card>
    )
  }

  // combo_fijo: usa el mismo CompoundsEditor (table parent → child + quantity)
  // pero con copy enfocado en la venta del combo, no en producción.
  if (kind === "combo_fijo") {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">Componentes del combo</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <p className="text-xs text-muted-foreground">
            Items que se entregan al cliente cuando vende este combo. El{" "}
            <strong>precio del combo es fijo</strong> (definido en la pestaña
            Perfil). El costo total se suma del costo de cada componente —
            sirve para calcular margen del combo vs venderlos por separado.
          </p>
          <CompoundsEditor itemId={id} />
        </CardContent>
      </Card>
    )
  }

  // produccion_directa / produccion_previa — receta clásica
  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">Insumos / Receta</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <p className="text-xs text-muted-foreground">
            Materiales que se consumen al{" "}
            <strong>
              {kind === "produccion_directa" ? "vender" : "producir un lote de"}
            </strong>{" "}
            este artículo. El costo total se suma de cantidad × costo unitario
            de cada ingrediente.
          </p>
          <CompoundsEditor itemId={id} />
        </CardContent>
      </Card>

      <FormField
        control={form.control}
        name="procedure"
        render={({ field }) => (
          <Card>
            <CardHeader>
              <CardTitle className="text-base font-semibold tracking-tight">Procedimiento</CardTitle>
            </CardHeader>
            <CardContent>
              <FormItem>
                <FormControl>
                  <Textarea
                    rows={6}
                    placeholder="Procedimiento para la elaboración (opcional)"
                    {...field}
                  />
                </FormControl>
                <FormDescription className="text-xs">
                  Instrucciones paso a paso de cómo preparar este ítem. Visible en el
                  módulo de producción.
                </FormDescription>
                <FormMessage />
              </FormItem>
            </CardContent>
          </Card>
        )}
      />
    </div>
  )
}

// ── HELPERS ─────────────────────────────────────────────────────────────────

function toNum(v: unknown): number | null {
  if (v === null || v === undefined || v === "") return null
  if (typeof v === "boolean") return null
  const n = typeof v === "string" ? parseFloat(v) : (v as number)
  return Number.isFinite(n) ? n : null
}

/**
 * Coerce defensivo de unknown → string. El backend devuelve `false` (bool PHP)
 * en campos de texto null/vacíos del JSONB legacy. `?? ""` deja pasar `false`
 * y rompe los inputs. toStr atrapa esos casos.
 */
function toStr(v: unknown): string {
  if (typeof v === "string") {
    // Edge case: el backend a veces guarda el LITERAL string "false" en SKU
    // o UOM cuando se serializó mal. Lo tratamos como vacío.
    return v === "false" ? "" : v
  }
  return ""
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
    minStock: null,
    maxStock: null,
    sort: 99999,
    commission: null,
    commissionType: "percent",
    pricePercent: null,
    priceType: "fixed",
    ecom: false,
    featured: false,
    procedure: "",
    availability: defaultAvailability(),
    currencies: {},
    validFrom: null,
    validUntil: null,
    minDaysBetweenSessions: null,
    giftcardColor: DEFAULT_GIFTCARD_COLOR,
    packDurationDays: 30,
    itemSessions: null,
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

// ── Helpers de país/moneda ─────────────────────────────────────────────────
//
// Mismos helpers que viven inline en /settings (CountryFlag). Acá repetidos
// porque el módulo de Items no debe depender del page de Settings — si en el
// futuro aparece un tercer consumer, mover ambos a `lib/country.tsx`.

function CountryFlag({ code }: { code: string }) {
  const flag = code
    ? code
        .toUpperCase()
        .replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0)))
    : "🌐"
  return <span className="text-2xl leading-none">{flag}</span>
}

/**
 * Nombre del país en español desde el ccode ISO 3166-1 alpha-2 — usando la
 * API nativa Intl.DisplayNames (sin dependencias). Fallback al ccode si el
 * runtime no lo soporta o el código es desconocido.
 *
 * Crítico para diferenciar las múltiples monedas con el mismo ISO 4217:
 * USD lo usan US/EC/PA/SV/etc. — sin nombre de país, son indistinguibles.
 */
function countryName(ccode: string): string {
  if (!ccode) return ""
  try {
    const dn = new Intl.DisplayNames(["es"], { type: "region" })
    return dn.of(ccode.toUpperCase()) || ccode
  } catch {
    return ccode
  }
}
