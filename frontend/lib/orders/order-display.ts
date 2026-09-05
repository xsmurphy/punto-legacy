/**
 * Presentación compartida de órdenes en el POS — etiquetas, totales y
 * resúmenes de ítems usados por las tres vistas de /pos/ordenes (cuadros,
 * lista y mapa). Vive fuera de `page.tsx` para que las tres consuman
 * exactamente los mismos labels y el mismo cálculo de total.
 */

import { Bike, LayoutGrid, ShoppingBag, Store, Globe, CalendarClock, type LucideIcon } from "lucide-react"
import {
  isAddonChild,
  type Order,
  type OrderEvent,
  type OrderItem,
  type OrderStatus,
} from "@/hooks/use-orders"
import { resolveColorBg } from "@/lib/ui/color-palette"

/**
 * Etiquetas operativas (decisión del owner 2026-07-19). Nombran los estados
 * desde la óptica del mostrador, no de la cocina:
 *   open        → "Pendiente"  (se está armando, todavía no salió a preparar)
 *   sent        → "En espera"  (ya salió a preparar, esperando ser tomada)
 *   in_progress → "En proceso"
 *   ready       → "Enviado" (delivery) / "Listo" (el resto)
 * Los estados de la máquina NO cambian — esto es solo presentación.
 *
 * `STATUS_LABEL.ready` queda fijo en "Listo" — usalo solo cuando no hay
 * `order` a mano (ej. un status suelto). Para una orden completa, usá
 * `statusLabelFor(order)`, que resuelve "Enviado" para delivery.
 */
export const STATUS_LABEL: Record<OrderStatus, string> = {
  open: "Pendiente",
  sent: "En espera",
  in_progress: "En proceso",
  ready: "Listo",
  out_for_delivery: "En camino",
  delivered: "Entregada",
  closed: "Cobrada",
  cancelled: "Cancelada",
}

/**
 * Label de estado de una orden completa — igual a `STATUS_LABEL[status]`
 * salvo `ready` + `fulfillment==='delivery'`, que es "Enviado" (decisión del
 * owner: una orden delivery lista para el cadete se dice distinto de una
 * lista para retirar en mostrador).
 *
 * ⚠ TENSIÓN sin resolver (2026-07-28, F-D-1): con `out_for_delivery` ya
 * existiendo, "Enviado" (ready+delivery) y "En camino" (out_for_delivery)
 * quedan MUY cerca semánticamente — "Enviado" hoy en realidad significa
 * "listo, esperando que el cadete lo retire", no que ya salió. No se tocó
 * esta función por decisión propia: el label "Enviado" lo fijó el owner el
 * 2026-07-19, antes de que existiera este estado. Pendiente de que el owner
 * decida si "Enviado" debería renombrarse (ej. "Listo para envío") para
 * despejar la ambigüedad con "En camino".
 */
export function statusLabelFor(order: Order): string {
  if (order.status === "ready" && order.fulfillment === "delivery") return "Enviado"
  return STATUS_LABEL[order.status]
}

export const STATUS_VARIANT: Record<OrderStatus, "default" | "secondary" | "outline"> = {
  open: "outline",
  sent: "secondary",
  in_progress: "secondary",
  ready: "default",
  out_for_delivery: "default",
  delivered: "default",
  closed: "outline",
  cancelled: "outline",
}

export const SOURCE_LABEL: Record<Order["source"], string> = {
  counter: "Mostrador",
  table: "Espacio",
  ecommerce: "E-commerce",
  schedule: "Agenda",
}

/** Categoría de destino de una orden — ver `orderDestination()`. */
export type OrderDestinationKind = "space" | "counter" | "takeaway" | "delivery" | "ecommerce" | "schedule"

export interface OrderDestination {
  kind: OrderDestinationKind
  /**
   * Texto con la CATEGORÍA siempre explícita: "Espacio 2", "Mostrador",
   * "Retiro", "Envío". Nunca el dato pelado — un badge que dice solo "2"
   * (nombre del espacio) no le dice a nadie si eso es una mesa, un mostrador
   * o un número de orden. El ícono acompaña, no reemplaza: a 4 metros en una
   * TV un glifo chico no se lee y la categoría se perdería.
   */
  label: string
  /** Ícono de la categoría — refuerzo visual del label, nunca su sustituto. */
  icon: LucideIcon
}

/**
 * DESTINO de la orden: a dónde va, no de dónde vino. Todo pedido tiene que
 * decir SIEMPRE uno de: Espacio (con nombre) / Mostrador / Retiro / Envío
 * (más los casos de e-commerce/agenda, que conservan su tratamiento previo).
 *
 * Precedencia: una orden de espacio (`source==='table'`) es SIEMPRE el
 * espacio — manda sobre `fulfillment`, que ahí es `dine_in` por construcción
 * (lo fuerza `OrderCoreService::create`, no es una elección real). Fuera de
 * un espacio, `fulfillment` decide mostrador/retiro/envío.
 *
 * Fuente única: la consumen el KDS, la pantalla de despacho, /pos/ordenes y
 * la comanda impresa (`orderDestinationText`, sin íconos). Un `switch`
 * exhaustivo sobre `kind` a propósito.
 *
 * ⚠ Esto es la FUSIÓN de dos ejes (de dónde vino + cómo se entrega) en un
 * solo label, pensada para superficies que solo tienen lugar para un dato
 * (comanda impresa, KDS, despacho, vista cuadros). Cuando hay lugar para dos
 * columnas (la vista lista de /pos/ordenes), usar los ejes separados:
 * `orderSourceLabel()` y `orderFulfillmentLabel()` de abajo. No crear un
 * tercer helper — son exactamente estos dos ejes.
 */
export function orderDestination(order: Order): OrderDestination {
  if (order.source === "table") {
    // "Espacio 2", no "2". Si el nombre no se pudo resolver (sesión borrada,
    // orden vieja), "Espacio" a secas — la categoría siempre es cierta.
    return {
      kind: "space",
      label: order.spaceName ? `${SOURCE_LABEL.table} ${order.spaceName}` : SOURCE_LABEL.table,
      icon: LayoutGrid,
    }
  }
  if (order.source === "ecommerce") {
    return { kind: "ecommerce", label: order.channelRef ?? SOURCE_LABEL.ecommerce, icon: Globe }
  }
  if (order.source === "schedule") {
    return { kind: "schedule", label: SOURCE_LABEL.schedule, icon: CalendarClock }
  }
  switch (order.fulfillment) {
    case "takeaway":
      return { kind: "takeaway", label: "Retiro", icon: ShoppingBag }
    case "delivery":
      return { kind: "delivery", label: "Envío", icon: Bike }
    case "dine_in":
    default:
      return { kind: "counter", label: SOURCE_LABEL.counter, icon: Store }
  }
}

/**
 * Solo el texto del destino — para superficies sin íconos: impresión térmica,
 * `aria-label`, búsqueda. Es exactamente `orderDestination(order).label`: NO
 * existe una variante "corta" sin categoría, justamente para que ninguna
 * pantalla pueda volver a mostrar el nombre del espacio pelado.
 */
export function orderDestinationText(order: Order): string {
  return orderDestination(order).label
}

/**
 * Eje 1 de 2 para la vista lista: DE DÓNDE vino la orden — "Mostrador",
 * "Espacio 2", el canal de e-commerce ("Pedidos Ya"), "Agenda". Es
 * `orderDestination()` desarmado en su eje `source`, sin mezclar cómo se
 * entrega (ver `orderFulfillmentLabel`).
 */
export function orderSourceLabel(order: Order): string {
  if (order.source === "table") {
    return order.spaceName ? `${SOURCE_LABEL.table} ${order.spaceName}` : SOURCE_LABEL.table
  }
  if (order.source === "ecommerce") return order.channelRef ?? SOURCE_LABEL.ecommerce
  if (order.source === "schedule") return SOURCE_LABEL.schedule
  return SOURCE_LABEL.counter
}

/**
 * Eje 2 de 2 para la vista lista: CÓMO se entrega — "Local" / "Retiro" /
 * "Envío". Es `orderDestination()` desarmado en su eje `fulfillment`. Ojo:
 * `dine_in` acá dice "Local", no "Mostrador" — en esta tabla el Origen ya
 * dice "Mostrador"/"Espacio X", así que repetir la palabra sería ruido. El
 * chip del carrito sigue diciendo "MOSTRADOR" para `dine_in`; son superficies
 * distintas, no hace falta unificar la palabra entre ambas.
 */
export function orderFulfillmentLabel(order: Order): string {
  switch (order.fulfillment) {
    case "takeaway":
      return "Retiro"
    case "delivery":
      return "Envío"
    case "dine_in":
    default:
      return "Local"
  }
}

/**
 * Color de acento por estado — fuente única para toda la app. El KDS
 * (`lib/kds/kds-visuals.ts`) consume ESTE mapa para `sent`/`in_progress`/
 * `ready` en vez de llamar a `paletteHex` por su cuenta; no duplicar el
 * mapping ahí ni en ningún otro componente.
 *
 * `open` y `closed`/`cancelled` son `null` (borde neutro): `open` porque el
 * pedido todavía se está armando (no hay "estado" que anunciar), y
 * closed/cancelled porque ya salieron del flujo operativo.
 *
 * `sent`/`in_progress`/`ready` son EXACTAMENTE los colores de hoy en el KDS
 * (slate/sky/emerald) — no se pueden tocar, es la única señal de estado que
 * lee el cocinero a distancia. `delivered` usa `violet`, el mismo que el
 * ítem "Entregado" en `KDS_ITEM_VISUALS`. `out_for_delivery` usa `amber`
 * (F-D-1, 2026-07-28) — es el único color de `PALETTE_COLORS` que ningún
 * otro estado usa todavía (slate/sky/emerald/violet ya tomados); el borde de
 * color es un filtro visual en la vista cuadros y dos estados compartiendo
 * color lo rompería.
 *
 * Hex SIEMPRE vía `resolveColorBg` (lib/ui/color-palette.ts) — nunca hex
 * literal nuevo.
 */
export const STATUS_ACCENT: Record<OrderStatus, string | null> = {
  open: null,
  sent: resolveColorBg("slate"),
  in_progress: resolveColorBg("sky"),
  ready: resolveColorBg("emerald"),
  out_for_delivery: resolveColorBg("amber"),
  delivered: resolveColorBg("violet"),
  closed: null,
  cancelled: null,
}

/**
 * Estados filtrables desde la barra flotante: Pendiente / En espera / En
 * proceso / Enviado / En camino (más el pill "Todos"). `delivered` quedó
 * FUERA por decisión del owner — una orden entregada no se consulta
 * operativamente; se llega a ella desde el menú principal y el listado de
 * transacciones. `closed`/`cancelled` tampoco aparecen: el listado del POS
 * ya los excluye (ver ACTIVE_ORDER_STATUSES).
 */
export const FILTERABLE_STATUSES: OrderStatus[] = [
  "open",
  "sent",
  "in_progress",
  "ready",
  "out_for_delivery",
]

/**
 * Espejo de `ORDER_TRANSITIONS` en `api/lib/Orders/OrderCoreService.php:67` —
 * SOLO para decidir qué opciones mostrar en el dropdown de cambio manual de
 * estado (`OrderDetailView`). La autoridad es el backend: `updateStatus()`
 * revalida esta misma whitelist server-side y rechaza cualquier transición
 * que no esté acá, así que un desincronizado en este archivo nunca permite
 * saltarse la regla — a lo sumo el dropdown ofrece una opción que el server
 * rechaza (y el toast muestra el error real, no uno genérico).
 */
export const ORDER_TRANSITIONS: Record<OrderStatus, OrderStatus[]> = {
  open: ["sent", "cancelled"],
  sent: ["in_progress", "ready", "delivered", "cancelled"],
  in_progress: ["sent", "ready", "delivered", "cancelled"],
  ready: ["sent", "in_progress", "delivered", "out_for_delivery", "cancelled"],
  out_for_delivery: ["delivered", "cancelled"],
  delivered: ["cancelled"],
  closed: [],
  cancelled: [],
}

/**
 * Transiciones para el dropdown de estado manual — `ORDER_TRANSITIONS` sin
 * `cancelled`: cancelar exige motivo obligatorio y ya vive en el menú de
 * "más acciones" (`OrderDetailView`); dos caminos al mismo destino, uno sin
 * pedir motivo, sería un bug esperando pasar.
 */
export function manualStatusOptions(order: Order): OrderStatus[] {
  return ORDER_TRANSITIONS[order.status].filter((s) => s !== "cancelled")
}

/**
 * Quién ejecutó una transición. Compartido entre el historial del detalle de la
 * orden y el reporte de anulaciones de ítems — que un mismo evento se nombre
 * distinto en dos pantallas es cómo empieza a leerse como dos cosas distintas.
 */
export const ACTOR_KIND_LABEL: Record<OrderEvent["actorKind"], string> = {
  user: "Usuario",
  device: "Dispositivo",
  system: "Sistema",
}

/**
 * ¿Se le puede ofrecer al operador anular ESTE ítem de ESTA orden?
 *
 * Definición ÚNICA para todas las superficies que muestran la acción (mesa y
 * detalle de la orden). Es solo la parte que el cliente puede saber sin
 * preguntar; el permiso del operador (`pos.order.item.cancel`) y la ventana de
 * tiempo los evalúa el llamador y el backend respectivamente — acá está lo que
 * NUNCA tiene sentido ofrecer:
 *
 * - Orden cerrada o cancelada: no hay nada vivo que corregir.
 * - Orden ya cobrada (`saleTransactionId`): el ítem está dentro de una venta
 *   emitida. Sacarlo de la comanda dejaría la comanda y la factura diciendo
 *   cosas distintas; eso se corrige con una devolución o una nota de crédito
 *   (context/40), no acá.
 * - Ítem ya anulado: no se anula dos veces.
 * - Add-on de otra línea (`parentOrderItemId`): una hija no se marca ni se
 *   cobra por separado y `OrderCoreService::updateItemStatus` rechaza la
 *   transición — ofrecerla sería un botón que siempre falla.
 */
export function canCancelOrderItem(order: Order, item: OrderItem): boolean {
  if (order.status === "closed" || order.status === "cancelled") return false
  if (order.saleTransactionId !== null) return false
  if (item.status === "cancelled") return false
  if (isAddonChild(item)) return false
  return true
}

/**
 * Total de la orden = suma de sus ítems NO anulados. `items` puede faltar
 * (list sin includeItems).
 *
 * El filtro de anulados vivía duplicado en `SpaceSessionDialog` (que calculaba
 * el total de la mesa bien) mientras esta —la que usan la card, la lista, el
 * mapa, el detalle y la ficha del cliente— sumaba todo. Mientras la única
 * forma de anular era cancelar la orden entera casi no se notaba; con la
 * anulación por ítem, la diferencia es plata que la orden dice deber y nadie
 * va a cobrar. Una sola definición, y es esta.
 */
export function orderTotal(order: Order): number {
  return (order.items ?? [])
    .filter((i) => i.status !== "cancelled")
    .reduce((sum, i) => sum + (i.price ?? 0) * i.qty, 0)
}

/** "2x Café, 1x Medialuna" — o "—" si la orden todavía no trajo ítems. */
export function orderItemsSummary(order: Order): string {
  const items = order.items ?? []
  if (items.length === 0) return "—"
  return items.map((i) => `${i.qty}x ${i.name}`).join(", ")
}

/** Texto sobre el que busca la vista lista: nº de orden, cliente, ítems, origen y tipo de entrega. */
export function orderSearchHaystack(order: Order): string {
  return [
    order.orderNumber !== null ? `#${order.orderNumber} ${order.orderNumber}` : "",
    order.customerName ?? "",
    orderItemsSummary(order),
    order.note ?? "",
    orderSourceLabel(order),
    orderFulfillmentLabel(order),
  ]
    .join(" ")
    .toLowerCase()
}
