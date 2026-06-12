/**
 * Impuestos — Slice 3 del refactor taxonomy.
 * Endpoint: /v1/taxes. Reemplaza el legacy /v1/taxonomies?type=tax.
 */

export interface Tax {
  id: string
  /** Valor del impuesto en formato legacy (ej. "10", "5", "0").
   *  Compat con getTaxValue() del POS y facturación electrónica. */
  name: string
  /** Cast a número del `name` para uso aritmético. null si no parseable. */
  rate: number | null
  extra: string | null
  created_at: string | null
  updated_at: string | null
}

export interface TaxPayload {
  name: string
  extra?: string | null
}
