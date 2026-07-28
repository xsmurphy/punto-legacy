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
import { ChevronDown, DollarSign, MoreHorizontal, Printer, Truck, X } from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Skeleton } from "@/components/ui/skeleton"
import { Textarea } from "@/components/ui/textarea"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { formatRelativeShort, formatDateTime } from "@/lib/format-date"
import { formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import { useOrderActions } from "@/hooks/use-order-actions"
import { KDS_ITEM_VISUALS } from "@/lib/kds/kds-visuals"
import { SellerPickerDialog } from "@/components/pos/seller-picker-dialog"
import {
  useAssignCourier,
  useOrder,
  useUpdateOrderStatus,
  type Order,
  type OrderEvent,
} from "@/hooks/use-orders"
import {
  STATUS_LABEL,
  STATUS_VARIANT,
  manualStatusOptions,
  orderDestination,
  orderTotal,
  statusLabelFor,
} from "@/lib/orders/order-display"

const ACTOR_KIND_LABEL: Record<OrderEvent["actorKind"], string> = {
  user: "Usuario",
  device: "Dispositivo",
  system: "Sistema",
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
  const updateStatus = useUpdateOrderStatus()
  const assignCourier = useAssignCourier()
  const [courierPickerOpen, setCourierPickerOpen] = React.useState(false)
  // Mismas acciones que la card de la vista Cuadros — `useOrderActions` es la
  // única definición de Cobrar/Reimprimir/Cancelar.
  const {
    cobrar,
    reprint,
    printing,
    cancelOpen,
    setCancelOpen,
    cancelReason,
    setCancelReason,
    confirmCancel,
  } = useOrderActions(order, onAfterAction)

  // `events` (F-EVT-0) solo viene en el detalle — el listado no lo trae.
  // El resto (header, items, monto) ya está disponible en `order` (la lista
  // pide includeItems=1), así que no hace falta esperar este fetch para
  // pintar el grueso del panel.
  const { data: detail, isLoading: eventsLoading } = useOrder(order.id)

  const items = order.items ?? []
  const hasItems = items.length > 0
  const total = orderTotal(order)
  const destination = orderDestination(order)
  const statusOptions = manualStatusOptions(order)
  const timeIso = order.sentAt ?? order.createdAt

  function handleStatusChange(status: Order["status"]) {
    updateStatus.mutate(
      { orderId: order.id, status },
      {
        onSuccess: () => toast.success(`Orden #${order.orderNumber} → ${STATUS_LABEL[status]}`),
        // El error real del server, no uno genérico — ORDER_TRANSITIONS acá es
        // solo un espejo, la autoridad de qué transición vale es el backend.
        onError: (err) => toast.error("No se pudo cambiar el estado", { description: err.message }),
      },
    )
  }

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
            {statusOptions.length > 0 ? (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Badge
                    variant={STATUS_VARIANT[order.status]}
                    className="cursor-pointer gap-0.5 pr-1"
                  >
                    {statusLabelFor(order)}
                    <ChevronDown className="size-3" aria-hidden />
                  </Badge>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                  {statusOptions.map((s) => (
                    <DropdownMenuItem key={s} onSelect={() => handleStatusChange(s)}>
                      {STATUS_LABEL[s]}
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
            ) : (
              <Badge variant={STATUS_VARIANT[order.status]}>{statusLabelFor(order)}</Badge>
            )}
          </div>
        </div>
        <div className="shrink-0 flex flex-col items-end gap-2">
          <p className="text-2xl font-bold tabular-nums">{formatMoney(total, config)}</p>
          <div className="inline-flex">
            <Button
              size="sm"
              className="rounded-r-none border-r-0 gap-1.5"
              disabled={!hasItems}
              onClick={cobrar}
            >
              <DollarSign className="size-3.5" />
              Cobrar
            </Button>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button size="sm" className="rounded-l-none px-2" aria-label="Más acciones">
                  <MoreHorizontal className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem disabled={!hasItems || printing} onSelect={() => void reprint()}>
                  <Printer className="size-3.5" />
                  Reimprimir comanda
                </DropdownMenuItem>
                <DropdownMenuItem
                  variant="destructive"
                  onSelect={() => setCancelOpen(true)}
                >
                  <X className="size-3.5" />
                  Cancelar orden
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
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
            items.map((item) => (
              <div key={item.id} className="flex items-center justify-between py-2 text-sm gap-3">
                <div className="min-w-0 flex-1">
                  <span className="block truncate">{item.name}</span>
                  <span className="text-xs text-muted-foreground">
                    {KDS_ITEM_VISUALS[item.status].label}
                  </span>
                </div>
                <span className="tabular-nums text-muted-foreground w-12 text-right shrink-0">
                  {item.qty}
                </span>
                <span className="tabular-nums w-24 text-right shrink-0">
                  {formatMoney((item.price ?? 0) * item.qty, config)}
                </span>
              </div>
            ))
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
      <div className="mt-4 rounded-lg bg-muted/40 p-4">
        <h3 className="text-sm font-medium text-muted-foreground mb-2">Historial</h3>
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
                    {ev.fromStatus ? `${ev.fromStatus} → ${ev.toStatus}` : ev.toStatus}
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
      </div>

      <SellerPickerDialog
        open={courierPickerOpen}
        onOpenChange={setCourierPickerOpen}
        title="Asignar repartidor"
        currentUserId={order.courierId ?? undefined}
        onSelect={handleAssignCourier}
      />

      <AlertDialog
        open={cancelOpen}
        onOpenChange={(v) => {
          setCancelOpen(v)
          if (!v) setCancelReason("")
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Cancelar la orden #{order.orderNumber}?</AlertDialogTitle>
            <AlertDialogDescription>
              La orden no se elimina: queda cancelada y sale de las pantallas operativas.
              El motivo queda registrado en su historial.
            </AlertDialogDescription>
          </AlertDialogHeader>
          {/* Motivo obligatorio — el backend rechaza la cancelación sin él. */}
          <div className="flex flex-col gap-2">
            <Label htmlFor={`cancel-reason-detail-${order.id}`}>Motivo de la cancelación</Label>
            <Textarea
              id={`cancel-reason-detail-${order.id}`}
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
              placeholder="Ej.: el cliente se retiró, error de carga, faltó stock"
              rows={3}
            />
          </div>
          <AlertDialogFooter>
            <AlertDialogCancel>Volver</AlertDialogCancel>
            <AlertDialogAction
              onClick={confirmCancel}
              disabled={cancelReason.trim() === ""}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Cancelar orden
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
