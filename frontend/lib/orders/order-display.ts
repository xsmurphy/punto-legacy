/**
 * Presentación compartida de órdenes en el POS — etiquetas, totales y
 * resúmenes de ítems usados por las tres vistas de /pos/ordenes (cuadros,
 * lista y mapa). Vive fuera de `page.tsx` para que las tres consuman
 * exactamente los mismos labels y el mismo cálculo de total.
 */

import type { Order, OrderStatus } from "@/hooks/use-orders"

export const STATUS_LABEL: Record<OrderStatus, string> = {
  open: "Abierta",
  sent: "Enviada",
  in_progress: "En preparación",
  ready: "Lista",
  delivered: "Entregada",
  closed: "Cobrada",
  cancelled: "Cancelada",
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

/**
 * Estados filtrables desde la barra flotante — son los ACTIVOS de la máquina
 * de estados (`ORDER_TRANSITIONS` en api/lib/Orders/OrderCoreService.php).
 * `closed`/`cancelled` no aparecen porque el listado del POS ya los excluye
 * (ver ACTIVE_ORDER_STATUSES).
 */
export const FILTERABLE_STATUSES: OrderStatus[] = [
  "open",
  "sent",
  "in_progress",
  "ready",
  "delivered",
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
