"use client"

/**
 * Módulo Órdenes del POS (O1, context/24-orders-module-plan.md).
 *
 * Listado táctil de órdenes ACTIVAS del outlet del device (excluye
 * closed/cancelled — ver ACTIVE_ORDER_STATUSES en hooks/use-orders.ts).
 * "Cobrar" copia el contenido de la orden al carrito en modo venta
 * (loadFromOrder) y navega a /pos — el cobro en sí usa el flujo normal de
 * pay-dialog, que cierra el rastro con markPaid al confirmar (ver pay-dialog.tsx).
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { ClipboardList, Clock, DollarSign, Printer, X } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/empty-state"
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
import {
  useActiveOrders,
  useOrder,
  useCancelOrder,
  type Order,
  type OrderStatus,
} from "@/hooks/use-orders"
import { printOrderComandas } from "@/lib/orders/print-comandas"
import { toast } from "sonner"

const STATUS_LABEL: Record<OrderStatus, string> = {
  open: "Abierta",
  sent: "Enviada",
  in_progress: "En preparación",
  ready: "Lista",
  delivered: "Entregada",
  closed: "Cobrada",
  cancelled: "Cancelada",
}

const STATUS_VARIANT: Record<OrderStatus, "default" | "secondary" | "outline"> = {
  open: "outline",
  sent: "secondary",
  in_progress: "secondary",
  ready: "default",
  delivered: "default",
  closed: "outline",
  cancelled: "outline",
}

const SOURCE_LABEL: Record<Order["source"], string> = {
  counter: "Mostrador",
  table: "Mesa",
  ecommerce: "E-commerce",
  schedule: "Agenda",
}

export default function PosOrdenesPage() {
  const { data, isLoading } = useActiveOrders()
  const orders = data?.orders ?? []

  if (isLoading) {
    return (
      <div className="flex h-full w-full items-center justify-center p-6">
        <p className="text-sm text-muted-foreground">Cargando órdenes...</p>
      </div>
    )
  }

  if (orders.length === 0) {
    return (
      <div className="flex h-full w-full items-center justify-center p-6">
        <EmptyState
          ghost
          icon={ClipboardList}
          title="Sin órdenes activas"
          description="Las órdenes enviadas a cocina desde el carrito (modo Orden) van a aparecer acá."
        />
      </div>
    )
  }

  return (
    <div className="h-full w-full overflow-y-auto p-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {orders.map((order) => (
          <OrderCard key={order.id} order={order} />
        ))}
      </div>
    </div>
  )
}

function OrderCard({ order }: { order: Order }) {
  const router = useRouter()
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined)
  const allBindings = bindingsData?.bindings ?? []
  const loadFromOrder = useCartStore((s) => s.loadFromOrder)
  const cancelOrder = useCancelOrder()

  // Detalle con ítems — el listado activo no los trae (ver OrderCoreService::list()).
  const { data: detail } = useOrder(order.id)
  const items = detail?.items ?? []

  const [confirmCancelOpen, setConfirmCancelOpen] = React.useState(false)
  const [printing, setPrinting] = React.useState(false)

  const total = items.reduce((s, i) => s + (i.price ?? 0) * i.qty, 0)
  const itemSummary = items.length > 0
    ? items.map((i) => `${i.qty}x ${i.name}`).join(", ")
    : "—"

  function handleCobrar() {
    if (!detail) return
    loadFromOrder(detail)
    router.push("/pos")
  }

  function handleCancelConfirm() {
    cancelOrder.mutate(order.id, {
      onSuccess: () => toast.success(`Orden #${order.orderNumber} cancelada`),
      onError: (err) => toast.error("No se pudo cancelar la orden", { description: err.message }),
    })
    setConfirmCancelOpen(false)
  }

  async function handleReprint() {
    if (!detail) return
    setPrinting(true)
    try {
      const r = await printOrderComandas(detail, allBindings, config)
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
    <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
      <div className="flex items-start justify-between gap-2">
        <div>
          <p className="text-base font-semibold text-foreground">
            Orden #{order.orderNumber ?? "—"}
          </p>
          <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
            <Clock className="size-3" aria-hidden />
            {order.sentAt ? formatTime(order.sentAt) : order.createdAt ? formatTime(order.createdAt) : "—"}
            <span aria-hidden>·</span>
            <span>{SOURCE_LABEL[order.source]}</span>
          </div>
        </div>
        <Badge variant={STATUS_VARIANT[order.status]}>{STATUS_LABEL[order.status]}</Badge>
      </div>

      <p className="line-clamp-2 text-sm text-muted-foreground">{itemSummary}</p>

      <p className="text-sm font-semibold tabular-nums text-foreground">
        {formatMoney(total, config)}
      </p>

      <div className="flex items-center gap-2 pt-1">
        <Button
          size="sm"
          className="flex-1 gap-1.5"
          disabled={!detail}
          onClick={handleCobrar}
        >
          <DollarSign className="size-3.5" />
          Cobrar
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="gap-1.5"
          disabled={!detail || printing}
          onClick={handleReprint}
          aria-label="Reimprimir comanda"
        >
          <Printer className="size-3.5" />
        </Button>
        <Button
          size="sm"
          variant="outline"
          className={cn("gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive")}
          onClick={() => setConfirmCancelOpen(true)}
          aria-label="Cancelar orden"
        >
          <X className="size-3.5" />
        </Button>
      </div>

      <AlertDialog open={confirmCancelOpen} onOpenChange={setConfirmCancelOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Cancelar la orden #{order.orderNumber}?</AlertDialogTitle>
            <AlertDialogDescription>
              Esta acción no se puede deshacer. Los ítems no se van a preparar ni cobrar.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Volver</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleCancelConfirm}
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
