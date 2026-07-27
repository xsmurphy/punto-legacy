/**
 * Presentación compartida de órdenes en el POS — etiquetas, totales y
 * resúmenes de ítems usados por las tres vistas de /pos/ordenes (cuadros,
 * lista y mapa). Vive fuera de `page.tsx` para que las tres consuman
 * exactamente los mismos labels y el mismo cálculo de total.
 */

import { Bike, LayoutGrid, ShoppingBag, Store, Globe, CalendarClock, type LucideIcon } from "lucide-react"
import type { Order, OrderStatus } from "@/hooks/use-orders"

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
  delivered: "Entregada",
  closed: "Cobrada",
  cancelled: "Cancelada",
}

/**
 * Label de estado de una orden completa — igual a `STATUS_LABEL[status]`
 * salvo `ready` + `fulfillment==='delivery'`, que es "Enviado" (decisión del
 * owner: una orden delivery lista para el cadete se dice distinto de una
 * lista para retirar en mostrador).
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
 * Estados filtrables desde la barra flotante: Pendiente / En espera / En
 * proceso / Enviado (más el pill "Todos"). `delivered` quedó FUERA por
 * decisión del owner — una orden entregada no se consulta operativamente;
 * se llega a ella desde el menú principal y el listado de transacciones.
 * `closed`/`cancelled` tampoco aparecen: el listado del POS ya los excluye
 * (ver ACTIVE_ORDER_STATUSES).
 */
export const FILTERABLE_STATUSES: OrderStatus[] = [
  "open",
  "sent",
  "in_progress",
  "ready",
]

/** Total de la orden = suma de sus ítems. `items` puede faltar (list sin includeItems). */
export function orderTotal(order: Order): number {
  return (order.items ?? []).reduce((sum, i) => sum + (i.price ?? 0) * i.qty, 0)
}

/** "2x Café, 1x Medialuna" — o "—" si la orden todavía no trajo ítems. */
export function orderItemsSummary(order: Order): string {
  const items = order.items ?? []
  if (items.length === 0) return "—"
  return items.map((i) => `${i.qty}x ${i.name}`).join(", ")
}

/** Texto sobre el que busca la vista lista: nº de orden, cliente e ítems. */
export function orderSearchHaystack(order: Order): string {
  return [
    order.orderNumber !== null ? `#${order.orderNumber} ${order.orderNumber}` : "",
    order.customerName ?? "",
    orderItemsSummary(order),
    order.note ?? "",
  ]
    .join(" ")
    .toLowerCase()
}
