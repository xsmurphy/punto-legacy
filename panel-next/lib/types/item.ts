/**
 * Shapes de `/v1/items` — basado en `item` table del schema (db-schema-postgres.sql:139)
 * + JSONB demoted via migration 07.
 *
 * Tipo conceptual del item — combinación de 4 fields del backend:
 *   itemType + itemCanSale + itemTrackInventory + itemProduction
 *
 * El usuario nunca ve esos 4 campos directamente. Elige UN "kind" en el form
 * y nosotros mapeamos al backend. Análogamente, al cargar inferimos el kind
 * desde la combinación de campos.
 *
 * Cada kind tiene comportamiento distinto en otros módulos (producción solo
 * acepta `produccion_previa`; reposición de stock solo cuenta `insumo_stock`;
 * etc.) por eso son separados.
 */

export type ItemKind =
  | "producto"
  | "servicio"
  | "insumo_stock"
  | "insumo_sin_stock"
  | "produccion_previa"
  | "produccion_directa"
  | "combo"
  | "descuento"
  | "giftcard"

/** Backend itemType column — separado del kind conceptual. */
export type ItemBackendType =
  | "product"
  | "service"
  | "discount"
  | "combo"
  | "giftcard"
  | "production"
  | "compound"
  | "dynamic"

export interface ItemListItem {
  itemId: string
  itemName: string
  itemSKU: string | null
  itemType: ItemBackendType
  itemStatus: number
  itemPrice: number | string | null
  itemDate: string | null
  updated_at: string | null
  /** Estos vienen del JSONB flatten en list — no siempre presentes. */
  itemCanSale?: number | boolean | null
  itemTrackInventory?: number | boolean | null
  itemProduction?: number | boolean | null
}

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
  /** Detección de "produccion_directa" — true si tiene items hijos con itemParentId.
   *  Backend pendiente de exponer; por ahora lo dejamos opcional y defaulteamos. */
  hasCompounds?: boolean
  [key: string]: unknown
}

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
  categoryId: string
  brandId: string
  status: boolean

  // Configuraciones extendidas (en JSONB excepto outletId y itemSort).
  /** Sucursal a la que pertenece el ítem ("" = todas las sucursales). */
  outletId: string
  /** Proveedor primario (FK al contacto type=2 — proveedor). */
  supplierId: string
  /** Merma % — porcentaje de pérdida en producción/stock. */
  waste: number | null
  /** Prioridad de ordenamiento — menor = más arriba (default 99999). */
  sort: number | null
  /** Comisión sobre la venta. */
  commission: number | null
  /** Tipo de comisión: '%' (porcentual) o 'fixed' (monto fijo). */
  commissionType: "percent" | "fixed"
  /** Si el precio se calcula como % sobre costo, este es el % a aplicar. */
  pricePercent: number | null
  /** Tipo de cálculo de precio: '' (fijo) o 'percent' (% sobre costo). */
  priceType: "fixed" | "percent"
  /** Item vendido online (ecommerce). */
  ecom: boolean
  /** Item destacado (featured) en el catálogo. */
  featured: boolean
}

// ── Kind config: labels + visibility de secciones del form ────────────────

export interface KindFieldVisibility {
  /** Sección "Precios" — incluye precio de venta. */
  showPrice: boolean
  /** Costo (avg COGS). Va separado de showPrice porque insumos llevan costo
   *  pero no precio de venta. */
  showCost: boolean
  /** Sección "Impuestos" — taxId + taxIncluded. */
  showTax: boolean
  /** Discount default. */
  showDiscount: boolean
  /** Sección "Inventario" con switch lleva-stock + UOM (los toggles ya están
   *  determinados por el kind, no son user-editable). */
  showInventoryInfo: boolean
  /** Categorización (categoryId + brandId). */
  showCategorization: boolean
  /** Unit of measurement. */
  showUOM: boolean
  /** Sección Ingredientes / Compounds — solo producción. */
  showCompounds: boolean
}

export interface KindMeta {
  label: string
  description: string
  /** Group label para el dropdown del form. */
  group: "Items de venta" | "Insumos" | "Producción" | "Otros"
  /** Backend flags inferidos para este kind. */
  backend: {
    itemType: ItemBackendType
    itemCanSale: 0 | 1
    itemTrackInventory: 0 | 1
    itemProduction: 0 | 1
  }
  fields: KindFieldVisibility
}

export const KIND_META: Record<ItemKind, KindMeta> = {
  producto: {
    label: "Producto",
    description: "Artículo físico vendible con inventario.",
    group: "Items de venta",
    backend: { itemType: "product", itemCanSale: 1, itemTrackInventory: 1, itemProduction: 0 },
    fields: {
      showPrice: true,
      showCost: true,
      showTax: true,
      showDiscount: true,
      showInventoryInfo: true,
      showCategorization: true,
      showUOM: true,
      showCompounds: false,
    },
  },
  servicio: {
    label: "Servicio",
    description: "Servicio cobrado al cliente. No lleva inventario.",
    group: "Items de venta",
    backend: { itemType: "product", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: true,
      showCost: false,
      showTax: true,
      showDiscount: true,
      showInventoryInfo: false,
      showCategorization: true,
      showUOM: true,
      showCompounds: false,
    },
  },
  insumo_stock: {
    label: "Insumo con inventario",
    description:
      "Materia prima o componente que se cuenta en stock. Entra en reposición y conteo de inventario.",
    group: "Insumos",
    backend: { itemType: "product", itemCanSale: 0, itemTrackInventory: 1, itemProduction: 0 },
    fields: {
      showPrice: false,
      showCost: true,
      showTax: false,
      showDiscount: false,
      showInventoryInfo: true,
      showCategorization: true,
      showUOM: true,
      showCompounds: false,
    },
  },
  insumo_sin_stock: {
    label: "Insumo sin inventario",
    description: "Gasto puntual sin control de stock. Útil para servicios contratados o consumibles menores.",
    group: "Insumos",
    backend: { itemType: "product", itemCanSale: 0, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: false,
      showCost: true,
      showTax: false,
      showDiscount: false,
      showInventoryInfo: false,
      showCategorization: true,
      showUOM: true,
      showCompounds: false,
    },
  },
  produccion_previa: {
    label: "Producción previa",
    description:
      "Se fabrica con anticipación a la venta. Aparece en el módulo de producción para programar lotes.",
    group: "Producción",
    backend: { itemType: "production", itemCanSale: 1, itemTrackInventory: 1, itemProduction: 1 },
    fields: {
      showPrice: true,
      showCost: true,
      showTax: true,
      showDiscount: true,
      showInventoryInfo: true,
      showCategorization: true,
      showUOM: true,
      showCompounds: true,
    },
  },
  produccion_directa: {
    label: "Producción directa",
    description:
      "Se arma en el momento de la venta (ej. ensalada hecha al pedido). No entra en producción programada.",
    group: "Producción",
    backend: { itemType: "product", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: true,
      showCost: true,
      showTax: true,
      showDiscount: true,
      showInventoryInfo: false,
      showCategorization: true,
      showUOM: true,
      showCompounds: true,
    },
  },
  combo: {
    label: "Combo",
    description: "Paquete de varios items con precio único.",
    group: "Otros",
    backend: { itemType: "combo", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: true,
      showCost: false,
      showTax: true,
      showDiscount: true,
      showInventoryInfo: false,
      showCategorization: true,
      showUOM: false,
      showCompounds: false,
    },
  },
  descuento: {
    label: "Descuento",
    description: "Aplicable como ítem en venta para restar del total.",
    group: "Otros",
    backend: { itemType: "discount", itemCanSale: 1, itemTrackInventory: 0, itemProduction: 0 },
    fields: {
      showPrice: false,
      showCost: false,
      showTax: false,
      showDiscount: true,
      showInventoryInfo: false,
      showCategorization: false,
      showUOM: false,
      showCompounds: false,
    },
  },
  giftcard: {
    label: "Gift card",
    description: "Tarjeta pre-pagada vendida al cliente.",
    group: "Otros",
    backend: { itemType: "giftcard", itemCanSale: 1, itemTrackInventory: 1, itemProduction: 0 },
    fields: {
      showPrice: true,
      showCost: false,
      showTax: false,
      showDiscount: false,
      showInventoryInfo: false,
      showCategorization: false,
      showUOM: false,
      showCompounds: false,
    },
  },
}

/**
 * Mapea un item del backend al kind conceptual. Inferencia:
 *   - itemType !== 'product' → mapea 1:1 (combo/discount/giftcard/production)
 *   - itemType === 'product' → se decide por flags + presencia de compounds
 */
export function inferKind(item: ItemFull): ItemKind {
  // Mapeos directos por itemType.
  if (item.itemType === "combo") return "combo"
  if (item.itemType === "discount") return "descuento"
  if (item.itemType === "giftcard") return "giftcard"
  if (item.itemType === "production" || toBool(item.itemProduction)) return "produccion_previa"

  // itemType === 'product' a partir de acá.
  const canSale = toBool(item.itemCanSale ?? 1) // default 1 si no viene
  const track = toBool(item.itemTrackInventory)

  if (!canSale) {
    return track ? "insumo_stock" : "insumo_sin_stock"
  }
  if (!track) {
    // Servicio vs producción directa — los distingue la presencia de compounds.
    // Si hasCompounds está disponible y es true → producción directa.
    if (item.hasCompounds === true) return "produccion_directa"
    return "servicio"
  }
  return "producto"
}

/**
 * Backend flags para un kind. Lo usa el serializer al guardar.
 */
export function kindToBackendFields(kind: ItemKind) {
  return KIND_META[kind].backend
}

// ── helpers ────────────────────────────────────────────────────────────────

function toBool(v: unknown): boolean {
  if (v === null || v === undefined) return false
  if (typeof v === "boolean") return v
  if (typeof v === "number") return v > 0
  if (typeof v === "string") return v === "1" || v.toLowerCase() === "true" || v.toLowerCase() === "t"
  return false
}

// ── Taxonomies ────────────────────────────────────────────────────────────

export interface Taxonomy {
  id: string
  name: string
  type: string
  extra: string | null
}
