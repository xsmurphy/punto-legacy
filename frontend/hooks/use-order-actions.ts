"use client"

/**
 * Acciones de una orden — Cobrar, Reimprimir la comanda y Cancelar.
 *
 * Fuente ÚNICA de esas tres acciones para las dos superficies que las
 * ofrecen: `OrderCard` (vista Cuadros de /pos/ordenes) y `OrderDetailView`
 * (diálogo de detalle de Lista/Mapa). Vivían duplicadas byte por byte en los
 * dos componentes: cualquier fix futuro —el toast de "ninguna impresora tiene
 * el documento Orden", el guard del motivo, el orden de `onAfterAction` vs
 * `router.push`— se habría aplicado en uno solo y las dos pantallas habrían
 * divergido en silencio.
 *
 * Estado del diálogo de cancelación incluido a propósito: el motivo es
 * OBLIGATORIO (lo exige `OrderCoreService::updateStatus`, no solo esta UI), y
 * dejar ese estado suelto en cada componente es justamente la puerta por la
 * que se cuela un call-site que cancela sin pedirlo.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { toast } from "sonner"

import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"
import { posApi } from "@/lib/api/pos-client"
import { printOrderComandas } from "@/lib/orders/print-comandas"
import { useCancelOrder, type Order } from "@/hooks/use-orders"

export interface OrderActions {
  /** Vuelca la orden al carrito y navega a la caja para cobrarla. */
  cobrar: () => void
  /** Reimprime la comanda en las impresoras con el documento "Orden" asignado. */
  reprint: () => Promise<void>
  printing: boolean
  /** Diálogo de cancelación — el motivo es obligatorio, no un campo opcional. */
  cancelOpen: boolean
  setCancelOpen: (open: boolean) => void
  cancelReason: string
  setCancelReason: (reason: string) => void
  confirmCancel: () => void
}

export function useOrderActions(order: Order, onAfterAction?: () => void): OrderActions {
  const router = useRouter()
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined, { client: posApi })
  // useMemo: `?? []` crea un array nuevo en cada render y `reprint` lo tiene
  // como dependencia — sin esto la callback se recrea siempre.
  const allBindings = React.useMemo(() => bindingsData?.bindings ?? [], [bindingsData])
  const loadFromOrder = useCartStore((s) => s.loadFromOrder)
  const cancelOrder = useCancelOrder()

  const [cancelOpen, setCancelOpen] = React.useState(false)
  const [cancelReason, setCancelReason] = React.useState("")
  const [printing, setPrinting] = React.useState(false)

  const cobrar = React.useCallback(() => {
    loadFromOrder(order)
    onAfterAction?.()
    router.push("/pos")
  }, [loadFromOrder, order, onAfterAction, router])

  const confirmCancel = React.useCallback(() => {
    const reason = cancelReason.trim()
    // El backend también lo rechaza; acá evitamos el round-trip.
    if (reason === "") return
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
    setCancelOpen(false)
    setCancelReason("")
  }, [cancelReason, cancelOrder, order.id, order.orderNumber, onAfterAction])

  const reprint = React.useCallback(async () => {
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
  }, [order, allBindings, config])

  return {
    cobrar,
    reprint,
    printing,
    cancelOpen,
    setCancelOpen,
    cancelReason,
    setCancelReason,
    confirmCancel,
  }
}
