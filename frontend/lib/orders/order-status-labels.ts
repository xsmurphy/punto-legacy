/**
 * Nombres de las etapas de una orden — ÚNICA fuente del front.
 *
 * El comercio puede renombrar las etapas desde Ajustes (pedido del owner
 * 2026-09-17). SOLO nombres: la máquina de estados, las transiciones, la
 * cocina, la pantalla de mozos, el delivery y los reportes siguen operando
 * con los códigos. Nada acá agrega, quita ni reordena etapas.
 *
 * Resolución: nombre del comercio → nombre de fábrica. El comercio llega por
 * el bootstrap del panel, el de la caja (offline con el snapshot) y el contexto
 * de las pantallas; en React se toma con `useOrderStatusLabels()`
 * (`components/orders/order-status-labels-provider.tsx`). El backend solo
 * guarda las claves renombradas y NO conoce los nombres de fábrica: este mapa
 * no tiene copia en ningún otro lado.
 *
 * Nombres de fábrica (decisión del owner 2026-07-19), desde la óptica del
 * mostrador y no de la cocina:
 *   open        → "Pendiente"  (se está armando, todavía no salió a preparar)
 *   sent        → "En espera"  (ya salió a preparar, esperando ser tomada)
 *   in_progress → "En proceso"
 *   ready       → "Listo", y "Enviado" cuando la orden es de envío
 *
 * ⚠ TENSIÓN sin resolver (2026-07-28, F-D-1): con `out_for_delivery`
 * existiendo, "Enviado" (ready + delivery) y "En camino" quedan muy cerca —
 * "Enviado" en realidad es "listo, esperando al cadete". Desde que el
 * comercio puede renombrar el slot `ready_delivery`, cada uno lo resuelve con
 * sus palabras; el nombre de fábrica sigue siendo el que fijó el owner.
 */

import type { Order, OrderStatus } from "@/hooks/use-orders"

/** Una etapa renombrable: los estados de la orden + `ready` de una orden de envío. */
export type OrderStatusLabelKey = OrderStatus | "ready_delivery"

/** Solo las etapas que el comercio renombró. Lo ausente es el nombre de fábrica. */
export type OrderStatusLabels = Partial<Record<OrderStatusLabelKey, string>>

/** Largo máximo de un nombre. Mismo tope que `OrderStatusLabels::MAX_LENGTH` (api). */
export const ORDER_STATUS_LABEL_MAX = 24

export const DEFAULT_ORDER_STATUS_LABELS: Record<OrderStatusLabelKey, string> = {
  open: "Pendiente",
  sent: "En espera",
  in_progress: "En proceso",
  ready: "Listo",
  ready_delivery: "Enviado",
  out_for_delivery: "En camino",
  delivered: "Entregada",
  closed: "Cobrada",
  cancelled: "Cancelada",
}

/** Orden del flujo, para listar las etapas (Ajustes). */
export const ORDER_STATUS_LABEL_KEYS: OrderStatusLabelKey[] = [
  "open",
  "sent",
  "in_progress",
  "ready",
  "ready_delivery",
  "out_for_delivery",
  "delivered",
  "closed",
  "cancelled",
]

function isLabelKey(key: string): key is OrderStatusLabelKey {
  return (ORDER_STATUS_LABEL_KEYS as string[]).includes(key)
}

/**
 * Normaliza lo que llegue del bootstrap/contexto (o de un snapshot offline
 * viejo, que puede no traer la clave) a un `OrderStatusLabels` limpio: solo
 * claves conocidas y nombres no vacíos. El backend ya lo normalizó; esto es
 * para no confiar en la forma de un cache.
 */
export function normalizeOrderStatusLabels(raw: unknown): OrderStatusLabels {
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return {}
  const out: OrderStatusLabels = {}
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    if (!isLabelKey(key) || typeof value !== "string") continue
    const name = value.trim()
    if (name) out[key] = name
  }
  return out
}

/**
 * Nombre de una etapa suelta — cuando no hay una orden a mano (filtros, el
 * menú de cambio de estado, el historial). `ready` sale con su nombre general;
 * para una orden completa usar `orderStatusLabelFor`, que resuelve el de envío.
 * Un código desconocido se devuelve tal cual.
 */
export function orderStatusLabel(
  status: OrderStatusLabelKey | string,
  labels?: OrderStatusLabels | null,
): string {
  if (!isLabelKey(status)) return status
  return labels?.[status] || DEFAULT_ORDER_STATUS_LABELS[status]
}

/** Nombre de la etapa de una orden completa: `ready` de una orden de envío usa su propio nombre. */
export function orderStatusLabelFor(
  order: Pick<Order, "status" | "fulfillment">,
  labels?: OrderStatusLabels | null,
): string {
  if (order.status === "ready" && order.fulfillment === "delivery") {
    return orderStatusLabel("ready_delivery", labels)
  }
  return orderStatusLabel(order.status, labels)
}
