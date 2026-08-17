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
import { isAddonChild, type Order } from "@/hooks/use-orders"
import { orderDestinationText } from "@/lib/orders/order-display"

export function buildOrderTicketData(order: Order, config: PosConfig | null): TicketData {
  const catalogItems = useCatalogStore.getState().items
  const orderItems = (order.items ?? []).filter((oi) => oi.status !== "cancelled")

  // categoryId de cada línea PADRE, para que sus add-ons se impriman en la
  // MISMA comanda que ella. Este builder delega la partición por impresora en
  // `printSale`, que reparte por `categoryId` de la línea (ver la cabecera):
  // si la hija declarara la categoría de su propio producto, el "+ Queso
  // extra" podría salir en la impresora de la barra y la hamburguesa en la de
  // la plancha — media instrucción en un puesto que no prepara nada. Es el
  // mismo criterio con el que el backend le hereda `stationId` al add-on.
  const parentCategoryById = new Map<string, string | null>()
  for (const oi of orderItems) {
    if (!isAddonChild(oi)) {
      const catalogItem = oi.itemId ? catalogItems.find((c) => c.id === oi.itemId) : undefined
      parentCategoryById.set(oi.id, catalogItem?.categoryId ?? null)
    }
  }

  const items: TicketItem[] = orderItems.map((oi) => {
    const catalogItem = oi.itemId ? catalogItems.find((c) => c.id === oi.itemId) : undefined
    // Add-ons (context/41): la comanda lista TODOS, cobren o no — es de
    // cocina, y "sin cebolla" (priceDelta 0) es justamente la instrucción que
    // no puede faltar. La divergencia con el ticket fiscal, que solo lista los
    // add-ons con precio (D3), NO se resuelve acá: el ticket de la VENTA la
    // aplica con `includeFreeAddons` en buildTicketItemsFromTransaction, y
    // este builder es exclusivo de docType="order".
    //
    // `isAddonChild` es la ÚNICA señal que necesita la impresión: los dos
    // renderers (render-template.ts / html-renderer.ts) ya pasan cada línea
    // por `ticketItemName()`, que le antepone el prefijo de indentación. No se
    // arma ningún mecanismo paralelo de sangría.
    const addonChild = isAddonChild(oi)
    // El recargo del add-on ya está cobrado en el precio del padre, así que la
    // hija va con importe 0 y la comanda no muestra plata duplicada. La
    // cantidad SÍ es la suya (2 hamburguesas con queso = 2 quesos).
    const unitPrice = oi.price ?? 0
    return {
      name: oi.name,
      qty: oi.qty,
      unitPrice,
      discountAmount: 0,
      discountPercent: 0,
      total: unitPrice * oi.qty,
      isAddonChild: addonChild,
      categoryId: addonChild
        ? (parentCategoryById.get(oi.parentOrderItemId ?? "") ?? null)
        : (catalogItem?.categoryId ?? null),
      id: oi.itemId,
      uid: catalogItem?.sku ?? null,
      note: oi.note,
      // Etiquetas de línea (uso interno, pedido owner 2026-08-14) — viajan
      // desde CartLine.tags → CreateOrderItemInput.tags → pos_order_item.tags
      // (mig 135) → OrderItem.tags. NO se imprimen en facturas: eso depende
      // de que la plantilla de la impresora fiscal no incluya el bloque
      // `item_tags` (blocks.ts) — este builder es solo para docType="order".
      tags: oi.tags,
      // Comanda: se imprime ANTES de cobrar, sin impuesto congelado ni
      // motor corrido sobre estas líneas (F3b no lo pide — el ticket fiscal
      // es el de la venta, no la comanda de cocina/estación).
      taxId: null,
      taxRate: null,
      taxKind: null,
      taxIncluded: null,
      taxAmount: null,
      taxNet: null,
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
    currency: config?.currency ?? null,
    thousand: config?.thousand ?? null,
    decimal: config?.decimal ?? null,
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
