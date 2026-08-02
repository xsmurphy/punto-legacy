"use client"

/**
 * Badge de estado de una orden — ÚNICA implementación del cambio rápido de
 * estado en /pos/ordenes. La consumen `OrderDetailView` (Dialog de detalle),
 * `OrderCard` (vista Cuadros) y `OrdersListView` (vista Lista): antes cada
 * una tenía su propio dropdown (o pintaba el Badge estático), y un fix en
 * una no se aplicaba en las otras.
 *
 * Si `manualStatusOptions(order)` no ofrece transiciones (ej. `closed`/
 * `cancelled`, o `interactive=false`) se pinta el Badge pelado, sin trigger.
 * El backend es la autoridad de qué transición vale — `ORDER_TRANSITIONS` en
 * `lib/orders/order-display.ts` es solo un espejo; el toast de error muestra
 * el mensaje real del server, nunca uno genérico.
 */

import { ChevronDown } from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { useUpdateOrderStatus, type Order } from "@/hooks/use-orders"
import {
  STATUS_LABEL,
  STATUS_VARIANT,
  manualStatusOptions,
  statusLabelFor,
} from "@/lib/orders/order-display"

export function OrderStatusBadge({
  order,
  interactive = true,
}: {
  order: Order
  /**
   * false fuerza el Badge pelado aunque existan transiciones — para
   * superficies de solo lectura que no deben ofrecer el cambio de estado.
   * Default true: es lo que hoy usan las tres vistas de /pos/ordenes.
   */
  interactive?: boolean
}) {
  const updateStatus = useUpdateOrderStatus()
  const statusOptions = interactive ? manualStatusOptions(order) : []

  if (statusOptions.length === 0) {
    return <Badge variant={STATUS_VARIANT[order.status]}>{statusLabelFor(order)}</Badge>
  }

  function handleStatusChange(status: Order["status"]) {
    updateStatus.mutate(
      { orderId: order.id, status },
      {
        onSuccess: () => toast.success(`Orden #${order.orderNumber} → ${STATUS_LABEL[status]}`),
        // El error real del server, no uno genérico — la whitelist del front
        // es solo un espejo, el backend revalida y puede rechazar igual.
        onError: (err) => toast.error("No se pudo cambiar el estado", { description: err.message }),
      },
    )
  }

  return (
    // stopPropagation ACÁ (no solo en el trigger): en card/lista el badge
    // vive dentro de un elemento clickeable que abre el detalle de la orden.
    // `DropdownMenuContent` se renderiza en un portal (fuera del DOM del
    // contenedor), pero React burbujea sus eventos por el ÁRBOL DE
    // COMPONENTES, no por el DOM — un click en un DropdownMenuItem también
    // dispararía el onClick de la card/fila si no se corta acá, envolviendo
    // trigger y content.
    <span onClick={(e) => e.stopPropagation()}>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          {/* after:-inset-2: agranda el target táctil (~40px) sin tocar el
              tamaño visual del badge ni mover nada alrededor — mismo patrón
              que el trigger de módulos en cart-panel.tsx. */}
          <Badge
            variant={STATUS_VARIANT[order.status]}
            className="relative cursor-pointer gap-0.5 pr-1 after:absolute after:-inset-2 after:content-['']"
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
    </span>
  )
}
