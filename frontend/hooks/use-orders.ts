"use client"

/**
 * Hooks del módulo de Órdenes para el POS (O1, context/24-orders-module-plan.md).
 *
 * El device POS corre con Bearer del device (realm `pos-app`), no con la
 * cookie `_jwt_panel` del panel — mismo motivo que `use-pos-outlets.ts` /
 * `use-pos-config.ts`. Va por `posFetch` contra el BFF `/api/pos/orders`,
 * que proxea a `/v1/orders-core.php` reenviando el query string completo.
 *
 * Invalidación: `order` está mapeado en `ENTITY_TO_QUERY_KEYS`
 * (hooks/use-realtime-sync.ts) a `["orders"]` — cualquier mutación server-side
 * (create/send/status/mark-paid/item-status) publica `realtimePublish('order',
 * ...)` y el socket dispara la invalidación acá. NO consumimos el canal KDS
 * (`{companyId}:kds:{outletId}`) — eso es scope de O2.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"

// ── Tipos ─────────────────────────────────────────────────────────────────────

export type OrderStatus =
  | "open"
  | "sent"
  | "in_progress"
  | "ready"
  | "delivered"
  | "closed"
  | "cancelled"

export type OrderItemStatus = "pending" | "preparing" | "ready" | "delivered" | "cancelled"

export interface OrderItem {
  id: string
  itemId: string | null
  name: string
  qty: number
  price: number | null
  note: string | null
  stationId: string | null
  stationName: string | null
  status: OrderItemStatus
  course: number
  createdAt: string | null
  readyAt: string | null
  deliveredAt: string | null
}

/**
 * Un renglón del historial de transiciones (F-EVT-0,
 * context/27-delivery-sla-plan.md PARTE C, `pos_order_event`). Solo viene en
 * el detalle de la orden (`find()`) — `list()` no lo trae, ver
 * `OrderCoreService::presentOrder()`.
 */
export interface OrderEvent {
  scope: "order" | "item"
  orderItemId: string | null
  stationId: string | null
  stationName: string | null
  fromStatus: OrderStatus | OrderItemStatus | null
  toStatus: OrderStatus | OrderItemStatus
  actorKind: "user" | "device" | "system"
  actorModule: string | null
  createdAt: string | null
}

export interface Order {
  id: string
  companyId: string
  outletId: string
  registerId: string | null
  source: "counter" | "table" | "ecommerce" | "schedule"
  status: OrderStatus
  orderNumber: number | null
  spaceSessionId: string | null
  customerId: string | null
  /**
   * Datos del cliente resueltos server-side (LEFT JOIN a `contact` en
   * `OrderCoreService::list()`/`find()`). `customerLat`/`customerLng` salen de
   * `contact.data->>'contactLatLng'` (string legacy "lat,lng") ya parseadas y
   * validadas — null cuando el cliente no tiene ubicación cargada o el valor
   * está malformado. Alimentan la vista mapa de /pos/ordenes.
   */
  customerName: string | null
  customerLat: number | null
  customerLng: number | null
  userId: string | null
  note: string | null
  channelRef: string | null
  saleTransactionId: string | null
  createdAt: string | null
  sentAt: string | null
  closedAt: string | null
  /**
   * Presente en `find()` (detalle) siempre, y en `list()` solo si se pidió
   * `includeItems=1` (ej. `useOrdersBySession` — dialog de sesión de
   * espacio, context/15 F3). Ausente en `list()` sin ese flag.
   */
  items?: OrderItem[]
  /** Timeline de transiciones — solo presente en el detalle (`find()`), ver OrderEvent. */
  events?: OrderEvent[]
}

export interface CreateOrderItemInput {
  itemId?: string
  qty: number
  price?: number
  note?: string
  course?: number
}

export interface CreateOrderInput {
  source?: "counter" | "table" | "ecommerce" | "schedule"
  /**
   * Sesión de espacio (space_session) a la que se asocia la orden (context/15
   * F2). El backend fuerza `source='table'` cuando está presente y valida
   * que la sesión sea del tenant+outlet y esté `open`.
   */
  spaceSessionId?: string
  items: CreateOrderItemInput[]
  customerId?: string
  note?: string
  channelRef?: string
  sendNow?: boolean
}

/** Órdenes operativamente activas — excluye closed/cancelled (ver O0, ORDER_TRANSITIONS). */
export const ACTIVE_ORDER_STATUSES: OrderStatus[] = [
  "open",
  "sent",
  "in_progress",
  "ready",
  "delivered",
]

async function posJson<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await posFetch(url, init)
  const json = await res.json().catch(() => null)
  if (!res.ok || !json?.ok) {
    throw new Error(json?.error?.message ?? `Error ${res.status}`)
  }
  return json.data as T
}

// ── Queries ───────────────────────────────────────────────────────────────────

/**
 * Lista de órdenes activas del outlet del device — alimenta /pos/ordenes.
 *
 * Pide `includeItems=1`: el service resuelve los ítems de TODAS las órdenes en
 * una sola query batched (`loadItemsByOrderIds`). Antes cada card hacía su
 * propio `useOrder(order.id)` → N+1 de requests para pintar un listado. Con
 * los ítems en la respuesta del listado, las tres vistas (cuadros, lista,
 * mapa) calculan total y resumen sin fetch adicional.
 */
export function useActiveOrders() {
  return useQuery<{ orders: Order[] }>({
    queryKey: ["orders", "active"],
    queryFn: () => {
      const qs = new URLSearchParams()
      for (const s of ACTIVE_ORDER_STATUSES) qs.append("status[]", s)
      qs.set("includeItems", "1")
      return posJson<{ orders: Order[] }>(`/api/pos/orders?${qs.toString()}`)
    },
    staleTime: 10 * 1000,
    refetchInterval: 15 * 1000,
  })
}

/** Detalle de una orden (con ítems) — usado por las cards para Cobrar/Reimprimir. */
export function useOrder(orderId: string | null) {
  return useQuery<Order>({
    queryKey: ["orders", "detail", orderId],
    queryFn: () => posJson<Order>(`/api/pos/orders?id=${orderId}`),
    enabled: !!orderId,
    staleTime: 5 * 1000,
  })
}

/**
 * Órdenes de una sesión de espacio (todas, cualquier status — el diálogo del
 * espacio muestra el historial completo de rondas). Pide `includeItems=1`
 * para que el service adjunte los ítems de cada orden en la misma respuesta
 * (una sola query batched server-side, sin N+1 de fetches por orden — ver
 * `OrderCoreService::list()`), consumido por `SpaceSessionDialog` para
 * mostrar el detalle de ítems y el total de la sesión (context/15 F3).
 */
export function useOrdersBySession(sessionId: string | null) {
  return useQuery<{ orders: Order[] }>({
    queryKey: ["orders", "session", sessionId],
    queryFn: () =>
      posJson<{ orders: Order[] }>(`/api/pos/orders?spaceSessionId=${sessionId}&includeItems=1`),
    enabled: !!sessionId,
    staleTime: 5 * 1000,
  })
}

/**
 * Detalle de una orden fuera del ciclo de hooks — usado por el flujo
 * "Cobrar el espacio" (context/15 F2) para resolver los ítems de cada orden de
 * la sesión antes de armar el merge en `loadFromSession`.
 */
export function fetchOrderDetail(orderId: string): Promise<Order> {
  return posJson<Order>(`/api/pos/orders?id=${orderId}`)
}

/**
 * Igual a `useOrdersBySession` pero fuera del ciclo de hooks — usado por el
 * handler de "Cobrar" en `/pos/espacios` para resolver qué órdenes son
 * billable (no closed/cancelled) antes de pedir su detalle completo.
 * `includeItems=1` por consistencia con `useOrdersBySession` — mismo endpoint,
 * mismo shape esperado.
 */
export function fetchOrdersBySession(sessionId: string): Promise<{ orders: Order[] }> {
  return posJson<{ orders: Order[] }>(`/api/pos/orders?spaceSessionId=${sessionId}&includeItems=1`)
}

// ── Mutaciones ────────────────────────────────────────────────────────────────

export function useCreateOrder() {
  const qc = useQueryClient()
  return useMutation<Order, Error, CreateOrderInput>({
    mutationFn: (data) =>
      posJson<Order>("/api/pos/orders", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["orders"] })
    },
  })
}

export function useMarkOrderPaid() {
  const qc = useQueryClient()
  return useMutation<Order, Error, { orderId: string; transactionId: string }>({
    mutationFn: ({ orderId, transactionId }) =>
      posJson<Order>(`/api/pos/orders?id=${orderId}&action=mark-paid`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ transactionId }),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["orders"] })
    },
  })
}

export function useCancelOrder() {
  const qc = useQueryClient()
  return useMutation<Order, Error, string>({
    mutationFn: (orderId) =>
      posJson<Order>(`/api/pos/orders?id=${orderId}&action=status`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status: "cancelled" }),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["orders"] })
    },
  })
}
