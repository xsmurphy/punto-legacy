/**
 * Impuestos — F0 del plan de impuestos multi-país (context/38).
 * Endpoint: /v1/taxes. Reemplaza el legacy /v1/taxonomies?type=tax.
 */

export type TaxKind = "rate" | "exempt"

export interface Tax {
  id: string
  /** Valor del impuesto en formato legacy (ej. "10", "5", "0").
   *  Compat con getTaxValue() del POS y facturación electrónica. */
  name: string
  /** Tasa numérica (mig 120). Fuente autoritativa para uso aritmético —
   *  ya no es un cast best-effort de `name`. */
  rate: number | null
  /** 'rate' = porcentaje real; 'exempt' = exento (rate=0 sin ser un 0% real). */
  kind: TaxKind
  extra: string | null
  /** Orden manual (drag&drop en Catálogo, mig 121). null = sin ordenar —
   *  el backend cae a orden alfabético por name (NULLS LAST). */
  sortOrder: number | null
  created_at: string | null
  updated_at: string | null
}

export interface TaxPayload {
  name: string
  /** Opcional: si no viene, el backend la deriva de `name`. */
  rate?: number | null
  kind?: TaxKind
  extra?: string | null
}
