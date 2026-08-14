/**
 * Armado del carrito para un cobro PARCIAL de una sesión de espacio
 * (split de cuenta, context/15-espacios-module-plan.md §F3).
 *
 * ── Por qué existe este archivo ──────────────────────────────────────────────
 *
 * Cada cobro parcial es su propia `transaction` → su propio comprobante
 * fiscal, y una transaction se crea con `/v1/sales`, que EXIGE que toda línea
 * tenga `itemId` (ver `saleIsSimplePathEligible()` en
 * `api/includes/functions.php`: una línea sin itemId aborta con 422, y el
 * endpoint NO tiene fallback al legacy). Es decir: **no se puede facturar una
 * línea genérica tipo "Pago parcial de mesa"** sin tocar el backend o sin un
 * ítem de catálogo dedicado configurado por el tenant — ninguna de las dos
 * cosas existe hoy.
 *
 * Por eso los modos `amount` (monto libre) y `share` (partes iguales) NO
 * emiten una línea sintética: reparten el monto a cobrar **proporcionalmente
 * sobre los ítems reales todavía no saldados** de la sesión. Consecuencias
 * buscadas:
 *
 *  - El comprobante describe bienes reales (una factura paraguaya debe
 *    detallar lo vendido; "Pago parcial de mesa" no lo hace).
 *  - Stock e `itemSold` se conservan: N parciales de 1/N de cada ítem
 *    descuentan, sumados, exactamente la cantidad original. Una línea
 *    genérica no descontaría stock nunca en estos modos.
 *  - La cantidad de cada línea queda fraccionada (0,333 × Pizza). Es el
 *    precio a pagar y es lo que "partes iguales" significa literalmente.
 *
 * Limitación conocida (FLAG): si sobre la MISMA sesión se mezclan modos
 * (`amount`/`share` + `items`), el stock se descuenta de más — el parcial por
 * monto ya consumió una fracción de ítems que después se cobran enteros por
 * el modo `items`. La plata queda bien (el ledger es la fuente de verdad del
 * saldo); el inventario no. Se resuelve de raíz con un ítem de catálogo
 * dedicado al cobro parcial + soporte de línea sin stock en `SaleService`.
 *
 * ── Redondeo ────────────────────────────────────────────────────────────────
 *
 * Todo el reparto se hace en unidades mínimas de moneda (enteros), nunca en
 * floats: el total de las líneas tiene que dar EXACTO el monto que el backend
 * va a registrar en el ledger, o la caja cobra una cosa y el saldo descuenta
 * otra. `splitShares` es un espejo literal de
 * `SpaceSettlementService::splitShares()` — misma aritmética, misma regla de
 * "la última parte absorbe el resto".
 */

import type { CartLine } from "@/lib/cart/store"
import type { Order } from "@/hooks/use-orders"
import type { PosConfig } from "@/lib/types/pos-bootstrap"

/**
 * Tolerancia al comparar un monto contra el saldo. Espejo de
 * `SpaceSettlementService::MONEY_EPSILON`: la última parte de un split
 * absorbe el resto del redondeo y puede diferir en una unidad mínima de
 * moneda del saldo recalculado server-side.
 */
export const MONEY_EPSILON = 0.01

/**
 * Decimales con los que se muestra una cantidad prorrateada. 6 alcanza para
 * que el precio unitario recalculado no se corra ni un guaraní visible.
 */
const QTY_DECIMALS = 6

function roundTo(value: number, decimals: number): number {
  const factor = 10 ** decimals
  return Math.round(value * factor) / factor
}

/** Ítem de orden utilizable como origen de una línea de cobro parcial. */
export interface SettlementSource {
  /** `pos_order_item.orderitemid` — la unidad que el backend marca en kind='items'. */
  orderItemId: string
  /** `item.itemId` del catálogo. Obligatorio para poder facturar la línea. */
  itemId: string
  name: string
  qty: number
  price: number
  note?: string
  /** Etiquetas de línea (uso interno) — espejo de `note`, mismo criterio. */
  tags?: string[]
}

/**
 * Decimales de la moneda del tenant. Espejo de
 * `SpaceSettlementService::currencyDecimals()`: `settingDecimal` es un flag
 * 'yes'/'no', no un conteo de dígitos.
 */
export function currencyDecimals(config: Pick<PosConfig, "decimal"> | null): number {
  return config?.decimal === "yes" ? 2 : 0
}

/**
 * Reparte `total` en `shareCount` partes exactas, con la última absorbiendo
 * el resto del redondeo. Espejo literal del backend — si las dos
 * implementaciones divergen, el preview de la UI miente sobre lo que se va a
 * cobrar.
 */
export function splitShares(total: number, shareCount: number, decimals: number): number[] {
  const scale = 10 ** decimals
  const totalScaled = Math.round(total * scale)
  const unitScaled = Math.trunc(totalScaled / shareCount)
  const parts = new Array<number>(shareCount).fill(unitScaled)
  parts[shareCount - 1] += totalScaled - unitScaled * shareCount
  return parts.map((p) => p / scale)
}

/**
 * Indexa los ítems facturables de las órdenes de una sesión por
 * `orderItemId`. Solo entran ítems no cancelados de órdenes no
 * cerradas/canceladas — el mismo criterio que `computeBalance()` server-side,
 * para que lo que se puede volcar al carrito coincida con lo que compone el
 * saldo.
 */
export function sourcesFromOrders(orders: Order[]): Map<string, SettlementSource> {
  const map = new Map<string, SettlementSource>()
  for (const order of orders) {
    if (order.status === "closed" || order.status === "cancelled") continue
    for (const oi of order.items ?? []) {
      if (oi.status === "cancelled") continue
      map.set(oi.id, {
        orderItemId: oi.id,
        itemId: oi.itemId ?? "",
        name: oi.name,
        qty: oi.qty,
        price: oi.price ?? 0,
        note: oi.note ?? undefined,
        tags: oi.tags ?? undefined,
      })
    }
  }
  return map
}

/**
 * Error de armado del carrito. Se lanza ANTES de crear la venta — un cobro
 * parcial que no se puede facturar tiene que fallar mientras todavía no hay
 * plata movida, no después.
 */
export class SettlementLinesError extends Error {}

function assertBillable(source: SettlementSource): void {
  if (!source.itemId) {
    throw new SettlementLinesError(
      `"${source.name}" no está vinculado a un artículo del catálogo y no se puede facturar por separado. Cobrá la mesa completa.`,
    )
  }
}

/**
 * Líneas para `kind='items'`: los ítems elegidos, tal cual, sin prorratear.
 * El monto que cobra la caja es la suma de esos ítems — el mismo que el
 * backend recalcula desde los precios persistidos.
 */
export function buildItemsLines(
  sources: Map<string, SettlementSource>,
  orderItemIds: string[],
): Omit<CartLine, "lineId">[] {
  return orderItemIds.map((id) => {
    const source = sources.get(id)
    if (!source) {
      throw new SettlementLinesError(
        "Alguno de los ítems seleccionados ya no está disponible. Actualizá el saldo e intentá de nuevo.",
      )
    }
    assertBillable(source)
    return {
      itemId: source.itemId,
      name: source.name,
      qty: source.qty,
      unitPrice: source.price,
      note: source.note,
      tags: source.tags,
    }
  })
}

/**
 * Líneas para `kind='amount'` y `kind='share'`: reparte `target` sobre
 * `sources` en proporción al peso de cada ítem, ajustando la CANTIDAD (no el
 * precio unitario — el precio de la factura tiene que seguir siendo el real).
 *
 * El reparto se hace sobre enteros en unidades mínimas de moneda con el
 * método del resto mayor, y la última línea absorbe el residuo: la suma de
 * las líneas da `target` exacto.
 *
 * `sources` debe traer SOLO los ítems no saldados (los ya cobrados por
 * `kind='items'` no vuelven a facturarse ni a descontar stock).
 */
export function buildProportionalLines(
  sources: SettlementSource[],
  target: number,
  decimals: number,
  note?: string,
): Omit<CartLine, "lineId">[] {
  const scale = 10 ** decimals
  const targetScaled = Math.round(target * scale)
  // `Number.isFinite` explícito: un target NaN (índice de parte fuera de
  // rango, saldo sin cargar) pasaría todas las comparaciones de abajo — NaN
  // nunca es mayor ni menor — y produciría líneas con qty NaN.
  if (!Number.isFinite(targetScaled) || targetScaled <= 0) {
    throw new SettlementLinesError("El monto a cobrar debe ser mayor a 0.")
  }

  const billable = sources.filter((s) => s.qty > 0 && s.price > 0)
  for (const source of billable) assertBillable(source)

  const weights = billable.map((s) => Math.round(s.qty * s.price * scale))
  const weightTotal = weights.reduce((sum, w) => sum + w, 0)
  if (weightTotal <= 0) {
    throw new SettlementLinesError(
      "La mesa no tiene ítems pendientes para facturar este cobro. Cobrá por ítems o cerrá la mesa.",
    )
  }
  if (targetScaled > weightTotal) {
    throw new SettlementLinesError(
      "El monto supera lo que queda por facturar en la mesa. Revisá el saldo.",
    )
  }

  // Reparto entero: floor proporcional + el residuo entero a la última línea
  // con peso. Mismo criterio que el backend con las partes iguales.
  const allocated = weights.map((w) => Math.floor((targetScaled * w) / weightTotal))
  let remainder = targetScaled - allocated.reduce((sum, a) => sum + a, 0)
  // El residuo se reparte de a una unidad mínima empezando por las líneas de
  // mayor peso — evita concentrar varios guaraníes en un solo renglón. Nunca
  // se asigna a una línea más de lo que ese ítem vale (allocated ≤ weight),
  // condición que además garantiza la terminación: `targetScaled` nunca supera
  // `weightTotal`, así que siempre queda capacidad mientras haya residuo.
  const byWeightDesc = weights
    .map((w, i) => ({ w, i }))
    .sort((a, b) => b.w - a.w)
    .map((x) => x.i)
  while (remainder > 0) {
    const before = remainder
    for (const i of byWeightDesc) {
      if (remainder === 0) break
      if (allocated[i] < weights[i]) {
        allocated[i] += 1
        remainder -= 1
      }
    }
    if (remainder === before) break // sin capacidad libre — imposible por la guarda de arriba
  }

  const lines: Omit<CartLine, "lineId">[] = []
  billable.forEach((source, i) => {
    const amount = allocated[i] / scale
    if (amount <= 0) return
    // La cantidad sale fraccionada (1/3 de una pizza) y el carrito la imprime
    // cruda: sin redondear, el cajero vería "0.3333333333333333". Se redondea
    // a QTY_DECIMALS y el precio unitario se recalcula para que
    // `qty × unitPrice` siga dando EXACTO el monto asignado — la caja no
    // puede cobrar un total distinto al que el ledger va a descontar del
    // saldo. El corrimiento del precio unitario es de milésimas de guaraní
    // (invisible en pantalla y en el comprobante), el del total sería plata.
    const qty = roundTo(amount / source.price, QTY_DECIMALS)
    if (qty <= 0) return
    lines.push({
      itemId: source.itemId,
      name: source.name,
      qty,
      unitPrice: amount / qty,
      note: [source.note, note].filter(Boolean).join(" · ") || undefined,
      tags: source.tags,
    })
  })

  if (lines.length === 0) {
    throw new SettlementLinesError("No se pudo armar el cobro parcial. Revisá el saldo de la mesa.")
  }
  return lines
}
