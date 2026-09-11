/**
 * Etiquetas de la línea de tiempo de una orden (`pos_order_event`).
 *
 * Lo comparten el detalle de la caja (`components/orders/order-detail-view.tsx`)
 * y el del panel (`app/(panel)/orders/[id]/page.tsx`). Vive en un archivo
 * propio y no en `order-display.ts` porque necesita `KDS_ITEM_VISUALS`, y
 * `kds-visuals.ts` ya importa de `order-display.ts`: meterlo ahí armaba un
 * import circular que rompe en la inicialización del módulo.
 */

import type { OrderEvent, OrderStatus } from "@/hooks/use-orders"
import { KDS_ITEM_VISUALS } from "@/lib/kds/kds-visuals"
import { DEVICE_KIND_LABELS, type DeviceKind } from "@/lib/devices/connected-device"
import { ACTOR_KIND_LABEL, STATUS_LABEL } from "@/lib/orders/order-display"

/**
 * Etiqueta legible de un extremo de la transición. El historial mezcla eventos
 * de ORDEN (`open`, `sent`, …) y de ÍTEM (`pending`, `preparing`, …): son dos
 * máquinas de estado distintas, así que se resuelve contra el mapa que
 * corresponda según el `scope` del evento. Sin esto se imprimía el valor crudo
 * de la BD ("open → sent"), que no le dice nada a quien atiende.
 */
export function eventStatusLabel(scope: OrderEvent["scope"], status: string | null): string {
  if (!status) return ""
  if (scope === "item") {
    return KDS_ITEM_VISUALS[status as keyof typeof KDS_ITEM_VISUALS]?.label ?? status
  }
  return STATUS_LABEL[status as OrderStatus] ?? status
}

/** "Enviada → En proceso", o solo el destino en el evento de creación. */
export function eventTransitionLabel(ev: OrderEvent): string {
  return ev.fromStatus
    ? `${eventStatusLabel(ev.scope, ev.fromStatus)} → ${eventStatusLabel(ev.scope, ev.toStatus)}`
    : eventStatusLabel(ev.scope, ev.toStatus)
}

/**
 * Quién movió el estado: tipo de actor y, si se sabe, desde qué superficie
 * ("Dispositivo · Caja POS"). El módulo se traduce con las mismas etiquetas
 * que la pantalla de dispositivos; `panel` no es un dispositivo y va aparte.
 */
export function eventActorLabel(ev: OrderEvent): string {
  const kind = ACTOR_KIND_LABEL[ev.actorKind] ?? ev.actorKind
  if (!ev.actorModule) return kind
  const module =
    ev.actorModule === "panel"
      ? "Panel"
      : (DEVICE_KIND_LABELS[ev.actorModule as DeviceKind] ?? ev.actorModule)
  return `${kind} · ${module}`
}
