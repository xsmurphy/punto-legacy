"use client"

/**
 * Card de una orden activa del POS. Es la unidad de la vista Cuadros y también
 * el contenido del diálogo de detalle que abren las vistas Lista y Mapa — así
 * las tres vistas ofrecen exactamente las mismas acciones (Cobrar, Reimprimir,
 * Cancelar) sin duplicar lógica.
 *
 * Los ítems vienen en la propia orden (`useActiveOrders` pide
 * `includeItems=1`), no de un fetch por card.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { Clock, DollarSign, Printer, User, X } from "lucide-react"
import { toast } from "sonner"

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
import { useCartStore } from "@/lib/cart/store"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"
import { posApi } from "@/lib/api/pos-client"
import { useCancelOrder, type Order } from "@/hooks/use-orders"
import { printOrderComandas } from "@/lib/orders/print-comandas"
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
  const router = useRouter()
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined, { client: posApi })
  const allBindings = bindingsData?.bindings ?? []
  const loadFromOrder = useCartStore((s) => s.loadFromOrder)
  const cancelOrder = useCancelOrder()

  const [confirmCancelOpen, setConfirmCancelOpen] = React.useState(false)
  const [cancelReason, setCancelReason] = React.useState("")
  const [printing, setPrinting] = React.useState(false)

  const hasItems = (order.items?.length ?? 0) > 0
  const total = orderTotal(order)
  const destination = orderDestination(order)

  function handleCobrar() {
    loadFromOrder(order)
    onAfterAction?.()
    router.push("/pos")
  }

  function handleCancelConfirm() {
    const reason = cancelReason.trim()
    if (reason === "") return // el backend también lo rechaza; acá evitamos el round-trip
    cancelOrder.mutate(
      { orderId: order.id, reason },
      {
        onSuccess: () => {
          toast.success(`Orden #${order.orderNumber} cancelada`)
          onAfterAction?.()
        },
        onError: (err) => toast.error("No se pudo cancelar la orden", { description: err.message }),
      },
    )
    setConfirmCancelOpen(false)
    setCancelReason("")
  }

  async function handleReprint() {
    setPrinting(true)
    try {
      const r = await printOrderComandas(order, allBindings, config)
      if (r.failed > 0) {
        toast.warning(`${r.failed} impresora(s) fallaron al reimprimir${r.errors[0] ? `: ${r.errors[0]}` : ""}`)
      } else if (r.printed > 0) {
        toast.success(`${r.printed} impresora(s) reimprimieron`)
      } else {
        toast.warning("Ninguna impresora tiene asignado el documento Orden — asignáselo en Impresoras")
      }
    } catch (err) {
      toast.error("No se pudo reimprimir la comanda", {
        description: err instanceof Error ? err.message : String(err),
      })
    } finally {
      setPrinting(false)
    }
  }

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
          onClick={handleCobrar}
        >
          <DollarSign className="size-3.5" />
          Cobrar
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="gap-1.5"
          disabled={!hasItems || printing}
          onClick={handleReprint}
          aria-label="Reimprimir comanda"
          title="Reimprimir comanda"
        >
          <Printer className="size-3.5" />
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
          onClick={() => setConfirmCancelOpen(true)}
          aria-label="Cancelar orden"
          title="Cancelar orden"
        >
          <X className="size-3.5" />
        </Button>
      </div>

      <AlertDialog
        open={confirmCancelOpen}
        onOpenChange={(v) => {
          setConfirmCancelOpen(v)
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
              onClick={handleCancelConfirm}
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
