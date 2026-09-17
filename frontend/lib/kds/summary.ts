/**
 * Consolidado del día — "¿cuántos de cada opción hay que armar?" (context/70).
 *
 * Es OTRA LECTURA de lo mismo que el board, no otra fuente: un comercio de
 * viandas no cocina contra la comanda —los platos ya están hechos— sino que
 * ARMA y entrega, y para eso la pregunta no es "qué pide la comanda 42" sino
 * "cuántas milanesas en total". Las mismas órdenes, agrupadas por plato.
 *
 * POR QUÉ SE DERIVA Y NO SE PIDE AL SERVIDOR
 * ------------------------------------------
 * El resumen tiene que coincidir 1:1 con lo que la pantalla muestra, o quien
 * arma cuenta una cosa y el board dice otra. Cualquier endpoint propio sería
 * una segunda definición de "la demanda de hoy" (otro corte de fecha, otro
 * filtro de estaciones, otro momento de refresco) y divergiría el día que una
 * de las dos cambie. Acá se agregan LAS MISMAS órdenes que ya están en
 * memoria, con LOS MISMOS predicados de `board.ts` — y como deriva del estado,
 * el tiempo real sale gratis: lo que actualiza el board actualiza el resumen.
 *
 * QUÉ ENTRA
 * ---------
 * Todo lo NO TERMINAL, que es más que el board: una orden cuyos platos ya
 * están `ready` salió del board (no hay nada que hacer) pero sigue siendo
 * comida armada esperando salir, y quien cuenta bandejas la necesita. Por eso
 * cada fila separa PENDIENTE de LISTO en vez de dar un solo número: el
 * pendiente es trabajo, el listo es control.
 *
 * Funciones puras y sin React a propósito: esto es lo único de la vista que
 * puede estar mal en silencio (un número mal sumado no se ve, a diferencia de
 * un layout roto), así que se verifica en `__tests__/summary.test.ts`.
 */

import type { Order, OrderItem } from "@/hooks/use-orders"
import { addonChildrenOf, boardTimeMs, isTerminal, screenItems } from "@/lib/kds/board"

/** Una orden que pide este plato — el detalle que se abre al tocar la fila. */
export interface SummaryOrderRef {
  orderId: string
  orderNumber: number | null
  customerName: string | null
  /** Unidades de este plato en ESA orden (sumadas si vino en varias líneas). */
  qty: number
  /** No queda ninguna unidad de este plato por armar en esa orden. */
  ready: boolean
  /** Mismo reloj que ordena el board — el detalle se lee en orden de llegada. */
  boardTimeMs: number
}

/** Una opción elegida dentro del plato (guarnición, punto de cocción…). */
export interface SummaryAddon {
  name: string
  qty: number
}

export interface SummaryRow {
  /** Estable entre renders: agrupa por ítem del catálogo, o por nombre si es línea libre. */
  key: string
  name: string
  /** Unidades que todavía hay que armar. */
  pending: number
  /** Unidades ya armadas esperando salir. */
  ready: number
  total: number
  /**
   * Las opciones del plato, agregadas. Es el caso que NO se puede leer del
   * total: "Milanesa 18" no le dice a quien arma cuántas van con arroz y
   * cuántas con puré, y esa es justamente la cuenta que tiene que hacer.
   */
  addons: SummaryAddon[]
  orders: SummaryOrderRef[]
}

/**
 * Qué línea cuenta para el armado. Enumera los estados QUE SÍ en vez de
 * descartar `cancelled`/`delivered`: si mañana aparece un estado nuevo, queda
 * afuera hasta que alguien decida qué significa acá, en vez de sumarse solo a
 * una cuenta que nadie va a revisar.
 */
function countsForSummary(item: OrderItem): boolean {
  return item.status === "pending" || item.status === "preparing" || item.status === "ready"
}

/**
 * Clave de agrupación. Dos líneas del mismo ítem del catálogo son el mismo
 * plato aunque vengan de órdenes distintas; una línea libre (`itemId` null, lo
 * que el cajero tipeó a mano) solo puede agruparse por su texto, y se prefija
 * para que un ítem cuyo id fuera igual a un nombre no colisione con ella.
 */
function groupKey(item: OrderItem): string {
  return item.itemId ?? `txt:${item.name}`
}

interface Draft {
  key: string
  name: string
  pending: number
  ready: number
  addons: Map<string, number>
  orders: Map<string, SummaryOrderRef & { pending: number }>
}

/**
 * Agrupa por plato las órdenes que le pertenecen a esta pantalla.
 *
 * `orders` viene ya filtrado por la pantalla con los criterios del board
 * (`belongsToScreen` + corte de fecha de entrega); lo que esta función agrega
 * —y por eso lo hace adentro y no en el llamador— es que **una orden terminal
 * nunca cuenta**: una cancelada o ya cobrada no se arma, y ese es el tipo de
 * filtro que un segundo llamador se olvida.
 */
export function summarizeOrders(orders: Order[], stationIds: string[]): SummaryRow[] {
  const groups = new Map<string, Draft>()

  for (const order of orders) {
    if (isTerminal(order.status)) continue
    const time = boardTimeMs(order)

    // `screenItems` ya deja afuera las líneas hijas de add-ons y respeta el
    // filtro de estaciones — las hijas se cuentan aparte, colgadas del padre.
    for (const item of screenItems(order, stationIds)) {
      if (!countsForSummary(item)) continue

      const key = groupKey(item)
      let group = groups.get(key)
      if (!group) {
        group = { key, name: item.name, pending: 0, ready: 0, addons: new Map(), orders: new Map() }
        groups.set(key, group)
      }

      const armed = item.status === "ready"
      if (armed) group.ready += item.qty
      else group.pending += item.qty

      // Las opciones viajan con su padre: acá solo se llega con un padre que
      // cuenta, así que una hija de una línea cancelada o entregada no entra.
      for (const addon of addonChildrenOf(order, item.id)) {
        if (!countsForSummary(addon)) continue
        group.addons.set(addon.name, (group.addons.get(addon.name) ?? 0) + addon.qty)
      }

      let ref = group.orders.get(order.id)
      if (!ref) {
        ref = {
          orderId: order.id,
          orderNumber: order.orderNumber,
          customerName: order.customerName,
          qty: 0,
          pending: 0,
          ready: false,
          boardTimeMs: time,
        }
        group.orders.set(order.id, ref)
      }
      ref.qty += item.qty
      if (!armed) ref.pending += item.qty
    }
  }

  return Array.from(groups.values())
    .map((group) => ({
      key: group.key,
      name: group.name,
      pending: group.pending,
      ready: group.ready,
      total: group.pending + group.ready,
      addons: Array.from(group.addons, ([name, qty]) => ({ name, qty })).sort(
        (a, b) => b.qty - a.qty || a.name.localeCompare(b.name)
      ),
      orders: Array.from(group.orders.values())
        .map(({ pending, ...ref }) => ({ ...ref, ready: pending === 0 }))
        .sort((a, b) => a.boardTimeMs - b.boardTimeMs),
    }))
    // Lo que más falta armar, arriba: el resumen se lee de arriba hacia abajo
    // y la primera fila tiene que ser la que más trabajo representa. El
    // desempate por nombre lo hace ESTABLE — sin él, dos platos con el mismo
    // pendiente se intercambiarían de lugar en cada evento del socket, que es
    // exactamente lo que el board evita moviendo nada.
    .sort((a, b) => b.pending - a.pending || a.name.localeCompare(b.name))
}
