"use client"

/**
 * Card de una orden activa del POS — la unidad de la vista Cuadros. El
 * diálogo de detalle de Lista/Mapa usa
 * `OrderDetailView`, que comparte las acciones (Cobrar, Reimprimir, Cancelar)
 * vía `useOrderActions` — misma lógica, una sola definición.
 *
 * Los ítems vienen en la propia orden (`useActiveOrders` pide
 * `includeItems=1`), no de un fetch por card.
 */

import { Clock, DollarSign, Printer, User, X } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
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
import { cn } from "@/lib/utils"
import { formatTime } from "@/lib/format-date"
import { formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import { useOrderActions } from "@/hooks/use-order-actions"
import { type Order } from "@/hooks/use-orders"
import {
  STATUS_VARIANT,
  orderDestination,
  orderItemsSummary,
  orderTotal,
  statusLabelFor,
} from "@/lib/orders/order-display"

export function OrderCard({
  order,
  className,
  onAfterAction,
}: {
  order: Order
  className?: string
  /** La dispara el diálogo de detalle para cerrarse tras cobrar/cancelar. */
  onAfterAction?: () => void
}) {
  const config = useCatalogStore((s) => s.config)
  // Cobrar / Reimprimir / Cancelar viven en `useOrderActions` — las comparte
  // con `OrderDetailView` (diálogo de detalle de Lista/Mapa). Estaban
  // duplicadas en los dos componentes y cualquier fix se aplicaba en uno solo.
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

  const hasItems = (order.items?.length ?? 0) > 0
  const total = orderTotal(order)
  const destination = orderDestination(order)

  return (
    <div className={cn("flex flex-col gap-3 rounded-xl border border-border bg-card p-4", className)}>
      <div className="flex items-start justify-between gap-2">
        <div>
          <p className="text-base font-semibold tabular-nums text-foreground">
            Orden #{order.orderNumber ?? "—"}
          </p>
          <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
            <Clock className="size-3" aria-hidden />
            {order.sentAt ? formatTime(order.sentAt) : order.createdAt ? formatTime(order.createdAt) : "—"}
            <span aria-hidden>·</span>
            <destination.icon className="size-3" aria-hidden />
            <span>{destination.label}</span>
          </div>
        </div>
        <Badge variant={STATUS_VARIANT[order.status]}>{statusLabelFor(order)}</Badge>
      </div>

      {order.customerName ? (
        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
          <User className="size-3.5 shrink-0" aria-hidden />
          <span className="truncate">{order.customerName}</span>
        </p>
      ) : null}

      <p className="line-clamp-2 text-sm text-muted-foreground">{orderItemsSummary(order)}</p>

      <p className="text-sm font-semibold tabular-nums text-foreground">
        {formatMoney(total, config)}
      </p>

      <div className="flex items-center gap-2 pt-1">
        <Button
          size="sm"
          className="flex-1 gap-1.5"
          disabled={!hasItems}
          onClick={cobrar}
        >
          <DollarSign className="size-3.5" />
          Cobrar
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="gap-1.5"
          disabled={!hasItems || printing}
          onClick={() => void reprint()}
          aria-label="Reimprimir comanda"
          title="Reimprimir comanda"
        >
          <Printer className="size-3.5" />
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
          onClick={() => setCancelOpen(true)}
          aria-label="Cancelar orden"
          title="Cancelar orden"
        >
          <X className="size-3.5" />
        </Button>
      </div>

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
            <Label htmlFor={`cancel-reason-${order.id}`}>Motivo de la cancelación</Label>
            <Textarea
              id={`cancel-reason-${order.id}`}
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
