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

export interface DiscountableLine {
  qty: number
  unitPrice: number
  /** Descuento propio de la línea, en porcentaje 0-100. */
  discount?: number | null
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
}

export function allocateLineDiscounts(
  lines: DiscountableLine[],
  saleDiscount?: SaleDiscountInput | null,
): LineDiscountAllocation[] {
  const gross = lines.map((l) => l.qty * l.unitPrice)
  const lineDiscount = lines.map((l, i) =>
    Math.round((gross[i] * Math.min(100, Math.max(0, l.discount ?? 0))) / 100),
  )
  const net = gross.map((g, i) => g - lineDiscount[i])
  const netTotal = net.reduce((s, n) => s + n, 0)

  const saleDiscountTotal = (() => {
    if (!saleDiscount || netTotal <= 0) return 0
    if (saleDiscount.mode === "money") {
      return Math.min(Math.max(0, saleDiscount.value), netTotal)
    }
    const pct = Math.min(100, Math.max(0, saleDiscount.value))
    return Math.round((netTotal * pct) / 100)
  })()

  const saleShare = distributeByLargestRemainder(net, saleDiscountTotal)

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

  const order = exact
    .map((e, i) => ({ i, remainder: e - Math.floor(e) }))
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
