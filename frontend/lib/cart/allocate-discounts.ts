/**
 * Reparte los descuentos de una venta LÍNEA POR LÍNEA.
 *
 * Regla del negocio (owner, 2026-07-29): en Punto los descuentos viven en el
 * ítem, nunca en el total. Un "descuento de venta" es una forma cómoda de
 * cargarlo desde la caja, pero al persistir tiene que quedar repartido entre
 * los ítems — la suma de los ítems con su descuento ES el total con descuento.
 *
 * Antes esto no pasaba: el front mandaba cada ítem con su total BRUTO y
 * `totalDiscount` ausente, y todo el descuento viajaba en el campo de la
 * transacción. Consecuencias reales:
 *   - `itemSold.itemSoldDiscount` quedaba SIEMPRE en 0, así que los reportes
 *     por producto/categoría/marca —que suman esa columna— mostraban cero
 *     descuento por más que la venta lo tuviera.
 *   - El descuento POR LÍNEA no llegaba a ningún total de la transacción: se
 *     veía en el carrito y en lo que se cobraba, pero la transacción quedaba
 *     registrada por el bruto menos solo el descuento de venta.
 *
 * Redondeo: se reparte por el método del resto mayor, así la suma de las
 * partes es EXACTAMENTE el descuento total (sin el peso que se pierde o sobra
 * al redondear cada línea por separado). Se trabaja en unidades enteras, misma
 * convención que el resto del POS (guaraníes, sin decimales).
 */

import type { TaxKind } from "@/lib/tax/engine"

/**
 * Impuesto EFECTIVO de una línea, en el mismo vocabulario que congela el motor
 * (`lib/tax/engine.ts`) y su espejo PHP (`SaleService::enrichWithTaxes`):
 * tasa + naturaleza (`rate`/`exempt`) + si viene DENTRO del precio de lista.
 *
 * Es la dimensión que le faltaba a `lineGross`: hasta 2026-08-22 el neteo de
 * "quitar IVA" dividía TODA línea por 1.10 (una constante `TAX_RATE` que vivía
 * acá), así que un ítem al 5% o al 3% —de los que hay vendidos en producción—
 * se neteaba de más, y uno exento se neteaba sin tener nada que netear.
 */
export interface LineTax {
  /** Porcentaje: 10, 5, 3, 21… Irrelevante cuando `kind === "exempt"`. */
  rate: number
  /** `exempt` no es lo mismo que tasa 0% — es una dimensión fiscal aparte. */
  kind: TaxKind
  /** true = el impuesto ya está DENTRO de `unitPrice` (precio de lista final). */
  included: boolean
}

/**
 * Fallback fiscal seguro: sin tasa resoluble, la línea es exenta y `ivaRemoved`
 * no le toca el precio. NUNCA se inventa una tasa — mismo criterio que
 * `SaleService::deriveTaxRateKindFromName` y que `selectCartIva`.
 */
export const EXEMPT_LINE_TAX: LineTax = { rate: 0, kind: "exempt", included: true }

/**
 * Importe de una línea, ajustado por `ivaRemoved`. FUENTE ÚNICA de la fórmula:
 * la usa el carrito (`lineSubtotal` en lib/cart/store.ts), el precio unitario y
 * el bruto del payload de la venta (`lib/commands/create-sale.ts`). Si cada
 * lado redondeara por su cuenta, lo que se cobra y lo que se registra
 * diferirían por unos guaraníes — y con "quitar IVA" activo, por el impuesto
 * entero.
 *
 * El neteo solo ocurre cuando hay algo que netear:
 *   - línea exenta o de tasa 0 → no se puede quitar un impuesto que no está;
 *   - impuesto NO incluido (`included: false`) → el precio de lista YA es neto;
 *   - impuesto incluido → se divide por (1 + tasa/100), con la tasa DE ESA
 *     LÍNEA (la que `lib/cart/line-tax.ts` resolvió del catálogo del tenant).
 */
export function lineGross(raw: number, ivaRemoved = false, tax: LineTax = EXEMPT_LINE_TAX): number {
  if (!ivaRemoved) return raw
  if (tax.kind !== "rate" || tax.rate <= 0 || !tax.included) return raw
  return Math.round(raw / (1 + tax.rate / 100))
}

export interface DiscountableLine {
  /** Id de la línea — se cruza con `saleDiscount.lineIds` para saber si la alcanza. */
  lineId?: string
  qty: number
  unitPrice: number
  /** Descuento propio de la línea, en porcentaje 0-100. */
  discount?: number | null
  /**
   * Impuesto congelado de la línea. REQUERIDO a propósito: es lo que hace
   * imposible netear "quitar IVA" con una tasa que no es la del ítem. Se
   * resuelve con `withLineTax()` (lib/cart/line-tax.ts) — el único lugar que
   * lee el catálogo — así el carrito y el payload usan exactamente la misma.
   */
  tax: LineTax
}

export interface LineDiscountAllocation {
  /** Bruto de la línea: qty × unitPrice, sin ningún descuento. */
  gross: number
  /** Plata que sale del descuento propio de la línea. */
  lineDiscount: number
  /** Parte del descuento de venta que le toca a esta línea. */
  saleShare: number
  /** lineDiscount + saleShare. Es lo que va a `itemSold.itemSoldDiscount`. */
  totalDiscount: number
  /** totalDiscount expresado como % del bruto de la línea. */
  effectivePercent: number
}

export interface SaleDiscountInput {
  value: number
  mode: "percent" | "money"
  /**
   * Alcance CONGELADO del descuento: las líneas que estaban en el carrito al
   * aplicarlo (ver `saleDiscount` en lib/cart/store.ts). Lo agregado después no
   * entra, y una línea con descuento propio nunca entra — un producto lleva un
   * solo descuento.
   *
   * Opcional por compatibilidad: una venta guardada antes del 2026-07-30 no lo
   * tiene, y ahí se cae al comportamiento viejo (alcanza a todas las líneas sin
   * descuento propio).
   */
  lineIds?: string[]
}

export function allocateLineDiscounts(
  lines: DiscountableLine[],
  saleDiscount?: SaleDiscountInput | null,
  ivaRemoved = false,
): LineDiscountAllocation[] {
  // Bruto y neto con la MISMA fórmula (y el mismo redondeo) que el carrito:
  // el descuento propio de la línea sale de la diferencia entre los dos, no de
  // un redondeo aparte. Así `subtotal - discount` del payload es exactamente el
  // total que vio y cobró el cajero.
  const gross = lines.map((l) => lineGross(l.qty * l.unitPrice, ivaRemoved, l.tax))
  const net = lines.map((l) =>
    lineGross(l.qty * l.unitPrice * (1 - Math.min(100, Math.max(0, l.discount ?? 0)) / 100), ivaRemoved, l.tax),
  )
  const lineDiscount = gross.map((g, i) => g - net[i])

  // Solo las líneas ALCANZADAS por el descuento de venta pesan en su reparto:
  // las demás quedan con peso 0 y no reciben nada.
  const covered = lines.map((l) => {
    if (l.discount) return false // ya tiene descuento propio → nunca dos
    if (!saleDiscount?.lineIds) return true // venta vieja sin alcance: todas
    return l.lineId !== undefined && saleDiscount.lineIds.includes(l.lineId)
  })
  const coveredWeights = net.map((n, i) => (covered[i] ? n : 0))
  const coveredTotal = coveredWeights.reduce((s, n) => s + n, 0)

  const saleDiscountTotal = (() => {
    if (!saleDiscount || coveredTotal <= 0) return 0
    if (saleDiscount.mode === "money") {
      return Math.min(Math.max(0, saleDiscount.value), coveredTotal)
    }
    const pct = Math.min(100, Math.max(0, saleDiscount.value))
    return Math.round((coveredTotal * pct) / 100)
  })()

  const saleShare = distributeByLargestRemainder(coveredWeights, saleDiscountTotal)

  return lines.map((_, i) => {
    const totalDiscount = lineDiscount[i] + saleShare[i]
    return {
      gross: gross[i],
      lineDiscount: lineDiscount[i],
      saleShare: saleShare[i],
      totalDiscount,
      effectivePercent: gross[i] > 0 ? (totalDiscount / gross[i]) * 100 : 0,
    }
  })
}

/**
 * Reparte `amount` entre `weights` proporcionalmente, en enteros, garantizando
 * que la suma del resultado sea exactamente `amount`.
 *
 * Se asigna a cada parte su piso y las unidades sobrantes van a las líneas con
 * mayor resto — el criterio estándar para que no aparezca ni falte un guaraní
 * al sumar el ticket.
 */
function distributeByLargestRemainder(weights: number[], amount: number): number[] {
  const out = weights.map(() => 0)
  if (amount <= 0) return out

  const totalWeight = weights.reduce((s, w) => s + Math.max(0, w), 0)
  if (totalWeight <= 0) return out

  const exact = weights.map((w) => (Math.max(0, w) * amount) / totalWeight)
  const floors = exact.map((e) => Math.floor(e))
  let assigned = floors.reduce((s, f) => s + f, 0)

  // Solo las partes con peso positivo entran en el reparto del resto: una línea
  // fuera del alcance (peso 0) nunca debe recibir un guaraní suelto.
  const order = exact
    .map((e, i) => ({ i, remainder: e - Math.floor(e) }))
    .filter(({ i }) => weights[i] > 0)
    .sort((a, b) => b.remainder - a.remainder)

  const result = [...floors]
  let k = 0
  while (assigned < amount && order.length > 0) {
    result[order[k % order.length].i] += 1
    assigned += 1
    k += 1
  }
  return result
}
