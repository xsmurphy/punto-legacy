/**
 * Presentación compartida de órdenes en el POS — etiquetas, totales y
 * resúmenes de ítems usados por las tres vistas de /pos/ordenes (cuadros,
 * lista y mapa). Vive fuera de `page.tsx` para que las tres consuman
 * exactamente los mismos labels y el mismo cálculo de total.
 */

import type { Order, OrderStatus } from "@/hooks/use-orders"

/**
 * Etiquetas operativas (decisión del owner 2026-07-19). Nombran los estados
 * desde la óptica del mostrador, no de la cocina:
 *   open        → "Pendiente"  (se está armando, todavía no salió a preparar)
 *   sent        → "En espera"  (ya salió a preparar, esperando ser tomada)
 *   in_progress → "En proceso"
 *   ready       → "Enviado"    (despachada desde el mostrador)
 * Los estados de la máquina NO cambian — esto es solo presentación.
 */
export const STATUS_LABEL: Record<OrderStatus, string> = {
  open: "Pendiente",
  sent: "En espera",
  in_progress: "En proceso",
  // "Listo" es la etiqueta base. "Enviado" corresponde SOLO a los pedidos
  // marcados como delivery (decisión del owner) — eso llega con `fulfillment`
  // (context/27 §B.1, todavía sin implementar). Hasta entonces ninguna orden
  // es delivery, así que "Listo" es correcto para todas.
  ready: "Listo",
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
