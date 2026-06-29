/**
 * Categorías de producto — Slice 1 del refactor taxonomy.
 * Endpoint: /v1/categories. Reemplaza el legacy /v1/taxonomies?type=category.
 */

export interface Category {
  id: string
  name: string
  parentId: string | null
  extra: string | null
  created_at: string | null
  updated_at: string | null
}

export interface CategoryPayload {
  name: string
  parentId?: string | null
  extra?: string | null
}
