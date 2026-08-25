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
 * (El backend lo bloquea de todos modos: las dos familias son mutuamente
 * excluyentes por sesión — `SpaceSettlementService::validateAndComputeAmount`.)
 *
 * Add-ons: `kind='items'` los re-hidrata como `selections` del padre y por
 * ende descuentan stock; `amount`/`share` NO — ver el docblock de
 * `buildProportionalLines` para las tres razones y por qué forzarlo sería
 * peor.
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

import { addonsDelta, rebuildSelectionsFromOrder, type CartLine } from "@/lib/cart/store"
import { isAddonChild, type Order, type OrderItem } from "@/hooks/use-orders"
import type { PosConfig, PosItem } from "@/lib/types/pos-bootstrap"

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
  /**
   * La fila cruda de la orden. La necesita `rebuildSelectionsFromOrder` para
   * re-hidratar los add-ons: los campos aplanados de arriba son el contrato de
   * PLATA de esta capa, pero la reconstrucción necesita el `OrderItem` entero.
   */
  orderItem: OrderItem
  /**
   * Hijas de add-on de esta línea (context/41, mig 140), no canceladas. Vacío
   * en el 100% del tráfico de un comercio que no usa la feature.
   */
  children: OrderItem[]
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
 *
 * Las hijas de add-on NO son unidades cobrables y por eso no entran al mapa:
 * no llevan plata propia (su recargo ya está en el precio del padre) y el
 * saldo del backend las excluye explícitamente
 * (`SpaceBalanceService::compute()`, `parentorderitemid IS NULL`). Quedan
 * colgadas de su padre en `children`, que es donde sirven: para reconstruir
 * las `selections` y que el add-on descuente stock al cobrarse.
 */
export function sourcesFromOrders(orders: Order[]): Map<string, SettlementSource> {
  const map = new Map<string, SettlementSource>()
  const childrenByParent = new Map<string, OrderItem[]>()

  for (const order of orders) {
    if (order.status === "closed" || order.status === "cancelled") continue
    for (const oi of order.items ?? []) {
      if (oi.status === "cancelled") continue
      if (isAddonChild(oi)) {
        const parentId = oi.parentOrderItemId as string
        const siblings = childrenByParent.get(parentId)
        if (siblings) siblings.push(oi)
        else childrenByParent.set(parentId, [oi])
        continue
      }
      map.set(oi.id, {
        orderItemId: oi.id,
        itemId: oi.itemId ?? "",
        name: oi.name,
        qty: oi.qty,
        price: oi.price ?? 0,
        note: oi.note ?? undefined,
        tags: oi.tags ?? undefined,
        orderItem: oi,
        children: [],
      })
    }
  }

  // Segundo paso: las hijas llegan agrupadas después de su padre, pero el
  // padre puede venir en otra orden de la misma sesión — no se asume orden.
  for (const [parentId, children] of childrenByParent) {
    const parent = map.get(parentId)
    if (parent) parent.children = children
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
 *
 * ── Add-ons (context/41) ────────────────────────────────────────────────────
 *
 * Un ítem con add-ons vuelve con sus `selections` re-hidratadas
 * (`rebuildSelectionsFromOrder`), igual que al cobrar una orden o una mesa
 * entera: sin ellas `SaleService::expandAddonSelections` no corre, el add-on
 * no genera su `itemSold`, no descuenta stock y no sale indentado en el
 * ticket. La plata ya estaba bien —el recargo viene adentro del `price` del
 * padre— y por eso el agujero era invisible.
 *
 * **El precio unitario NO se re-cotiza acá, a diferencia de
 * `cartLinesFromOrderItems`.** Un cobro parcial se registra en el ledger con
 * el monto que el backend recalcula desde los precios PERSISTIDOS
 * (`SpaceSettlementService::validateAndComputeAmount`, kind='items'): si la
 * caja cobrara el `priceDelta` vigente y el owner hubiera cambiado el precio
 * del add-on con la mesa abierta, la venta y el asiento del ledger diferirían
 * — el cliente pagaría una cifra y la mesa quedaría con saldo (o saldada de
 * menos). Se ancla al precio persistido y se despeja la base restándole el
 * recargo VIGENTE, que es exactamente lo que el server le va a restar al
 * padre. Invariante que sale intacta: padre + hijas = lo que cobró la caja =
 * lo que registra el ledger.
 *
 * Fail-safe idéntico al de `rebuildSelectionsFromOrder`: si el recargo vigente
 * no entra en el precio persistido (base negativa), la línea va sin add-ons —
 * el comportamiento previo — en vez de emitir un detalle que suma más que el
 * total cobrado.
 */
export function buildItemsLines(
  sources: Map<string, SettlementSource>,
  orderItemIds: string[],
  catalogItems: PosItem[],
): Omit<CartLine, "lineId">[] {
  return orderItemIds.map((id) => {
    const source = sources.get(id)
    if (!source) {
      throw new SettlementLinesError(
        "Alguno de los ítems seleccionados ya no está disponible. Actualizá el saldo e intentá de nuevo.",
      )
    }
    assertBillable(source)

    const cat = catalogItems.find((ci) => ci.id === source.itemId)
    const rebuilt = rebuildSelectionsFromOrder(source.orderItem, source.children, cat)
    // De lo que devuelve `rebuilt` se usan SOLO las selecciones: su
    // `basePrice` está despejado con el recargo CONGELADO (sirve para
    // re-cotizar, que es lo que hace el cobro de la orden/mesa) y acá el
    // anclaje es el opuesto — el precio persistido manda, ver el docblock.
    // Cablear `rebuilt.basePrice` acá rompería en silencio el amarre con el
    // ledger.
    const selections = rebuilt?.selections
    const basePrice = source.price - addonsDelta(selections)

    const line = {
      itemId: source.itemId,
      name: source.name,
      qty: source.qty,
      unitPrice: source.price,
      note: source.note,
      tags: source.tags,
    }
    if (!selections || basePrice < 0) return line
    return { ...line, basePrice, selections }
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
 *
 * ── Por qué acá NO se reconstruyen los add-ons ──────────────────────────────
 *
 * Decisión explícita, no un olvido. `buildItemsLines` sí los reconstruye; este
 * camino no, por tres razones que se suman:
 *
 * 1. **La qty deja de ser una unidad.** Estas líneas salen fraccionadas (0,333
 *    × Pizza) y `CartLineAddon.qty` es un entero ≥ 1 que el server multiplica
 *    por las unidades del padre. Un tercio de pizza descontaría un tercio de
 *    queso — un movimiento de stock que no corresponde a nada que haya pasado
 *    en la cocina.
 * 2. **El recargo NO se prorratea.** `expandAddonSelections` le resta al padre
 *    el `priceDelta` unitario COMPLETO y se lo da a la hija. Sobre una línea
 *    que solo cobra una fracción del ítem, el add-on se llevaría su recargo
 *    entero (y la base del padre se iría a negativo en cualquier parcial
 *    chico).
 * 3. **Se descontaría N veces.** `amount`/`share` prorratean sobre lo no
 *    saldado SIN marcarlo: N parciales tocan los mismos ítems N veces. Con
 *    selections, el add-on descontaría stock en cada uno.
 *
 * O sea: el add-on cobrado por `amount`/`share` sigue sin descontar stock. Es
 * el mismo hueco ya documentado arriba para el ítem prorrateado en general
 * (mezclar familias deriva el inventario), y se cierra con la misma solución
 * de raíz —ítem de catálogo dedicado al cobro parcial + línea sin stock en
 * `SaleService`—, no con una reconstrucción a medias acá. Forzarlo cambiaría
 * un stock que no se descuenta por uno que se descuenta mal.
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
