/**
 * Motor de cálculo de impuestos — envoltorio tipado.
 *
 * F1 del plan multi-país (context/38-impuestos-multi-pais.md). La lógica
 * real vive en UN solo lugar: `engine-core.mjs` (JS puro). Este archivo
 * solo re-exporta esa implementación con tipos TS encima (vía
 * `engine-core.d.mts`, colocado junto al .mjs) — no duplica la fórmula.
 *
 * Nadie consume este motor todavía (cableado a carrito/SaleService = F2,
 * fuera de alcance).
 */
export {
  computeTaxes,
  roundHalfUp,
  type TaxKind,
  type TaxLineInput,
  type TaxEngineConfig,
  type TaxLineResult,
  type TaxByRateResult,
  type TaxTotalsResult,
  type TaxComputationResult,
} from "./engine-core.mjs"
