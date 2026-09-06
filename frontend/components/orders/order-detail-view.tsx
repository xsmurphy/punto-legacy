"use client"

/**
 * Detalle de una orden — mismo formato que `TransactionDetail`
 * (components/register/pos-transactions-dialog.tsx): header con
 * cliente + metadatos, monto + split button arriba a la derecha, bloques
 * `rounded-lg bg-muted/40 p-4` para Items e Historial.
 *
 * Es el contenido del Dialog de detalle en /pos/ordenes (vistas Lista y
 * Mapa) — NO reemplaza a `OrderCard`, que sigue siendo la card de la vista
 * Cuadros con su propio layout compacto.
 *
 * Cobrar/Reimprimir/Cancelar salen de `useOrderActions`, compartido con
 * `OrderCard` — una sola definición para las dos superficies.
 */

import * as React from "react"
import { Ban, ChevronRight, DollarSign, MoreHorizontal, Truck } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible"
import { ActionMenu } from "@/components/ui/action-menu"
import { cn } from "@/lib/utils"
import { formatRelativeShort, formatDateTime } from "@/lib/format-date"
import { formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import { useLockStore } from "@/lib/pos/lock-store"
import { useOrderActions } from "@/hooks/use-order-actions"
import { CancelOrderItemDialog } from "@/components/orders/cancel-order-item-dialog"
import { CancelOrderDialog } from "@/components/orders/cancel-order-dialog"
import { KDS_ITEM_VISUALS } from "@/lib/kds/kds-visuals"
import { SellerPickerDialog } from "@/components/pos/seller-picker-dialog"
import { OrderStatusBadge } from "@/components/orders/order-status-badge"
import {
  useAssignCourier,
  useOrder,
  type Order,
  type OrderItem,
  type OrderStatus,
  type OrderEvent,
} from "@/hooks/use-orders"
import {
  ACTOR_KIND_LABEL,
  STATUS_LABEL,
  canCancelOrderItem,
  orderDestination,
  orderTotal,
} from "@/lib/orders/order-display"


/**
 * Etiqueta legible de un extremo de la transición. El historial mezcla eventos
 * de ORDEN (`open`, `sent`, …) y de ÍTEM (`pending`, `preparing`, …): son dos
 * máquinas de estado distintas, así que se resuelve contra el mapa que
 * corresponda según el `scope` del evento. Sin esto se imprimía el valor crudo
 * de la BD ("open → sent"), que no le dice nada a quien atiende.
 */
function eventStatusLabel(scope: OrderEvent["scope"], status: string | null): string {
  if (!status) return ""
  if (scope === "item") {
    return KDS_ITEM_VISUALS[status as keyof typeof KDS_ITEM_VISUALS]?.label ?? status
  }
  return STATUS_LABEL[status as OrderStatus] ?? status
}

export function OrderDetailView({
  order,
  onAfterAction,
}: {
  order: Order
  /** La dispara el Dialog contenedor para cerrarse tras cobrar/cancelar. */
  onAfterAction?: () => void
}) {
  const config = useCatalogStore((s) => s.config)
  const assignCourier = useAssignCourier()
  const [courierPickerOpen, setCourierPickerOpen] = React.useState(false)
  // Mismas acciones que la card de la vista Cuadros — `useOrderActions` es la
  // única definición de Cobrar/Reimprimir/Cancelar.
  const actions = useOrderActions(order, onAfterAction)
  const { cobrar, isPaid, reprint, printing, setCancelOpen } = actions

  // `events` (F-EVT-0) solo viene en el detalle — el listado no lo trae.
  // El resto (header, items, monto) ya está disponible en `order` (la lista
  // pide includeItems=1), así que no hace falta esperar este fetch para
  // pintar el grueso del panel.
  const { data: detail, isLoading: eventsLoading } = useOrder(order.id)

  /**
   * Anulación de un ítem suelto — gated por el permiso del OPERADOR del PIN,
   * no por el del device (mismo criterio que el conteo de stock en la caja).
   * Sin el permiso la columna no se renderiza para ninguna fila; con él existe
   * siempre, vacía en los ítems que ya no se pueden anular.
   */
  const operatorPermissions = useLockStore((s) => s.operatorPermissions)
  const canCancelItems = operatorPermissions.includes("pos.order.item.cancel")
  const [itemToCancel, setItemToCancel] = React.useState<OrderItem | null>(null)

  /**
   * Motivo de anulación POR ÍTEM, resuelto contra el timeline.
   *
   * El historial de abajo ya lista los eventos con su `reason`, pero para
   * entender por qué falta un plato había que abrirlo y cruzar el evento con
   * la línea. Acá el motivo se lee en la línea misma, que es donde se hace la
   * pregunta. La última transición a `cancelled` gana (los eventos llegan en
   * orden cronológico).
   */
  const cancelReasonByItem = React.useMemo(() => {
    const map = new Map<string, string>()
    for (const ev of detail?.events ?? []) {
      if (ev.scope === "item" && ev.orderItemId && ev.toStatus === "cancelled" && ev.reason) {
        map.set(ev.orderItemId, ev.reason)
      }
    }
    return map
  }, [detail])

  const items = order.items ?? []
  const hasItems = items.length > 0
  const total = orderTotal(order)
  const destination = orderDestination(order)
  const timeIso = order.sentAt ?? order.createdAt

  function handleAssignCourier(courierId: string | null) {
    assignCourier.mutate(
      { orderId: order.id, courierId },
      {
        onSuccess: () => toast.success(courierId ? "Repartidor asignado" : "Repartidor quitado"),
        onError: (err) => toast.error("No se pudo asignar el repartidor", { description: err.message }),
      },
    )
  }

  return (
    <div className="flex flex-col gap-0">
      {/* ── Header: cliente + monto top-right + split button ─────────────── */}
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <h2 className="text-xl font-semibold truncate">
            {order.customerName || <span className="text-muted-foreground">Sin cliente</span>}
          </h2>
          <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <span className="flex items-center gap-1">
              <destination.icon className="size-3.5" aria-hidden />
              {destination.label}
            </span>
            <span className="tabular-nums">#{order.orderNumber ?? "—"}</span>
            {timeIso && (
              <span className="tabular-nums" title={formatDateTime(timeIso, "d MMM yyyy HH:mm")}>
                {formatRelativeShort(timeIso)}
              </span>
            )}
            <OrderStatusBadge order={order} />
          </div>
        </div>
        {/* pr-10: el botón X del Dialog es `absolute top-4 right-4` y quedaba
            encima del monto (que es lo más grande del header). El padding le
            reserva su lugar en vez de superponerse. */}
        <div className="shrink-0 flex flex-col items-end gap-2 pr-10">
          <p className="text-2xl font-bold tabular-nums">{formatMoney(total, config)}</p>
          {/* Split button — misma geometría que el de `TransactionDetail`:
              `items-stretch` y tier `icon-sm` en el trigger para que en móvil
              los dos lleguen a los 44px del mínimo táctil sin deformarse. Los
              ítems van sin icono: menú de acciones = texto solo (owner). */}
          <div className="inline-flex items-stretch">
            <Button
              size="sm"
              className="rounded-r-none border-r-0 gap-1.5"
              disabled={!hasItems || isPaid}
              onClick={cobrar}
            >
              <DollarSign className="size-3.5" />
              {isPaid ? "Pagada" : "Cobrar"}
            </Button>
            <ActionMenu
              title="Acciones de la orden"
              trigger={
                <Button size="icon-sm" className="rounded-l-none" aria-label="Más acciones">
                  <MoreHorizontal className="size-4" />
                </Button>
              }
              actions={[
                {
                  label: "Reimprimir comanda",
                  disabled: !hasItems || printing,
                  onSelect: () => void reprint(),
                },
                {
                  label: "Cancelar orden",
                  variant: "destructive",
                  onSelect: () => setCancelOpen(true),
                },
              ]}
            />
          </div>
        </div>
      </div>

      {/* ── Items ──────────────────────────────────────────────────────────── */}
      <div className="mt-5 rounded-lg bg-muted/40 p-4">
        <h3 className="text-sm font-medium text-muted-foreground mb-2">
          Items ({items.length})
        </h3>
        <div className="divide-y divide-border/60">
          {items.length === 0 ? (
            <p className="text-sm text-muted-foreground py-2">Sin items</p>
          ) : (
            items.map((item) => {
              const cancelled = item.status === "cancelled"
              const reason = cancelReasonByItem.get(item.id)
              return (
                <div key={item.id} className="flex items-center justify-between py-2 text-sm gap-3">
                  <div className="min-w-0 flex-1">
                    <span
                      className={cn(
                        "block truncate",
                        cancelled && "text-muted-foreground line-through",
                      )}
                    >
                      {item.name}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {KDS_ITEM_VISUALS[item.status].label}
                    </span>
                    {cancelled && reason && (
                      <span className="block text-xs text-muted-foreground">
                        Motivo de la anulación: {reason}
                      </span>
                    )}
                  </div>
                  <span
                    className={cn(
                      "tabular-nums text-muted-foreground w-12 text-right shrink-0",
                      cancelled && "line-through",
                    )}
                  >
                    {item.qty}
                  </span>
                  <span
                    className={cn(
                      "tabular-nums w-24 text-right shrink-0",
                      cancelled && "text-muted-foreground line-through",
                    )}
                  >
                    {formatMoney((item.price ?? 0) * item.qty, config)}
                  </span>
                  {/* Ancho fijo = el del botón `size="icon"`: los montos quedan
                      alineados haya o no acción en la fila, y anular un ítem no
                      corre de lugar lo que está abajo. */}
                  {canCancelItems && (
                    <div className="w-8 shrink-0">
                      {canCancelOrderItem(order, item) && (
                        <Button
                          variant="ghost"
                          size="icon"
                          aria-label={`Anular ${item.name}`}
                          onClick={() => setItemToCancel(item)}
                        >
                          <Ban className="size-4" />
                        </Button>
                      )}
                    </div>
                  )}
                </div>
              )
            })
          )}
        </div>
      </div>

      {/* ── Repartidor (F-D-1) — solo delivery ────────────────────────────────
          El repartidor es una PERSONA (staff de `contact`), no un dispositivo
          pareado; hoy la caja lo asigna (context/27 §B.6.2). */}
      {order.fulfillment === "delivery" && (
        <div className="mt-4 rounded-lg bg-muted/40 p-4">
          <h3 className="text-sm font-medium text-muted-foreground mb-2">Repartidor</h3>
          <div className="flex items-center justify-between gap-3">
            <span className="flex items-center gap-2 text-sm">
              <Truck className="size-3.5 text-muted-foreground" aria-hidden />
              {order.courierName ?? (
                <span className="text-muted-foreground italic">Sin repartidor asignado</span>
              )}
            </span>
            <Button
              size="sm"
              variant="outline"
              onClick={() => setCourierPickerOpen(true)}
              disabled={assignCourier.isPending}
            >
              {order.courierName ? "Reasignar" : "Asignar"}
            </Button>
          </div>
        </div>
      )}

      {/* ── Historial de transiciones (F-EVT-0) ───────────────────────────────
          `events` solo viene en el detalle — mientras carga, skeleton (igual
          que TransactionDetail); si el detalle ya resolvió y no hay eventos,
          el bloque no se rompe, solo dice "Sin movimientos". */}
      {/* Colapsado por defecto: una orden con varias rondas acumula decenas de
          transiciones y empujaba todo lo accionable fuera de la vista. El
          contador va en el trigger para no tener que abrirlo para saber si hay
          algo. */}
      <Collapsible className="mt-4 rounded-lg bg-muted/40 p-4">
        <CollapsibleTrigger className="flex w-full items-center justify-between gap-2 text-sm font-medium text-muted-foreground [&[data-state=open]>svg:last-child]:rotate-90">
          <span>
            Historial
            {detail?.events && detail.events.length > 0 ? ` (${detail.events.length})` : ""}
          </span>
          <ChevronRight className="size-4 shrink-0 transition-transform" aria-hidden />
        </CollapsibleTrigger>
        <CollapsibleContent className="mt-2">
        {eventsLoading ? (
          <div className="flex flex-col gap-2">
            <Skeleton className="h-4 w-3/4" />
            <Skeleton className="h-4 w-1/2" />
          </div>
        ) : !detail?.events || detail.events.length === 0 ? (
          <p className="text-sm text-muted-foreground italic">Sin movimientos registrados</p>
        ) : (
          <div className="divide-y divide-border/60">
            {detail.events.map((ev, idx) => (
              <div key={idx} className="py-2 text-sm">
                <div className="flex items-center justify-between gap-3">
                  <span>
                    {ev.fromStatus
                      ? `${eventStatusLabel(ev.scope, ev.fromStatus)} → ${eventStatusLabel(ev.scope, ev.toStatus)}`
                      : eventStatusLabel(ev.scope, ev.toStatus)}
                  </span>
                  {ev.createdAt && (
                    <span
                      className="text-xs text-muted-foreground tabular-nums shrink-0"
                      title={formatDateTime(ev.createdAt, "d MMM yyyy HH:mm")}
                    >
                      {formatRelativeShort(ev.createdAt)}
                    </span>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  {ACTOR_KIND_LABEL[ev.actorKind]}
                  {ev.actorModule ? ` · ${ev.actorModule}` : ""}
                  {ev.stationName ? ` · ${ev.stationName}` : ""}
                  {ev.reason ? ` · ${ev.reason}` : ""}
                </p>
              </div>
            ))}
          </div>
        )}
        </CollapsibleContent>
      </Collapsible>

      <CancelOrderItemDialog
        item={itemToCancel}
        open={itemToCancel !== null}
        onOpenChange={(v) => {
          if (!v) setItemToCancel(null)
        }}
      />

      <SellerPickerDialog
        open={courierPickerOpen}
        onOpenChange={setCourierPickerOpen}
        title="Asignar repartidor"
        currentUserId={order.courierId ?? undefined}
        onSelect={handleAssignCourier}
      />

      <CancelOrderDialog
        orderId={order.id}
        orderNumber={order.orderNumber}
        actions={actions}
      />
    </div>
  )
}
