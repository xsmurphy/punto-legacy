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
 *
 * El JSX del diálogo es `<CancelOrderDialog>` — estaba duplicado byte por byte
 * en los dos componentes y se extrajo junto con este cambio, porque el error
 * inline nuevo habría que haberlo escrito dos veces.
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
import { cancelErrorMessage } from "@/lib/orders/cancel-error"

export interface OrderActions {
  /** Vuelca la orden al carrito y navega a la caja para cobrarla. No-op si `isPaid`. */
  cobrar: () => void
  /**
   * La orden ya tiene `saleTransactionId` (nació pagada — flujo "Orden en
   * venta", o se cobró después). "Cobrar" tiene que quedar inhabilitado: sin
   * este gate, un segundo cobro generaría una venta duplicada del mismo
   * pedido.
   */
  isPaid: boolean
  /** Reimprime la comanda en las impresoras con el documento "Orden" asignado. */
  reprint: () => Promise<void>
  printing: boolean
  /** Diálogo de cancelación — el motivo es obligatorio, no un campo opcional. */
  cancelOpen: boolean
  setCancelOpen: (open: boolean) => void
  cancelReason: string
  setCancelReason: (reason: string) => void
  /**
   * Rechazo del servidor ya traducido a copy accionable, o `null`. Se pinta
   * INLINE en el diálogo y por eso el diálogo NO se cierra al fallar: el motivo
   * tipeado sigue ahí para que un encargado tome la posta sin reescribirlo.
   */
  cancelError: string | null
  cancelPending: boolean
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
  const [cancelError, setCancelError] = React.useState<string | null>(null)
  const [printing, setPrinting] = React.useState(false)

  // El diálogo arranca limpio cada vez que se abre: un error de un intento
  // anterior colgado acá sería peor que no mostrar nada.
  React.useEffect(() => {
    if (!cancelOpen) setCancelError(null)
  }, [cancelOpen])

  const isPaid = order.saleTransactionId != null

  const cobrar = React.useCallback(() => {
    if (isPaid) return
    loadFromOrder(order)
    onAfterAction?.()
    router.push("/pos")
  }, [isPaid, loadFromOrder, order, onAfterAction, router])

  /**
   * Cancelar una orden puede REBOTAR desde 2026-09-06: el backend la gatea con
   * `OrderCancelGate` (403 sin `pos.order.item.cancel`, 422 fuera de la ventana
   * del comercio sin `.late`). Antes no rebotaba nunca, así que esto cerraba el
   * diálogo de una y tiraba el error a un toast — el motivo ya tipeado se
   * perdía y el mensaje pelado del servidor no decía a quién llamar.
   *
   * Ahora el cierre del diálogo ocurre SOLO en el éxito, y el rechazo se
   * traduce con el mismo copy que la anulación de un ítem
   * (`lib/orders/cancel-error.ts`) para que el cajero vea la salida real.
   */
  const confirmCancel = React.useCallback(() => {
    const reason = cancelReason.trim()
    // El backend también lo rechaza; acá evitamos el round-trip.
    if (reason === "") return
    setCancelError(null)
    cancelOrder.mutate(
      { orderId: order.id, reason },
      {
        onSuccess: () => {
          toast.success(`Orden #${order.orderNumber} cancelada`)
          setCancelOpen(false)
          setCancelReason("")
          onAfterAction?.()
        },
        onError: (err) => setCancelError(cancelErrorMessage(err, "order")),
      },
    )
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
    isPaid,
    reprint,
    printing,
    cancelOpen,
    setCancelOpen,
    cancelReason,
    setCancelReason,
    cancelError,
    cancelPending: cancelOrder.isPending,
    confirmCancel,
  }
}
