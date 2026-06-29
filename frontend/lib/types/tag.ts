/**
 * Etiquetas de producto — Slice 4 del refactor taxonomy.
 * Endpoint: /v1/tags. Tabla `tag` dedicada (mig 39).
 */

export interface Tag {
  id: string
  name: string
  extra: string | null
  created_at: string | null
  updated_at: string | null
}

export interface TagPayload {
  name: string
  extra?: string | null
}

/** Asignación de una etiqueta a un item (item_tag m2m, sin isPrimary). */
export interface ItemTag {
  id: string
  name: string
}
