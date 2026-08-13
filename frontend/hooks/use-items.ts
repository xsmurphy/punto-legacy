"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import {
  kindToBackendFields,
  defaultAvailability,
  type ComboGroup,
  type ItemAvailability,
  type ItemCompound,
  type ItemFormValues,
  type ItemFull,
  type ItemImage,
  type ItemListItem,
  type ItemLocation,
  type PackComponent,
  type Taxonomy,
} from "@/lib/types/item"

export function useItems(opts?: { q?: string; archived?: boolean; parentId?: string; includeVariants?: boolean }) {
  return useQuery<{
    items: ItemListItem[]
    total: number
    limit: number
    offset: number
  }>({
    queryKey: ["items", opts?.q ?? "", opts?.archived ?? false, opts?.parentId ?? "", opts?.includeVariants ?? false],
    queryFn: () => {
      const params = new URLSearchParams({ limit: "200" })
      if (opts?.q) params.set("q", opts.q)
      if (opts?.archived) params.set("archived", "1")
      // parentId vacío → top-level (default). Con id explícito → hijos del grupo.
      if (opts?.parentId) params.set("parentId", opts.parentId)
      if (opts?.includeVariants) params.set("includeVariants", "true")
      return api.get(`/v1/items?${params.toString()}`)
    },
    staleTime: 30 * 1000,
  })
}

/** Agrupa N items bajo un nuevo grupo. Devuelve el groupId nuevo. */
export function useGroupItems() {
  const qc = useQueryClient()
  return useMutation<
    { groupId: string; childCount: number },
    Error,
    { itemIds: string[]; groupName: string }
  >({
    mutationFn: ({ itemIds, groupName }) =>
      api.post("/v1/items?resource=group", { itemIds, groupName }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["items"] }),
  })
}

/** Desagrupa: limpia itemParentId de los hijos del grupo y archiva el grupo. */
export function useUngroupItems() {
  const qc = useQueryClient()
  return useMutation<{ ungrouped: number }, Error, string>({
    mutationFn: (groupId) => api.post(`/v1/items?id=${groupId}&resource=ungroup`, {}),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["items"] }),
  })
}

/**
 * Edición masiva: aplica el mismo patch a N items.
 * `priceAdjustPercent` (opcional) recalcula itemPrice por item (precio × (1+p/100)).
 * Si un item es grupo, el backend propaga el patch a sus hijos automáticamente.
 */
export interface BulkEditPatch {
  itemPrice?: number | null
  taxId?: string | null
  categoryId?: string | null
  brandId?: string | null
  outletId?: string | null
  itemDiscount?: number | null
  itemUOM?: string | null
  itemWaste?: number | null
  itemComissionPercent?: number | null
  itemComissionType?: "0" | "1" | null
  itemPricePercent?: number | null
  itemPriceType?: 0 | 1 | null
  itemSessions?: number | null
  itemDuration?: number | null
  itemEcom?: 0 | 1 | null
  itemFeatured?: 0 | 1 | null
  kind?: string | null
}

export interface BulkEditReport {
  ok: boolean
  updated: number
  skipped: number
  errors: Array<{ itemId: string; reason: string }>
}

export function useBulkEditItems() {
  const qc = useQueryClient()
  return useMutation<
    BulkEditReport,
    Error,
    { itemIds: string[]; patch: BulkEditPatch; priceAdjustPercent?: number | null }
  >({
    mutationFn: ({ itemIds, patch, priceAdjustPercent }) =>
      api.post("/v1/items?resource=bulk-edit", {
        itemIds,
        patch,
        ...(priceAdjustPercent != null ? { priceAdjustPercent } : {}),
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["items"], refetchType: "active" }),
  })
}

/**
 * Renombrar grupo (o cualquier item) — PATCH parcial con solo itemName.
 * Reusa el PUT /v1/items?id=X que ya acepta cualquier subset de campos.
 */
export function useRenameItem() {
  const qc = useQueryClient()
  return useMutation<ItemFull, Error, { itemId: string; name: string }>({
    mutationFn: ({ itemId, name }) =>
      api.put<ItemFull>(`/v1/items?id=${itemId}`, { itemName: name }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["items", itemId] })
    },
  })
}

export function useItem(id: string | undefined) {
  return useQuery<ItemFull>({
    queryKey: ["items", id],
    queryFn: () => api.get<ItemFull>(`/v1/items?id=${id}`),
    enabled: !!id,
    staleTime: 30 * 1000,
  })
}

/**
 * Sync del m2m item_category. Reemplaza COMPLETAMENTE las categorías del
 * item con las pasadas (backend hace DELETE + INSERT). El backend mantiene
 * `item.categoryId` (legacy 1:1) en sync con la categoría marcada como
 * isPrimary — los reportes viejos siguen funcionando.
 */
export function useUpdateItemCategories() {
  const qc = useQueryClient()
  return useMutation<
    { updated: boolean; categories: { id: string; name: string; isPrimary: boolean }[] },
    Error,
    { itemId: string; categories: { id: string; isPrimary: boolean }[] }
  >({
    mutationFn: ({ itemId, categories }) =>
      api.put(`/v1/items?id=${itemId}&resource=categories`, {
        categories,
      }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId] })
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

/**
 * Reemplaza el set de marcas (item_brand m2m) del item. El backend mantiene
 * `item.brandId` (legacy 1:1) en sync con la marca isPrimary.
 */
export function useUpdateItemBrands() {
  const qc = useQueryClient()
  return useMutation<
    { updated: boolean; brands: { id: string; name: string; isPrimary: boolean }[] },
    Error,
    { itemId: string; brands: { id: string; isPrimary: boolean }[] }
  >({
    mutationFn: ({ itemId, brands }) =>
      api.put(`/v1/items?id=${itemId}&resource=brands`, { brands }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId] })
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

/**
 * Reemplaza el set de etiquetas (item_tag m2m) del item. El backend mantiene
 * `data.tags` JSONB en sync para consumers legacy.
 */
export function useUpdateItemTags() {
  const qc = useQueryClient()
  return useMutation<
    { updated: boolean; tags: { id: string; name: string }[] },
    Error,
    { itemId: string; tags: string[] }
  >({
    mutationFn: ({ itemId, tags }) =>
      api.put(`/v1/items?id=${itemId}&resource=tags`, { tags }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId] })
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

export function useCreateItem() {
  const qc = useQueryClient()
  return useMutation<ItemFull, Error, ItemFormValues>({
    mutationFn: (values) => {
      const flags = kindToBackendFields(values.kind)
      return api.post<ItemFull>("/v1/items", {
        kind: values.kind,
        ...serialize(values, flags),
      })
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

export function useUpdateItem() {
  const qc = useQueryClient()
  return useMutation<ItemFull, Error, { id: string; values: ItemFormValues }>({
    mutationFn: ({ id, values }) => {
      const flags = kindToBackendFields(values.kind)
      return api.put<ItemFull>(`/v1/items?id=${id}`, {
        kind: values.kind,
        ...serialize(values, flags),
      })
    },
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["items", vars.id] })
    },
  })
}

export function useArchiveItem() {
  const qc = useQueryClient()
  return useMutation<{ archived: boolean; itemId: string }, Error, string>({
    mutationFn: (id) => api.del(`/v1/items?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

/** Hard-delete: solo para items archivados (itemStatus=0) sin ventas asociadas.
 *  El backend responde 409 si el item tiene ventas — el caller debe manejar ese caso. */
export function useDeleteItem() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean; itemId: string }, Error, string>({
    mutationFn: (id) => api.del(`/v1/items?id=${id}&hard=1`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

export interface ImportReport {
  created: number
  updated: number
  total: number
  errors: { line: number; message: string }[]
}

export function useImportItems() {
  const qc = useQueryClient()
  return useMutation<ImportReport, Error, { file: File; mode: "insert" | "update" }>({
    mutationFn: ({ file, mode }) => {
      const form = new FormData()
      form.append("csv", file)
      form.append("mode", mode)
      return api.postForm<ImportReport>("/v1/items?resource=import", form)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

/** URL absoluta para descargar la plantilla CSV. */
export function importTemplateUrl(): string {
  return api.url("/v1/items?resource=template")
}

// ── Recetas / Compuestos ───────────────────────────────────────────────────

export function useItemCompounds(itemId: string | undefined) {
  return useQuery<{ compounds: ItemCompound[] }>({
    queryKey: ["items", itemId, "compounds"],
    queryFn: () => api.get(`/v1/items?id=${itemId}&resource=compounds`),
    enabled: !!itemId,
    staleTime: 30 * 1000,
  })
}

export function useAddCompound() {
  const qc = useQueryClient()
  return useMutation<
    { compoundId: string; compounds: ItemCompound[] },
    Error,
    { itemId: string; childItemId: string; quantity: number }
  >({
    mutationFn: ({ itemId, childItemId, quantity }) =>
      api.post(`/v1/items?id=${itemId}&resource=compounds`, { childItemId, quantity }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId, "compounds"] })
    },
  })
}

export function useUpdateCompoundQuantity() {
  const qc = useQueryClient()
  return useMutation<
    { compounds: ItemCompound[] },
    Error,
    { itemId: string; compoundId: string; quantity: number }
  >({
    mutationFn: ({ itemId, compoundId, quantity }) =>
      api.put(`/v1/items?id=${itemId}&resource=compounds`, { compoundId, quantity }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId, "compounds"] })
    },
  })
}

export function useDeleteCompound() {
  const qc = useQueryClient()
  return useMutation<
    { deleted: boolean; compounds: ItemCompound[] },
    Error,
    { itemId: string; compoundId: string }
  >({
    mutationFn: ({ itemId, compoundId }) =>
      api.del(`/v1/items?id=${itemId}&resource=compounds&compoundId=${compoundId}`),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId, "compounds"] })
    },
  })
}

// ── Depósitos (locations) ──────────────────────────────────────────────────

export function useItemLocations(itemId: string | undefined) {
  return useQuery<{ locations: ItemLocation[] }>({
    queryKey: ["items", itemId, "locations"],
    queryFn: () => {
      // Backend devuelve raw rows con `locationid` lowercase desde PG. Normalizamos.
      return api.get<{ locations: Array<Record<string, unknown>> }>(
        `/v1/items?id=${itemId}&resource=locations`,
      ).then((r) => ({
        locations: r.locations.map((row) => ({
          locationId:   String(row.locationid ?? row.locationId ?? ""),
          outletId:     String(row.outletid   ?? row.outletId   ?? ""),
          isDefault:    Boolean(row.isdefault ?? row.isDefault ?? false),
          taxonomyName: String(row.taxonomyname ?? row.taxonomyName ?? ""),
          outletName:   (row.outletname ?? row.outletName ?? null) as string | null,
        })),
      }))
    },
    enabled: !!itemId,
    staleTime: 30 * 1000,
  })
}

// ── Combo dinámico — grupos ────────────────────────────────────────────────

export function useComboGroups(itemId: string | undefined) {
  return useQuery<{ groups: ComboGroup[] }>({
    queryKey: ["items", itemId, "combo-groups"],
    queryFn: () => api.get(`/v1/items?id=${itemId}&resource=combo-groups`),
    enabled: !!itemId,
    staleTime: 30 * 1000,
  })
}

export interface ComboGroupInput {
  name: string
  sourceType: "items" | "category"
  sourceCategoryId?: string | null
  minSelection: number
  maxSelection: number
  items?: Array<{
    childItemId: string
    extraPrice: number
    isPreselected: boolean
    sort?: number
  }>
}

export function useCreateComboGroup() {
  const qc = useQueryClient()
  return useMutation<
    { groupId: string; groups: ComboGroup[] },
    Error,
    { itemId: string; input: ComboGroupInput }
  >({
    mutationFn: ({ itemId, input }) =>
      api.post(
        `/v1/items?id=${itemId}&resource=combo-groups`,
        input as unknown as Record<string, unknown>,
      ),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId, "combo-groups"] })
    },
  })
}

export function useUpdateComboGroup() {
  const qc = useQueryClient()
  return useMutation<
    { groups: ComboGroup[] },
    Error,
    { itemId: string; groupId: string; input: Partial<ComboGroupInput> }
  >({
    mutationFn: ({ itemId, groupId, input }) =>
      api.put(
        `/v1/items?id=${itemId}&resource=combo-groups&groupId=${groupId}`,
        input as unknown as Record<string, unknown>,
      ),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId, "combo-groups"] })
    },
  })
}

export function useDeleteComboGroup() {
  const qc = useQueryClient()
  return useMutation<
    { deleted: boolean; groups: ComboGroup[] },
    Error,
    { itemId: string; groupId: string }
  >({
    mutationFn: ({ itemId, groupId }) =>
      api.del(`/v1/items?id=${itemId}&resource=combo-groups&groupId=${groupId}`),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId, "combo-groups"] })
    },
  })
}

export function useSyncItemLocations() {
  const qc = useQueryClient()
  return useMutation<
    { updated: boolean; count: number },
    Error,
    { itemId: string; locationIds: string[]; defaultLocationId: string | null }
  >({
    mutationFn: ({ itemId, locationIds, defaultLocationId }) =>
      api.put(`/v1/items?id=${itemId}&resource=locations`, {
        locationIds,
        default: defaultLocationId,
      }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId, "locations"] })
    },
  })
}

// ── Galería de imágenes ────────────────────────────────────────────────────

export function useUploadItemImage() {
  const qc = useQueryClient()
  return useMutation<{ image: ItemImage }, Error, { itemId: string; file: File }>({
    mutationFn: ({ itemId, file }) => {
      const form = new FormData()
      form.append("image", file)
      return api.postForm<{ image: ItemImage }>(
        `/v1/items?id=${itemId}&resource=images`,
        form,
      )
    },
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId] })
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

export function useDeleteItemImage() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, { itemId: string; imageId: string }>({
    mutationFn: ({ itemId, imageId }) =>
      api.del(`/v1/items?id=${itemId}&resource=images&imageId=${imageId}`),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId] })
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

export function useReorderItemImages() {
  const qc = useQueryClient()
  return useMutation<{ images: ItemImage[] }, Error, { itemId: string; order: string[] }>({
    mutationFn: ({ itemId, order }) =>
      api.put<{ images: ItemImage[] }>(`/v1/items?id=${itemId}&resource=images`, { order }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId] })
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

/**
 * Reemplaza la portada del item (image[0]). Si ya había una, la borra; sube la
 * nueva; si quedan otras, reordena para que la nueva sea primera.
 * Pensado para el ProductPhoto del perfil (un solo slot circular).
 */
export function useReplaceCoverImage() {
  const qc = useQueryClient()
  return useMutation<ItemImage, Error, { itemId: string; file: File; current: ItemImage[] }>({
    mutationFn: async ({ itemId, file, current }) => {
      const sorted = [...current].sort((a, b) => a.sort - b.sort)
      const cover = sorted[0]
      if (cover) {
        await api.del(`/v1/items?id=${itemId}&resource=images&imageId=${cover.imageId}`)
      }
      const form = new FormData()
      form.append("image", file)
      const res = await api.postForm<{ image: ItemImage }>(
        `/v1/items?id=${itemId}&resource=images`,
        form,
      )
      const newImg = res.image
      const others = sorted.slice(1).map((i) => i.imageId)
      if (others.length > 0) {
        await api.put<{ images: ItemImage[] }>(
          `/v1/items?id=${itemId}&resource=images`,
          { order: [newImg.imageId, ...others] },
        )
      }
      return newImg
    },
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["items", itemId] })
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

export function useTaxonomies() {
  return useQuery<{ taxonomies: Taxonomy[] }>({
    queryKey: ["taxonomies"],
    queryFn: () => api.get("/v1/taxonomies"),
    staleTime: 5 * 60 * 1000,
  })
}

export function useTaxonomiesByType(type: string) {
  const { data, ...rest } = useTaxonomies()
  return {
    ...rest,
    data: (data?.taxonomies ?? []).filter((t) => t.type === type),
  }
}

/** Lista de monedas extranjeras del tenant con sus tasas.
 *
 *  `ccode` = país ISO 3166-1 alpha-2 (PY/AR/US/EC/...) — necesario para
 *  diferenciar las múltiples monedas con el mismo ISO 4217 (USD lo usan
 *  Estados Unidos, Ecuador, Panamá, El Salvador, etc.) y para pintar la
 *  bandera + nombre de país en la UI.
 *
 *  `code` = ISO 4217 de la moneda (USD/EUR/PYG/ARS/...).
 *
 *  `value` = tasa al PYG.
 */
export interface CurrencyEntry {
  ccode: string
  code: string
  value?: number
  // Campos opcionales legacy — algunos endpoints históricos los incluían;
  // se preservan para no romper consumers que los lean.
  country?: string
  rate?: number
  symbol?: string
  name?: string
}
export function useCurrencies() {
  return useQuery<CurrencyEntry[]>({
    queryKey: ["currencies"],
    queryFn: () => api.get("/v1/currencies"),
    staleTime: 5 * 60 * 1000,
  })
}

// ── helpers ────────────────────────────────────────────────────────────────

function serialize(
  values: ItemFormValues,
  flags: ReturnType<typeof kindToBackendFields>,
): Record<string, unknown> {
  return {
    itemName: values.name,
    itemSKU: values.sku,
    itemDescription: values.description,
    itemPrice: values.price,
    itemCost: values.cost,
    itemDiscount: values.discount,
    itemUOM: values.uom,
    itemTaxIncluded: values.taxIncluded ? 1 : 0,
    itemStatus: values.status ? 1 : 0,
    taxId: values.taxId || null,
    categoryId: values.categoryId || null,
    brandId: values.brandId || null,
    // Flags derivados del kind — NO los toca el usuario directamente.
    itemType: flags.itemType,
    itemCanSale: flags.itemCanSale,
    itemTrackInventory: flags.itemTrackInventory,
    itemProduction: flags.itemProduction,
    // Configuraciones extendidas — algunas son columnas (outletId, itemSort,
    // supplierId), el resto rutea al JSONB `data` automáticamente porque no
    // están en el _getTableSchema['item'] whitelist tras migration 07.
    outletId: values.outletId || null,
    supplierId: values.supplierId || null,
    itemSort: values.sort ?? 99999,
    itemWaste: values.waste ?? 0,
    // null viaja como null: es "sin umbral", no 0.
    itemMinStock: values.minStock,
    itemMaxStock: values.maxStock,
    itemComissionPercent: values.commission ?? 0,
    itemComissionType: values.commissionType === "percent" ? "0" : "1",
    itemPricePercent: values.pricePercent ?? 0,
    itemPriceType: values.priceType === "percent" ? 1 : 0,
    itemEcom: values.ecom ? 1 : 0,
    itemFeatured: values.featured ? 1 : 0,
    itemProcedure: values.procedure,
    // itemDateHour: serializa la availability como objeto JSONB. Si availability
    // está deshabilitada, mandamos null para limpiar.
    itemDateHour: values.availability.enabled
      ? JSON.stringify(values.availability)
      : null,
    // itemCurrencies: mapa { codigoMoneda: precio }. Solo guardamos entradas
    // con precio > 0 para no inflar el JSONB con ceros.
    itemCurrencies: JSON.stringify(
      Object.fromEntries(
        Object.entries(values.currencies).filter(([, v]) => v && Number(v) > 0),
      ),
    ),
    // Campos nuevos Slice A — van a JSONB automáticamente.
    // Keys SIN prefijo "item": la hidratación del form lee data.validFrom /
    // data.validUntil / data.minDaysBetweenSessions (items/[id]/page.tsx
    // ~322-326). Antes se escribían como itemValidFrom/itemValidUntil/
    // itemMinDaysBetweenSessions — keys JSONB distintas que la lectura nunca
    // veía: el campo "persistía" en el PUT y aparecía vacío al recargar
    // (bug silencioso, mismo patrón hasVariants/pinhash). Lo guardado bajo
    // las keys viejas nunca se mostró, así que no hay regresión visible al
    // abandonarlas.
    validFrom: values.validFrom ?? null,
    validUntil: values.validUntil ?? null,
    minDaysBetweenSessions: values.minDaysBetweenSessions ?? null,
    // Solo aplica a giftcard — para los otros kinds queda en JSONB sin uso.
    itemGiftcardColor: values.kind === "giftcard" ? values.giftcardColor : null,
    // Solo aplica a pack — días hasta vencimiento de cada instancia vendida.
    packDurationDays: values.kind === "pack" ? (values.packDurationDays ?? 30) : null,
    // itemSessions: cantidad de citas que SaleService agenda al vender el item
    // con cliente (persistScheduledSessions). La key JSONB real es
    // `itemSessions` (ver functions.php:150 y SaleService.php).
    itemSessions: values.itemSessions ?? null,
  }
}

export function parseAvailability(raw: unknown): ItemAvailability {
  if (typeof raw === "string") {
    try {
      const parsed = JSON.parse(raw)
      if (parsed && typeof parsed === "object" && "days" in parsed) {
        return parsed as ItemAvailability
      }
    } catch {
      /* fallthrough */
    }
  }
  if (raw && typeof raw === "object" && "days" in (raw as Record<string, unknown>)) {
    return raw as ItemAvailability
  }
  return defaultAvailability()
}

export function parseCurrencies(raw: unknown): Record<string, number> {
  if (typeof raw === "string") {
    try {
      const parsed = JSON.parse(raw)
      if (parsed && typeof parsed === "object") {
        return Object.fromEntries(
          Object.entries(parsed as Record<string, unknown>).map(([k, v]) => [
            k,
            typeof v === "number" ? v : Number(v) || 0,
          ]),
        )
      }
    } catch {
      /* fallthrough */
    }
  }
  if (raw && typeof raw === "object") {
    return Object.fromEntries(
      Object.entries(raw as Record<string, unknown>).map(([k, v]) => [
        k,
        typeof v === "number" ? v : Number(v) || 0,
      ]),
    )
  }
  return {}
}

// ── Pack components ────────────────────────────────────────────────────────

/**
 * Lista los componentes de un pack (GET /v1/pack_component?packItemId=<id>).
 * Solo activo cuando el item es de tipo "pack".
 */
export function usePackComponents(packItemId: string | undefined) {
  return useQuery<PackComponent[]>({
    queryKey: ["pack-components", packItemId],
    queryFn: () => api.get<PackComponent[]>(`/v1/pack_component?packItemId=${packItemId}`),
    enabled: !!packItemId,
    staleTime: 30 * 1000,
  })
}

/**
 * Reemplaza todos los componentes de un pack (PUT /v1/pack_component?packItemId=<id>).
 * Bulk replace: elimina los existentes e inserta los nuevos.
 */
export function useSetPackComponents() {
  const qc = useQueryClient()
  return useMutation<
    PackComponent[],
    Error,
    { packItemId: string; components: Omit<PackComponent, "packComponentId">[] }
  >({
    mutationFn: ({ packItemId, components }) =>
      api.put<PackComponent[]>(`/v1/pack_component?packItemId=${packItemId}`, { components }),
    onSuccess: (_, { packItemId }) => {
      qc.invalidateQueries({ queryKey: ["pack-components", packItemId] })
    },
  })
}
