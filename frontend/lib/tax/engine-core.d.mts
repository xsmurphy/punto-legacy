/**
 * Tipos para `engine-core.mjs`.
 *
 * Estrategia elegida: `engine-core.mjs` es JS puro (sin tipos) a propósito,
 * para que `verify-engine.mjs` lo importe con Node pelado sin paso de
 * compilación. TypeScript resuelve automáticamente los tipos de un archivo
 * `.mjs` buscando un `.d.mts` (no `.d.ts`) con el mismo nombre en la misma
 * carpeta — es la extensión específica que usa para declaraciones de
 * módulos ESM, tanto con `moduleResolution: "bundler"` como con
 * "node16"/"nodenext" (ver frontend/tsconfig.json). No hace falta re-exportar
 * nada manualmente ni tocar el tsconfig. Así queda UNA sola implementación
 * real (el .mjs) con tipado estático encima, en vez de duplicar la lógica en
 * `engine.ts`.
 */

export type TaxKind = "rate" | "exempt"

export interface TaxLineInput {
  qty: number
  unitPrice: number
  discount?: number
  taxRate: number
  taxKind: TaxKind
  taxIncluded: boolean
}

export interface TaxEngineConfig {
  decimals: number
}

export interface TaxLineResult {
  net: number
  tax: number
  gross: number
}

export interface TaxByRateResult {
  taxRate: number
  taxKind: TaxKind
  base: number
  tax: number
  gross: number
}

export interface TaxTotalsResult {
  net: number
  tax: number
  gross: number
}

export interface TaxComputationResult {
  lines: TaxLineResult[]
  byRate: TaxByRateResult[]
  totals: TaxTotalsResult
}

export declare function roundHalfUp(value: number, decimals: number): number

export declare function computeTaxes(
  lines: TaxLineInput[],
  config: TaxEngineConfig
): TaxComputationResult
