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

export interface Order {
  id: string
  companyId: string
  outletId: string
  registerId: string | null
  source: "counter" | "table" | "ecommerce" | "schedule"
  status: OrderStatus
  orderNumber: number | null
  tableSessionId: string | null
  customerId: string | null
  userId: string | null
  note: string | null
  channelRef: string | null
  saleTransactionId: string | null
  createdAt: string | null
  sentAt: string | null
  closedAt: string | null
  /** Solo presente en `find()` (detalle) — `list()` no trae ítems. */
  items?: OrderItem[]
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
   * Sesión de mesa (table_session) a la que se asocia la orden (context/15
   * F2). El backend fuerza `source='table'` cuando está presente y valida
   * que la sesión sea del tenant+outlet y esté `open`.
   */
  tableSessionId?: string
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

/** Lista de órdenes activas del outlet del device — alimenta /pos/ordenes. */
export function useActiveOrders() {
  return useQuery<{ orders: Order[] }>({
    queryKey: ["orders", "active"],
    queryFn: () => {
      const qs = new URLSearchParams()
      for (const s of ACTIVE_ORDER_STATUSES) qs.append("status[]", s)
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
 * Órdenes de una sesión de mesa (todas, cualquier status — el sheet de la
 * mesa muestra el historial completo de rondas; `loadFromSession` en el
 * cart store filtra client-side las billable). `list()` no trae ítems, así
 * que cada card sigue pidiendo su detalle si necesita mostrarlos — acá se
 * usa `useOrder` por cada id cuando hace falta el contenido para "Cobrar".
 */
export function useOrdersBySession(sessionId: string | null) {
  return useQuery<{ orders: Order[] }>({
    queryKey: ["orders", "session", sessionId],
    queryFn: () => posJson<{ orders: Order[] }>(`/api/pos/orders?tableSessionId=${sessionId}`),
    enabled: !!sessionId,
    staleTime: 5 * 1000,
  })
}

/**
 * Detalle de una orden fuera del ciclo de hooks — usado por el flujo
 * "Cobrar la mesa" (context/15 F2) para resolver los ítems de cada orden de
 * la sesión antes de armar el merge en `loadFromSession` (necesita
 * `Order.items`, que `list()`/`useOrdersBySession` no traen).
 */
export function fetchOrderDetail(orderId: string): Promise<Order> {
  return posJson<Order>(`/api/pos/orders?id=${orderId}`)
}

/**
 * Igual a `useOrdersBySession` pero fuera del ciclo de hooks — usado por el
 * handler de "Cobrar" en `/pos/mesas` para resolver qué órdenes son
 * billable (no closed/cancelled) antes de pedir su detalle completo.
 */
export function fetchOrdersBySession(sessionId: string): Promise<{ orders: Order[] }> {
  return posJson<{ orders: Order[] }>(`/api/pos/orders?tableSessionId=${sessionId}`)
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
