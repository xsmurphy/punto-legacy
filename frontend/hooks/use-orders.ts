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
import { api } from "@/lib/api-client"

// ── Tipos ─────────────────────────────────────────────────────────────────────

export type OrderStatus =
  | "open"
  | "sent"
  | "in_progress"
  | "ready"
  | "out_for_delivery"
  | "delivered"
  | "closed"
  | "cancelled"

export type OrderItemStatus = "pending" | "preparing" | "ready" | "delivered" | "cancelled"

/**
 * Cómo llega la orden al cliente (context/27-delivery-sla-plan.md §B.1,
 * F-D-0). Ortogonal a `source` (de dónde vino el pedido) — un pedido
 * telefónico puede ser delivery, y uno de ecommerce puede ser retiro en el
 * local. Una orden de espacio (`spaceSessionId`) siempre es `dine_in`, el
 * backend lo fuerza (OrderCoreService::create).
 */
export type Fulfillment = "dine_in" | "takeaway" | "delivery"

export interface OrderItem {
  id: string
  itemId: string | null
  name: string
  qty: number
  price: number | null
  note: string | null
  /**
   * Etiquetas de línea (uso interno — pedido del owner 2026-08-14: mismo
   * catálogo/comportamiento que las etiquetas de venta, salen en comandas,
   * NUNCA en facturas). `null` cuando la línea no tiene ninguna.
   */
  tags: string[] | null
  stationId: string | null
  stationName: string | null
  status: OrderItemStatus
  course: number
  createdAt: string | null
  readyAt: string | null
  deliveredAt: string | null
  /**
   * Add-on de otra línea de ESTA orden (context/41, mig 139). `null` = línea
   * normal, que es el 100% del tráfico de un comercio que no usa la feature.
   *
   * Las hijas llegan SIEMPRE inmediatamente después de su padre y agrupadas
   * con él (el backend lo garantiza en el ORDER BY, no el cliente), así que
   * recorrer `items` en orden ya alcanza para renderizarlas indentadas.
   */
  parentOrderItemId: string | null
  /** `addon_group_option.optionId` elegida — trazabilidad para reportes (F6). */
  addonOptionId: string | null
  /**
   * Recargo UNITARIO de la hija. `0` = la opción no suma (D2). `null` en las
   * líneas normales.
   *
   * NO es plata a sumar: el recargo ya está dentro del `price` de la línea
   * PADRE (el POS manda `CartLine.unitPrice`, que ya lo incluye). Sirve para
   * distinguir el add-on que cobra del que es gratis — que es lo único que
   * separa la comanda de cocina (lista todo) del ticket fiscal (D3).
   */
  priceDelta: number | null
}

/**
 * ¿Esta línea es un add-on de otra? (context/41)
 *
 * Definición ÚNICA de "línea hija" para todo el front — KDS, display, comanda
 * y carrito la comparten. Importa que sea una sola: una hija no se marca, no
 * se entrega, no se cobra y no vuelve al carrito por separado (el backend
 * rechaza el intento en `OrderCoreService::updateItemStatus`), así que si una
 * pantalla se olvidara del filtro pediría una transición que el server
 * rechaza y rompería la acción entera.
 */
export function isAddonChild(item: OrderItem): boolean {
  return item.parentOrderItemId !== null
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
  /** Motivo de la transición. Obligatorio en las cancelaciones (lo exige el backend). */
  reason: string | null
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
   * Repartidor asignado (F-D-1, context/27-delivery-sla-plan.md §B.6.2). Es
   * una PERSONA (staff en `contact`), no un dispositivo pareado — hoy la caja
   * lo asigna; solo relevante si `fulfillment==='delivery'`, el backend
   * rechaza asignarlo en el resto.
   */
  courierId: string | null
  /** Nombre del repartidor asignado (LEFT JOIN a `contact`, mismo patrón que `customerName`). Solo relevante si `fulfillment==='delivery'`. */
  courierName: string | null
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
  /**
   * Nombre del espacio resuelto server-side (LEFT JOIN `space_session` →
   * `space`). Solo para `source='table'`; null en el resto. Se consume vía
   * `orderDestination()`/`orderDestinationText()` (lib/orders/order-display.ts),
   * nunca directo.
   */
  spaceName: string | null
  userId: string | null
  note: string | null
  channelRef: string | null
  saleTransactionId: string | null
  createdAt: string | null
  sentAt: string | null
  closedAt: string | null
  /**
   * Cómo llega la orden al cliente (mig 94, F-D-0). Default `dine_in` para
   * toda orden creada antes de este slice.
   */
  fulfillment: Fulfillment
  /**
   * Snapshot del destino de entrega (mig 94, F-D-1 — congelado al crear, NO
   * se re-lee de `customerAddress`: el cliente puede mudarse/borrar la
   * dirección y esa orden sigue diciendo a dónde fue). Todo null salvo
   * `fulfillment==='delivery'`.
   */
  deliveryAddressId: string | null
  deliveryAddress: string | null
  deliveryReference: string | null
  deliveryLat: number | null
  deliveryLng: number | null
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
  /** Etiquetas de línea — espejo de `CartLine.tags` (lib/cart/store.ts). */
  tags?: string[]
  course?: number
  /**
   * Add-ons elegidos en la línea (context/41). MISMO shape que la venta (ver
   * `create-sale.ts`): SOLO `optionId` + `qty`. El nombre y el recargo los
   * resuelve el server contra la BD (`AddonService::validateSelections`) —
   * mandar la copia local sería mandar datos que el server descarta.
   *
   * Una línea SIN add-ons NO lleva la key.
   */
  selections?: { optionId: string; qty: number }[]
}

export interface CreateOrderInput {
  source?: "counter" | "table" | "ecommerce" | "schedule"
  /**
   * Sesión de espacio (space_session) a la que se asocia la orden (context/15
   * F2). El backend fuerza `source='table'` cuando está presente y valida
   * que la sesión sea del tenant+outlet y esté `open`.
   */
  spaceSessionId?: string
  /** Default 'dine_in' en el backend. El backend fuerza 'dine_in' si viene `spaceSessionId`. */
  fulfillment?: Fulfillment
  /** Requerido cuando `fulfillment==='delivery'` — el backend valida que sea del cliente. */
  deliveryAddressId?: string
  items: CreateOrderItemInput[]
  customerId?: string
  note?: string
  channelRef?: string
  sendNow?: boolean
  /**
   * "Orden en venta" (cobrar primero, producir después): la orden nace YA
   * PAGADA. El backend persiste `saletransactionid` en el mismo INSERT — no
   * hace falta un markPaid() posterior que además la cerraría antes de
   * tiempo (pago y ciclo de vida son ortogonales, ver OrderCoreService).
   */
  transactionId?: string
}

/**
 * Órdenes operativamente activas — excluye closed/cancelled (ver O0,
 * ORDER_TRANSITIONS).
 *
 * `delivered` SIGUE acá a propósito aunque ya no tenga pill de filtro
 * (ver FILTERABLE_STATUSES): una orden entregada pero NO cobrada —el caso
 * del delivery que se paga cuando el repartidor vuelve— tiene que seguir
 * siendo alcanzable desde "Todos" para poder cobrarla. Sacarla de acá
 * dejaría plata sin cobrar e invisible.
 */
export const ACTIVE_ORDER_STATUSES: OrderStatus[] = [
  "open",
  "sent",
  "in_progress",
  "ready",
  "out_for_delivery",
  "delivered",
]

/**
 * Detalles estructurados que el backend adjunta a un rechazo (`apiError(...,
 * $details)` → `error.details` en el envelope). Hoy los usa la anulación de
 * ítem para explicar POR QUÉ no se pudo: sin `windowMinutes`/`elapsedMinutes`
 * el cajero solo leería "no se pudo", que en una caja termina en una llamada
 * al encargado para averiguar qué pasó.
 */
export interface OrderApiErrorDetails {
  code?: string
  windowMinutes?: number
  elapsedMinutes?: number
  [key: string]: unknown
}

/**
 * Error de una request de órdenes, con el `status` HTTP y los `details` del
 * envelope.
 *
 * Existe porque `new Error(message)` pierde justo lo que la UI necesita para
 * decir algo accionable: un 403 (falta el permiso) y un 422 (ventana de
 * anulación vencida) llegaban indistinguibles al call-site, que solo podía
 * repetir el texto del server. Extiende `Error`, así que todos los consumidores
 * que ya leen `err.message` siguen igual — la información extra es aditiva.
 */
export class OrderApiError extends Error {
  readonly status: number
  readonly details: OrderApiErrorDetails | null

  constructor(message: string, status: number, details: OrderApiErrorDetails | null = null) {
    super(message)
    this.name = "OrderApiError"
    this.status = status
    this.details = details
  }
}

async function posJson<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await posFetch(url, init)
  const json = await res.json().catch(() => null)
  if (!res.ok || !json?.ok) {
    throw new OrderApiError(
      json?.error?.message ?? `Error ${res.status}`,
      res.status,
      (json?.error?.details as OrderApiErrorDetails | undefined) ?? null,
    )
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
 * Órdenes reales (`pos_order`) de un cliente — alimenta el tab "Órdenes" de
 * la ficha del cliente en el PANEL (T5, reporte del tester 2026-08-03). Antes
 * ese tab leía el reporte `orders` (transaction type=12, pedido online
 * legacy) y por eso nunca mostraba nada: las órdenes del módulo Órdenes viven
 * en `pos_order`, no en `transaction`.
 *
 * Va por `api` (cookie `_jwt_panel`), NO `posFetch` — el resto de este
 * archivo es Bearer de device porque alimenta /pos/ordenes; la ficha del
 * cliente es superficie de PANEL, mismo cliente HTTP que el resto de la
 * ficha (ver invariante de realm en `lib/api-client.ts`).
 */
export function useOrdersByCustomer(
  customerId: string | null,
  params?: { from?: string; to?: string },
) {
  return useQuery<{ orders: Order[] }>({
    queryKey: ["orders", "by-customer", customerId, params?.from, params?.to],
    queryFn: () => {
      const qs = new URLSearchParams({ customerId: customerId as string, includeItems: "1" })
      if (params?.from) qs.set("from", params.from)
      if (params?.to) qs.set("to", params.to)
      return api.get<{ orders: Order[] }>(`/v1/orders-core?${qs.toString()}`)
    },
    enabled: !!customerId,
    staleTime: 10 * 1000,
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

/**
 * Cambio manual de estado SIN motivo — cualquier transición de
 * `ORDER_TRANSITIONS` salvo `cancelled` (esa exige motivo obligatorio, ver
 * `useCancelOrder` abajo). Dos hooks separados a propósito: unificarlos en
 * uno solo con `reason?: string` opcional dejaría la puerta abierta a
 * cancelar sin motivo por el código path "genérico" — el backend lo
 * rechazaría, pero el bug de UX (perder el motivo tipeado) ya habría pasado.
 * Usado por el dropdown de estado de `OrderDetailView`.
 */
export function useUpdateOrderStatus() {
  const qc = useQueryClient()
  return useMutation<Order, Error, { orderId: string; status: OrderStatus }>({
    mutationFn: ({ orderId, status }) =>
      posJson<Order>(`/api/pos/orders?id=${orderId}&action=status`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status }),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["orders"] })
    },
  })
}

/**
 * Asigna o desasigna (courierId=null) el repartidor de una orden delivery.
 * Solo válido si `fulfillment==='delivery'` — el backend rechaza el resto.
 */
export function useAssignCourier() {
  const qc = useQueryClient()
  return useMutation<Order, Error, { orderId: string; courierId: string | null }>({
    mutationFn: ({ orderId, courierId }) =>
      posJson<Order>(`/api/pos/orders?id=${orderId}&action=assign-courier`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ courierId }),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["orders"] })
    },
  })
}

/**
 * Cancela una orden. Una orden NUNCA se elimina — se cancela, y el motivo es
 * OBLIGATORIO (lo exige `OrderCoreService::updateStatus`, no solo esta UI:
 * la regla vale para cualquier cliente). Queda registrado en el timeline
 * (`pos_order_event.reason`). Separado de `useUpdateOrderStatus` porque el
 * motivo no es opcional acá — un solo hook con parámetro opcional invitaría
 * a un call-site a saltearlo.
 */
export function useCancelOrder() {
  const qc = useQueryClient()
  return useMutation<Order, Error, { orderId: string; reason: string }>({
    mutationFn: ({ orderId, reason }) =>
      posJson<Order>(`/api/pos/orders?id=${orderId}&action=status`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status: "cancelled", reason }),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["orders"] })
    },
  })
}

/**
 * Anula UN ítem de una orden. El motivo es OBLIGATORIO, igual que en la
 * cancelación de la orden entera.
 *
 * Separado de cualquier mutación genérica de `item-status` por la MISMA razón
 * que `useCancelOrder` está separado de `useUpdateOrderStatus` (ver el
 * comentario de ese hook): un solo hook con `reason?: string` opcional deja
 * abierto el camino "genérico" para anular sin motivo. El backend lo
 * rechazaría, pero el motivo que el cajero ya tipeó se habría perdido, y ese
 * es el bug de UX que esta separación previene.
 *
 * Devuelve la orden COMPLETA (`updateItemStatus` presenta la orden entera),
 * así que el llamador no necesita refetch para pintar el estado nuevo — la
 * invalidación de `["orders"]` es igual la que usan las mutaciones vecinas,
 * porque el total de la sesión del espacio y el listado de órdenes también
 * cambian con esto.
 *
 * Errores: `OrderApiError` con `status` (403 = falta permiso, 422 = motivo
 * vacío o ventana vencida) y `details.code` para distinguirlos. Los mapea
 * `CancelOrderItemDialog`, que es el único lugar donde se decide el copy.
 */
export function useCancelOrderItem() {
  const qc = useQueryClient()
  return useMutation<Order, Error, { orderItemId: string; reason: string }>({
    mutationFn: ({ orderItemId, reason }) =>
      posJson<Order>(`/api/pos/orders?resource=item-status&id=${orderItemId}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status: "cancelled", reason }),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["orders"] })
    },
  })
}
