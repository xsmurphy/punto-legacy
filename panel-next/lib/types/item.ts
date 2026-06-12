/**
 * Shapes de `/v1/items` — Slice A del refactor de Items.
 *
 * `kind` es la verdad canónica. El backend expone `item.itemKind` como `kind`.
 * Los flags legacy (itemType, itemCanSale, etc.) se mantienen en el response
 * para compat con código legacy hasta Slice E.
 *
 * Slice D reescribirá los form components por kind. Por ahora el form genérico
 * sigue usando `KindFieldVisibility`/`fields`, `kindToBackendFields`, y `inferKind`.
 */

export type ItemKind =
  | "producto"
  | "insumo_stock"
  | "insumo_sin_stock"
  | "insumo_control"
  | "produccion_directa"
  | "produccion_previa"
  | "servicio"
  | "servicio_sesiones"
  | "combo_fijo"
  | "combo_dinamico"
  | "descuento"
  | "giftcard"

export interface ItemCategory {
  id: string
  name: string
  isPrimary: boolean
}

/** Item dentro de un grupo de combo dinámico (sourceType='items'). */
export interface ComboGroupItem {
  groupItemId: string
  childItemId: string
  extraPrice: number
  isPreselected: boolean
  sort: number
  /** Datos del item resueltos via JOIN. */
  childName: string | null
  childSKU: string | null
  childPrice: number
  childUOM: string | null
  childKind: ItemKind | null
}

/** Grupo de selección de un combo dinámico. */
export interface ComboGroup {
  groupId: string
  name: string
  /** 'items' = lista explícita | 'category' = todos los items de una categoría. */
  sourceType: "items" | "category"
  sourceCategoryId: string | null
  sourceCategoryName: string | null
  minSelection: number
  maxSelection: number
  sort: number
  /** Solo cuando sourceType='items' — vacío si es 'category'. */
  items: ComboGroupItem[]
}

/** Regla de precio del combo dinámico. */
export type ComboPriceRule = "topPrice" | "lowPrice" | "average" | null

/** Compound / receta — ingrediente de un item de producción. */
export interface ItemCompound {
  compoundId: string
  parentItemId: string
  childItemId: string
  quantity: number
  sort: number
  /** Datos del child resueltos via JOIN — listos para mostrar sin lookup extra. */
  childName: string | null
  childSKU: string | null
  childUOM: string | null
  childCost: number
  childKind: ItemKind | null
  lineCost: number
}

/** Shape del listado — GET /v1/items */
export interface ItemListItem {
  itemId: string
  itemName: string
  itemSKU: string | null
  /** Kind canónico — viene directo del backend (itemKind column). */
  kind: ItemKind
  /** itemType legacy — se mantiene para compat. Preferir `kind`. */
  itemType: string | null
  itemStatus: number
  itemPrice: number | string | null
  itemCost: number | string | null
  itemDate: string | null
  updated_at: string | null
  categoryId: string | null
  brandId: string | null
  outletId: string | null
  /** Nombres resueltos por JOIN en la API — para mostrar sin lookup adicional. */
  categoryName: string | null
  brandName: string | null
  outletName: string | null
  /** URL de la imagen de portada (sort=0). Null si no hay galería. */
  coverImageUrl: string | null
  /** True si este item es un grupo (itemIsParent). */
  itemIsParent?: boolean | number | null
  /** Si es hijo de un grupo, apunta al grupo padre. */
  itemParentId?: string | null
  /** Cuántos hijos activos tiene este grupo. 0 si no es grupo. */
  childCount?: number
  /** Campos JSONB flattened — disponibles pero no tipeados explícitamente.
   * Incluyen itemUOM, itemDiscount, itemWaste, itemComissionPercent, itemEcom, etc. */
  itemUOM?: string | null
  itemDiscount?: number | string | null
  itemEcom?: number | boolean | null
  tags: string[]
}

/** Imagen de la galería de un item. */
export interface ItemImage {
  imageId: string
  url: string
  objectKey: string
  width: number | null
  height: number | null
  sizeBytes: number | null
  mime: string
  sort: number
}

/** Shape del detalle — GET /v1/items?id=... */
export interface ItemFull extends ItemListItem {
  itemCost: number | string | null
  itemUOM: string | null
  itemTaxExcluded: number | string | null
  itemDiscount: number | string | null
  itemTrackInventory: number | boolean | null
  itemCanSale: number | boolean | null
  itemProduction: number | boolean | null
  itemTaxIncluded: number | boolean | null
  taxId: string | null
  brandId: string | null
  categoryId: string | null
  supplierId: string | null
  locationId: string | null
  outletId: string | null
  itemDescription: string | null
  /** Categorías m2m — disponibles en el detalle, vacío en el listado. */
  categories: ItemCategory[]
  /** Galería de imágenes (0..5). Solo presente en el detalle. */
  images: ItemImage[]
  /** Campos JSONB flattened — disponibles pero no tipeados explícitamente.
   * Slice D los tipará por kind cuando los forms sean dedicados. */
  [key: string]: unknown
}

// 20 colores de paleta para gift cards. Hex sin '#'. Port FIEL del legacy
// panel/a_items.php:609-628. Default 'E0E0E0' (gris claro).
export const GIFTCARD_COLORS = [
  "e57373", "F06292", "BA68C8", "9575CD", "7986CB",
  "64B5F6", "4FC3F7", "4DD0E1", "4DB6AC", "81C784",
  "AED581", "DCE775", "FFF176", "FFD54F", "FFB74D",
  "FF8A65", "A1887F", "E0E0E0", "90A4AE", "ef5350",
] as const
export const DEFAULT_GIFTCARD_COLOR = "E0E0E0"

export interface ItemFormValues {
  kind: ItemKind
  name: string
  sku: string
  description: string
  price: number | null
  cost: number | null
  discount: number | null
  taxId: string
  taxIncluded: boolean
  uom: string
  /** Categoría principal — legacy single-category compat. Slice D moverá a m2m editor. */
  categoryId: string
  brandId: string
  status: boolean

  outletId: string
  supplierId: string
  waste: number | null
  sort: number | null
  commission: number | null
  commissionType: "percent" | "fixed"
  pricePercent: number | null
  priceType: "fixed" | "percent"
  ecom: boolean
  featured: boolean
  procedure: string

  availability: ItemAvailability
  currencies: Record<string, number>

  /** Vigencia del item — fecha desde/hasta (JSONB). Null = sin restricción. */
  validFrom: string | null
  validUntil: string | null

  /** Para servicio_sesiones — días mínimos entre sesiones. Null = sin restricción. */
  minDaysBetweenSessions: number | null

  /** Para giftcard — color hex sin '#' del fondo de la tarjeta. */
  giftcardColor: string
}

export interface ItemAvailability {
  enabled: boolean
  days: Record<DayOfWeek, { enabled: boolean; from: string; to: string }>
}

export type DayOfWeek = "lun" | "mar" | "mie" | "jue" | "vie" | "sab" | "dom"

export const DAY_LABELS: Record<DayOfWeek, string> = {
  lun: "Lun",
  mar: "Mar",
  mie: "Mié",
  jue: "Jue",
  vie: "Vie",
  sab: "Sáb",
  dom: "Dom",
}

export const DAYS: DayOfWeek[] = ["lun", "mar", "mie", "jue", "vie", "sab", "dom"]

export function defaultAvailability(): ItemAvailability {
  return {
    enabled: false,
    days: Object.fromEntries(
      DAYS.map((d) => [d, { enabled: true, from: "08:00", to: "20:00" }]),
    ) as ItemAvailability["days"],
  }
}

// ── Kind metadata ─────────────────────────────────────────────────────────

/** Control de visibilidad de secciones en el form genérico (Slice D lo reemplaza). */
export interface KindFieldVisibility {
  showPrice: boolean
  showCost: boolean
  showTax: boolean
  showDiscount: boolean
  showInventoryInfo: boolean
  showCategorization: boolean
  showUOM: boolean
  showCompounds: boolean
}

export interface KindMeta {
  label: string
  description: string
  group: "Items de venta" | "Insumos" | "Producción" | "Otros"
  backend: {
    itemType: string
    itemCanSale: 0 | 1
    itemTrackInventory: 0 | 1
    itemProduction: 0 | 1
  }
  fields: KindFieldVisibility
}

export const KIND_META: Record<ItemKind, KindMeta> = {
  producto: {
    label: "Producto",
    description: "Artículo físico vendible con stock.",
    group: "Items de venta",
    backend: { itemType: "product", itemCanSale: 1, itemTrackInventory: 1, itemProduction: 0 },
    fields: {
      showPrice: true, showCost: true, showTax: true, showDiscount: true,
      showInventoryInfo: true, showCategorization: true, showUOM: true, showCompounds: false,
    },
  },
  servicio: {
    label: "Servicio",
    description: "Servicio cobrado al cliente sin stock.",
    group: "Items de venta",
    backend: { itemType: "product", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: true, showCost: false, showTax: true, showDiscount: true,
      showInventoryInfo: false, showCategorization: true, showUOM: true, showCompounds: false,
    },
  },
  servicio_sesiones: {
    label: "Pack de sesiones",
    description: "N sesiones vendidas en bloque. Cada sesión se consume desde Citas.",
    group: "Items de venta",
    backend: { itemType: "product", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: true, showCost: false, showTax: true, showDiscount: true,
      showInventoryInfo: false, showCategorization: true, showUOM: false, showCompounds: false,
    },
  },
  insumo_stock: {
    label: "Insumo con stock",
    description: "Materia prima con inventario. Apto como ingrediente en recetas.",
    group: "Insumos",
    backend: { itemType: "product", itemCanSale: 0, itemTrackInventory: 1, itemProduction: 0 },
    fields: {
      showPrice: false, showCost: true, showTax: false, showDiscount: false,
      showInventoryInfo: true, showCategorization: true, showUOM: true, showCompounds: false,
    },
  },
  insumo_sin_stock: {
    label: "Insumo sin stock",
    description: "Insumo sin control de inventario. Apto como ingrediente.",
    group: "Insumos",
    backend: { itemType: "product", itemCanSale: 0, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: false, showCost: true, showTax: false, showDiscount: false,
      showInventoryInfo: false, showCategorization: true, showUOM: true, showCompounds: false,
    },
  },
  insumo_control: {
    label: "Insumo de control",
    description: "Insumo con stock que no va en recetas (ej. artículos de limpieza).",
    group: "Insumos",
    backend: { itemType: "product", itemCanSale: 0, itemTrackInventory: 1, itemProduction: 0 },
    fields: {
      showPrice: false, showCost: true, showTax: false, showDiscount: false,
      showInventoryInfo: true, showCategorization: true, showUOM: true, showCompounds: false,
    },
  },
  produccion_directa: {
    label: "Producción directa",
    description: "Se arma al momento de la venta. La receta se consume al vender.",
    group: "Producción",
    backend: { itemType: "product", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: true, showCost: true, showTax: true, showDiscount: true,
      showInventoryInfo: false, showCategorization: true, showUOM: true, showCompounds: true,
    },
  },
  produccion_previa: {
    label: "Producción previa",
    description: "Se fabrica en lotes antes de la venta. Stock propio + módulo de producción.",
    group: "Producción",
    backend: { itemType: "production", itemCanSale: 1, itemTrackInventory: 1, itemProduction: 1 },
    fields: {
      showPrice: true, showCost: true, showTax: true, showDiscount: true,
      showInventoryInfo: true, showCategorization: true, showUOM: true, showCompounds: true,
    },
  },
  combo_fijo: {
    label: "Combo fijo",
    description: "Precio único, componentes obligatorios sin elección del cliente.",
    group: "Otros",
    backend: { itemType: "combo", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: true, showCost: true, showTax: true, showDiscount: true,
      showInventoryInfo: false, showCategorization: true, showUOM: false, showCompounds: true,
    },
  },
  combo_dinamico: {
    label: "Combo dinámico",
    description: "Precio base + extras que el cliente elige (min/max por grupo).",
    group: "Otros",
    backend: { itemType: "combo", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: true, showCost: false, showTax: true, showDiscount: true,
      showInventoryInfo: false, showCategorization: true, showUOM: false, showCompounds: false,
    },
  },
  descuento: {
    label: "Descuento",
    description: "Item POS que aplica descuento al ticket.",
    group: "Otros",
    backend: { itemType: "discount", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: false, showCost: false, showTax: false, showDiscount: true,
      showInventoryInfo: false, showCategorization: false, showUOM: false, showCompounds: false,
    },
  },
  giftcard: {
    label: "Gift card",
    description: "Tarjeta pre-pagada con código canjeable.",
    group: "Otros",
    backend: { itemType: "giftcard", itemCanSale: 1, itemTrackInventory: 1, itemProduction: 0 },
    fields: {
      showPrice: true, showCost: false, showTax: false, showDiscount: false,
      showInventoryInfo: false, showCategorization: false, showUOM: false, showCompounds: false,
    },
  },
}

export const ALL_KINDS = Object.keys(KIND_META) as ItemKind[]

/**
 * Infiere el kind de un item. Si el item tiene `kind` del backend (post-migración 15),
 * lo retorna directamente. Si no, infiere desde los flags legacy.
 * Se elimina en Slice D cuando todos los forms sean per-kind.
 */
export function inferKind(item: Pick<ItemFull, "kind" | "itemType" | "itemCanSale" | "itemTrackInventory" | "itemProduction"> & Partial<{ hasCompounds: boolean }>): ItemKind {
  if (item.kind && KIND_META[item.kind]) return item.kind

  // Inferencia legacy para items sin kind (pre-migración).
  if (item.itemType === "combo") return "combo_fijo"
  if (item.itemType === "discount") return "descuento"
  if (item.itemType === "giftcard") return "giftcard"
  if (item.itemType === "production" || toBool(item.itemProduction)) return "produccion_previa"

  const canSale = toBool(item.itemCanSale ?? 1)
  const track = toBool(item.itemTrackInventory)

  if (!canSale) return track ? "insumo_stock" : "insumo_sin_stock"
  if (!track) {
    if ((item as { hasCompounds?: boolean }).hasCompounds === true) return "produccion_directa"
    return "servicio"
  }
  return "producto"
}

/** Backend flags para un kind — usado por serialize en el hook. */
export function kindToBackendFields(kind: ItemKind) {
  return KIND_META[kind].backend
}

// ── Taxonomies ────────────────────────────────────────────────────────────

export interface Taxonomy {
  id: string
  name: string
  type: string
  extra: string | null
  /** Solo aplica a type='location'. Null para los demás. */
  outletId: string | null
}

/** Depósito asignado a un item — vive en `itemLocation`. */
export interface ItemLocation {
  locationId: string
  outletId: string
  isDefault: boolean
  taxonomyName: string
  outletName?: string | null
}

// ── helpers ───────────────────────────────────────────────────────────────

function toBool(v: unknown): boolean {
  if (v === null || v === undefined) return false
  if (typeof v === "boolean") return v
  if (typeof v === "number") return v > 0
  if (typeof v === "string") return v === "1" || v.toLowerCase() === "true" || v.toLowerCase() === "t"
  return false
}
