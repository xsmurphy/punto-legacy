/**
 * Qué está EN el board del KDS y qué ya salió.
 *
 * El board muestra lo que falta hacer. Una comanda terminada ocupando lugar en
 * pantalla no le sirve a la cocina: tapa las que faltan. Cuando termina sale, y
 * se llega a ella por el panel de recall (`recall-dialog.tsx`).
 *
 * "TERMINADA" ES POR ESTACIÓN
 * ---------------------------
 * Con filtro de estaciones activo, la pregunta no es "¿terminó la orden?" sino
 * "¿terminó lo MÍO?". Si la pantalla está filtrada a Cocina y los ítems de
 * Cocina ya están `ready`, la comanda sale de ESA pantalla aunque la barra
 * todavía deba los suyos y el status global siga `in_progress`. Para eso existe
 * el estado por ítem.
 *
 * El corolario que hay que cuidar: la pantalla de la BARRA no puede perder esa
 * misma comanda porque la cocina terminó. Por eso `isDoneForScreen` mira
 * SIEMPRE los ítems de las estaciones de ESTA pantalla y nunca el status
 * agregado de la orden cuando hay filtro — el status agregado es la unión de
 * todas las estaciones, y usarlo acá haría exactamente eso.
 *
 * Sin filtro (pantalla que ve todo) vale el status de la orden, que el backend
 * ya deriva de TODOS sus ítems (`recomputeOrderStatus`) — no hay nada mejor que
 * recalcular en el cliente.
 *
 * Este módulo es la fuente ÚNICA de esos predicados: el board, los contadores
 * de la barra, el panel de recall y la purga de memoria tienen que coincidir en
 * qué está adentro y qué afuera, o la pantalla se contradice sola.
 */

import type { Order, OrderItem, OrderStatus } from "@/hooks/use-orders"
import { parseNaive } from "@/lib/format-date"

/**
 * Cuántas comandas salidas guarda el panel de recall.
 *
 * 20 es "el pasado inmediato", que es para lo que sirve el recall: quien
 * despacha y pregunta "¿y la 42?" un rato después de que salió, o la comanda
 * que volvió mal. En un servicio movido son del orden de la última media hora de
 * salida. Más que eso ya no es recall sino auditoría, y eso tiene su lugar
 * propio (/pos/ordenes y el timeline de `pos_order_event`), con búsqueda y
 * filtros — cosas que una lista de cocina no tiene y no debería.
 *
 * También es el tope de lo que la pantalla RETIENE en memoria de las comandas
 * terminales: el KDS queda abierto durante días, así que una noche movida no
 * puede hacer crecer el Map sin límite.
 */
export const RECALL_LIMIT = 20

/** Estados en los que ya no hay nada que cocinar y la comanda no vuelve. */
const TERMINAL_STATUSES: OrderStatus[] = ["delivered", "closed", "cancelled"]

export function isTerminal(status: OrderStatus): boolean {
  return TERMINAL_STATUSES.includes(status)
}

/**
 * Los ítems que le importan a ESTA pantalla. Sin filtro de estaciones, todos.
 * Es el mismo criterio que usa la tarjeta para pintar sus líneas — si divergiera,
 * quien opera vería una comanda salir del board con líneas sin marcar.
 */
export function screenItems(order: Order, stationIds: string[]): OrderItem[] {
  const items = order.items ?? []
  if (stationIds.length === 0) return items
  return items.filter((i) => i.stationId && stationIds.includes(i.stationId))
}

/**
 * ¿Esta comanda es de esta pantalla? Una orden sin ítems cargados todavía
 * (el detalle llega en un segundo fetch) NO se esconde: preferimos mostrar de
 * más un instante antes que perder una comanda.
 */
export function belongsToScreen(order: Order, stationIds: string[]): boolean {
  if (stationIds.length === 0) return true
  if (!order.items) return true
  return order.items.some((i) => i.stationId && stationIds.includes(i.stationId))
}

/** ¿Ya no hay nada que hacer con esta comanda EN ESTA pantalla? */
export function isDoneForScreen(order: Order, stationIds: string[]): boolean {
  // Entregada, cobrada o cancelada: fuera del board sin importar la estación.
  if (isTerminal(order.status)) return true

  if (stationIds.length === 0) return order.status === "ready"

  // Con filtro, mandan los ítems de esta estación y NUNCA el status agregado.
  if (!order.items) return false // el detalle todavía no llegó
  const mine = screenItems(order, stationIds).filter((i) => i.status !== "cancelled")
  // Todo lo de esta estación quedó cancelado: no hay nada que cocinar acá.
  if (mine.length === 0) return true
  return mine.every((i) => i.status === "ready" || i.status === "delivered")
}

/** Ítems que un recall devolvería a preparación (solo `ready`: `delivered` no vuelve). */
export function recallableItems(order: Order, stationIds: string[]): OrderItem[] {
  return screenItems(order, stationIds).filter((i) => i.status === "ready")
}

/**
 * Por qué NO se puede devolver a preparación, o null si se puede. Se muestra el
 * motivo en vez de un botón muerto — un botón deshabilitado sin explicación en
 * una pantalla que se mira de lejos es una llamada al encargado.
 */
export function recallBlockReason(order: Order, stationIds: string[]): string | null {
  if (order.status === "delivered") return "Ya entregada"
  if (order.status === "closed") return "Ya cobrada"
  if (order.status === "cancelled") return "Cancelada"
  if (recallableItems(order, stationIds).length === 0) return "Sin ítems para devolver"
  return null
}

function ms(iso: string | null | undefined): number {
  if (!iso) return 0
  // parseNaive: los timestamps vienen naive en la TZ del tenant — `new Date()`
  // pelado los interpretaría como UTC y correría el orden varias horas.
  return parseNaive(iso)?.getTime() ?? 0
}

/**
 * Momento en que la comanda salió del board de esta pantalla — ordena el panel
 * de recall (la más reciente primero).
 *
 * Sale de timestamps del SERVIDOR (`readyAt`/`deliveredAt` del ítem), no de un
 * "lo vi salir a tal hora" local: así el orden sobrevive a un refresh de la
 * pantalla y es el mismo en las dos TVs de la cocina.
 */
export function doneAtIso(order: Order, stationIds: string[]): string | null {
  let bestIso: string | null = null
  let best = 0
  const consider = (iso: string | null) => {
    const t = ms(iso)
    if (t > best) { best = t; bestIso = iso }
  }
  for (const item of screenItems(order, stationIds)) {
    if (item.status === "cancelled") continue
    consider(item.deliveredAt)
    consider(item.readyAt)
  }
  if (bestIso !== null) return bestIso
  // Sin timestamps de ítem (comanda cancelada entera, o cobrada sin marcar
  // nada): el momento de la orden es lo mejor disponible.
  consider(order.closedAt)
  consider(order.sentAt)
  consider(order.createdAt)
  return bestIso
}

export function doneAtMs(order: Order, stationIds: string[]): number {
  return ms(doneAtIso(order, stationIds))
}

/** Momento que ORDENA el board. No cambia nunca: una comanda devuelta a preparación
 *  reaparece en su lugar por tiempo, no al final. */
export function boardTimeMs(order: Order): number {
  return ms(order.sentAt) || ms(order.createdAt)
}

/**
 * Acota la memoria de la pantalla. Todo lo que sigue vivo (open → ready) se
 * conserva; de lo terminal se guardan las `limit` más recientes, que es
 * exactamente lo que el panel de recall puede llegar a mostrar.
 *
 * Deliberadamente NO depende de las estaciones visibles: cambiar el filtro en
 * el diálogo de config no puede borrar comandas que el operador todavía podría
 * querer ver. Devuelve el mismo Map si no hay nada que podar, para no disparar
 * renders de más.
 */
export function pruneTerminal(orders: Map<string, Order>, limit = RECALL_LIMIT): Map<string, Order> {
  const terminal = Array.from(orders.values()).filter((o) => isTerminal(o.status))
  if (terminal.length <= limit) return orders

  terminal.sort((a, b) => doneAtMs(b, []) - doneAtMs(a, []))
  const drop = new Set(terminal.slice(limit).map((o) => o.id))
  const next = new Map<string, Order>()
  for (const [id, order] of orders) {
    if (!drop.has(id)) next.set(id, order)
  }
  return next
}
