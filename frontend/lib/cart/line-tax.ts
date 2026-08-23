/**
 * Resolución del impuesto EFECTIVO de una línea de carrito — ÚNICO lugar del
 * front que traduce `PosItem.taxId` + `taxIncluded` a la tasa concreta que
 * usan tanto el cálculo del carrito como el payload de la venta.
 *
 * Por qué existe (2026-08-22): el neteo de "quitar IVA" dividía todo por 1.10
 * (`TAX_RATE` en `allocate-discounts.ts`). Con ítems al 5% o al 3% —los hay
 * vendidos en producción— eso descontaba de más. La tasa no puede ser una
 * constante: sale del catálogo del tenant, por línea. Ver
 * context/38-impuestos-multi-pais.md.
 *
 * Las reglas de fallback son EXACTAMENTE las del backend
 * (`SaleService::enrichWithTaxes`), para que el impuesto que congela el server
 * sea el mismo que el front usó para cobrar:
 *   - canje de vale → exento (su IVA ya se devengó en la venta que lo emitió);
 *   - `taxId` ausente, o que no matchea ninguna tasa del catálogo (tasa
 *     borrada, línea armada por un flujo que todavía no la propaga) → exento.
 *     NUNCA se inventa una tasa;
 *   - `taxIncluded` de la línea, y si no lo define, el default de la sucursal.
 *
 * Lee el catálogo con `getState()` (no un hook): mismo patrón síncrono que
 * `selectCartIva`/`loadFromOrder` en `lib/cart/store.ts`. NO es un fetch — es
 * memoria ya hidratada por el bootstrap; el carrito es offline-first, nunca
 * golpea red acá.
 */

import { useCatalogStore } from "@/lib/catalog/store"
import { EXEMPT_LINE_TAX, type LineTax } from "@/lib/cart/allocate-discounts"

/**
 * Lo mínimo que una línea necesita exponer para que su impuesto sea resoluble.
 * `CartLine` lo satisface estructuralmente — no hace falta importarlo, y así
 * este módulo no cierra un ciclo con `lib/cart/store.ts` (que sí lo importa).
 */
export interface TaxableLine {
  taxId?: string | null
  /** null = el ítem no lo define → manda el default de la sucursal. */
  taxIncluded?: boolean | null
  /** Canje de vale: su presencia hace la línea exenta, sin mirar el catálogo. */
  voucher?: unknown
}

/**
 * Resuelve el impuesto de UNA línea. Para listas usar `withLineTax`: arma el
 * índice del catálogo una sola vez en lugar de por línea.
 */
export function resolveLineTax(line: TaxableLine): LineTax {
  return resolveWith(catalogTaxContext(), line)
}

/**
 * Adjunta a cada línea su impuesto congelado, dejándola lista para
 * `allocateLineDiscounts` / `lineGross` — que lo exigen en el tipo justamente
 * para que sea imposible netear con una tasa que no es la del ítem.
 */
export function withLineTax<T extends TaxableLine>(lines: T[]): (T & { tax: LineTax })[] {
  const ctx = catalogTaxContext()
  return lines.map((line) => ({ ...line, tax: resolveWith(ctx, line) }))
}

interface TaxContext {
  byId: Map<string, { rate: number; kind: "rate" | "exempt" }>
  outletTaxIncluded: boolean
}

function catalogTaxContext(): TaxContext {
  const { taxes, outletTaxIncluded } = useCatalogStore.getState()
  return {
    byId: new Map(taxes.map((t) => [t.id, { rate: t.rate, kind: t.kind }])),
    outletTaxIncluded,
  }
}

function resolveWith(ctx: TaxContext, line: TaxableLine): LineTax {
  if (line.voucher) return EXEMPT_LINE_TAX
  const tax = line.taxId ? ctx.byId.get(line.taxId) : undefined
  if (!tax || tax.kind !== "rate" || tax.rate <= 0) return EXEMPT_LINE_TAX
  return { rate: tax.rate, kind: "rate", included: line.taxIncluded ?? ctx.outletTaxIncluded }
}
