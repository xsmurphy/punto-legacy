/**
 * Marcas de producto — Slice 2 del refactor taxonomy.
 * Endpoint: /v1/brands. Reemplaza el legacy /v1/taxonomies?type=brand.
 */

export interface Brand {
  id: string
  name: string
  extra: string | null
  created_at: string | null
  updated_at: string | null
}

export interface BrandPayload {
  name: string
  extra?: string | null
}

/** Asignación de una marca a un item (item_brand m2m). */
export interface ItemBrand {
  id: string
  name: string
  isPrimary: boolean
}
