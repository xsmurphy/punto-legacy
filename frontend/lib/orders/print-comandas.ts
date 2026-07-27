/**
 * Impresión de comandas por estación (O1, context/24-orders-module-plan.md).
 *
 * Decisión de diseño (deviation documentada en context/24): la orden ya
 * resuelve `stationId` por ítem server-side (OrderCoreService::resolveStationId,
 * categorías → order_station), pero el pipeline de impresión existente
 * (`printSale`/`getBindingsForSale`) parte tickets por `categoryId` de ítem,
 * NO por stationId — es el mecanismo único que ya usa toda la impresión del
 * POS (factura/recibo/cotización). En vez de duplicar esa lógica de partición
 * con un segundo mecanismo basado en stationId, reusamos el MISMO pipeline:
 * resolvemos el `categoryId` real de cada ítem de la orden (vía `itemId` →
 * catálogo, igual que `buildTicketItemsFromTransaction`) y dejamos que
 * `printSale` arme un ticket por binding con docType="order" — un solo lugar
 * de verdad para "qué impresora recibe qué ítems", en vez de dos.
 *
 * Si no hay ninguna impresora con `docTypes` incluyendo "order", `printSale`
 * devuelve `{printed:0, failed:0, errors:[]}` — silencioso, sin toast (ver
 * brief O1: "si no hay bindings, silently skip").
 */

import { printSale } from "@/lib/hardware/printers"
import type { PrinterBinding } from "@/lib/hardware/printers/binding"
import type { TicketData, TicketItem } from "@/lib/hardware/printers/build-ticket-data"
import { useCatalogStore } from "@/lib/catalog/store"
import type { PosConfig } from "@/lib/types/pos-bootstrap"
import type { Order } from "@/hooks/use-orders"
import { orderDestinationText } from "@/lib/orders/order-display"

export function buildOrderTicketData(order: Order, config: PosConfig | null): TicketData {
  const catalogItems = useCatalogStore.getState().items
  const orderItems = (order.items ?? []).filter((oi) => oi.status !== "cancelled")

  const items: TicketItem[] = orderItems.map((oi) => {
    const catalogItem = oi.itemId ? catalogItems.find((c) => c.id === oi.itemId) : undefined
    const unitPrice = oi.price ?? 0
    return {
      name: oi.name,
      qty: oi.qty,
      unitPrice,
      discount: 0,
      total: unitPrice * oi.qty,
      categoryId: catalogItem?.categoryId ?? null,
    }
  })

  return {
    companyName: config?.companyName ?? "",
    docType: "order",
    ticketNo: order.orderNumber != null ? String(order.orderNumber) : undefined,
    transactionId: order.id,
    date: order.createdAt ?? order.sentAt ?? new Date().toISOString(),
    items,
    subtotal: items.reduce((s, i) => s + i.total, 0),
    discount: 0,
    taxTotal: 0,
    total: items.reduce((s, i) => s + i.total, 0),
    payments: [],
    note: order.note ?? undefined,
    // A DÓNDE VA es lo primero que mira quien arma la comanda — ver el
    // forzado de esta línea en render-template.ts/html-renderer.ts.
    orderDestination: orderDestinationText(order),
  }
}

/**
 * Imprime la comanda de una orden, particionada por estación (vía el mismo
 * matching de categoryId que usa printSale para factura/recibo). Best-effort:
 * nunca lanza — el caller decide qué hacer con el resultado (toast warning
 * en fallas, sin toast si no hay bindings de docType="order").
 */
export async function printOrderComandas(
  order: Order,
  allBindings: PrinterBinding[],
  config: PosConfig | null,
): Promise<{ printed: number; failed: number; errors: string[] }> {
  if (!order.items || order.items.length === 0) {
    return { printed: 0, failed: 0, errors: [] }
  }
  const data = buildOrderTicketData(order, config)
  return printSale({ docType: "order", data, bindings: allBindings })
}
