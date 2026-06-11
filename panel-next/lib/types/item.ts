/**
 * Shapes de `/v1/items` — basado en `item` table del schema (db-schema-postgres.sql:139)
 * + JSONB demoted via migration 07. El backend devuelve la fila flatten'eada
 * con keys del JSONB merged onto the columns.
 *
 * Tipos de item soportados:
 *   - product  (default — artículo físico)
 *   - service  (servicio cobrado por unidad de tiempo)
 *   - discount (descuento aplicable como ítem)
 *   - combo    (combo / paquete de varios items)
 *   - giftcard (gift card pre-pagada)
 */

export type ItemType = "product" | "service" | "discount" | "combo" | "giftcard"

export interface ItemListItem {
  itemId: string
  itemName: string
  itemSKU: string | null
  itemType: ItemType
  itemStatus: number
  itemPrice: number | string | null
  itemDate: string | null
  updated_at: string | null
}

/** Lo que devuelve GET /v1/items?id=<uuid>: row completa (todas las columnas
 *  + JSONB flatten'eado). Tipo permissive porque hay muchos campos JSONB
 *  opcionales que no necesitamos en panel-next MVP (ecom, comissions, etc). */
export interface ItemFull extends ItemListItem {
  itemCost: number | string | null
  itemPrice: number | string | null
  itemSKU: string | null
  itemUOM: string | null
  itemTaxExcluded: number | string | null
  itemDiscount: number | string | null
  itemTrackInventory: boolean | number | null
  itemCanSale: boolean | number | null
  itemProduction: boolean | number | null
  itemTaxIncluded: boolean | number | null
  taxId: string | null
  brandId: string | null
  categoryId: string | null
  supplierId: string | null
  locationId: string | null
  outletId: string | null
  itemDescription: string | null
  // Los demás campos JSONB van sin tipar — el form los ignora.
  [key: string]: unknown
}

/** Form values del editor. Mapeados a los nombres `itemX` del backend. */
export interface ItemFormValues {
  name: string
  sku: string
  type: ItemType
  description: string
  price: number | null
  cost: number | null
  discount: number | null
  taxId: string
  taxIncluded: boolean
  trackInventory: boolean
  canSale: boolean
  production: boolean
  uom: string
  categoryId: string
  brandId: string
  status: boolean
}

// ── Taxonomies ────────────────────────────────────────────────────────────

export interface Taxonomy {
  id: string
  /** Para impuestos, `name` es el porcentaje como string ("10"). */
  name: string
  type: string
  extra: string | null
}
