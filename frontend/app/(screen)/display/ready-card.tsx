"use client"

import { Check, Clock } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { useElapsed } from "@/hooks/use-elapsed"
import type { Order, OrderItem } from "@/hooks/use-orders"
import { orderOrigin } from "@/lib/orders/order-display"

interface ReadyCardProps {
  order: Order
  readyItems: OrderItem[]
  busy: boolean
  onDeliverAll: (order: Order, items: OrderItem[]) => void
  onDeliverItem: (item: OrderItem) => void
}

/**
 * Pantalla de despacho: tarjeta de "listo para entregar". Layout más simple que
 * el KDS a propósito — solo lee ítems `ready` y los marca `delivered`.
 *
 * JERARQUÍA: manda el DESTINO, no el número.
 * Antes el número de orden era lo único grande y el origen decía "Espacio" a
 * secas (sin cuál) en un badge muted. Está al revés para quien reparte: el
 * número identifica, pero lo accionable es a dónde va. Ahora el destino es el
 * elemento más grande de la tarjeta y el número queda como identificador
 * secundario, todavía legible de lejos.
 *
 * El destino sale de `orderOrigin()` — misma fuente que el KDS y /pos/ordenes,
 * y por eso dice "Espacio 4" y no "Espacio": el nombre real viene del backend
 * (`spaceName`, LEFT JOIN space_session → space). El badge redundante que solo
 * decía "Espacio" cuando había `spaceSessionId` desaparece: era la misma
 * información, sin el dato.
 */
export function ReadyCard({ order, readyItems, busy, onDeliverAll, onDeliverItem }: ReadyCardProps) {
  const elapsed = useElapsed(order.sentAt ?? order.createdAt, { warnMin: 5, lateMin: 15 })

  return (
    <button
      type="button"
      disabled={busy}
      onClick={() => onDeliverAll(order, readyItems)}
      className="flex flex-col gap-2 rounded-lg border border-l-4 border-l-emerald-500 bg-card p-4 text-left shadow-sm transition-opacity disabled:opacity-60"
      style={{ fontSize: "clamp(0.875rem, 1vw + 0.5rem, 1.25rem)" }}
    >
      <div className="flex items-baseline justify-between gap-2">
        {/* El dato accionable, y por eso el más grande de la tarjeta. */}
        <span
          className="min-w-0 flex-1 truncate font-bold"
          style={{ fontSize: "clamp(1.75rem, 2.8vw + 1rem, 3.5rem)" }}
        >
          {orderOrigin(order)}
        </span>
        <span className="flex shrink-0 items-center gap-1 text-muted-foreground tabular-nums">
          <Clock className="size-4" />
          {elapsed.label}
        </span>
      </div>

      <div className="flex items-center gap-2">
        {/* Identificador — sigue grande, pero ya no le gana al destino. */}
        <span
          className="font-semibold tabular-nums"
          style={{ fontSize: "clamp(1.25rem, 1.4vw + 0.75rem, 2rem)" }}
        >
          #{order.orderNumber ?? "—"}
        </span>
        {order.customerName && <Badge variant="outline">{order.customerName}</Badge>}
      </div>

      <ul className="flex flex-col gap-1">
        {readyItems.map((item) => (
          <li
            key={item.id}
            role="button"
            onClick={(e) => { e.stopPropagation(); onDeliverItem(item) }}
            className="flex items-center justify-between gap-2 rounded-md bg-emerald-500/10 px-2 py-1"
          >
            <span><span className="font-semibold tabular-nums">{item.qty}×</span> {item.name}</span>
            {/* Check y no cubiertos: la misma pantalla la usa un depósito. */}
            <Check className="size-4 shrink-0 text-emerald-500" />
          </li>
        ))}
      </ul>
    </button>
  )
}
