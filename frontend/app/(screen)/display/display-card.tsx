"use client"

import { Check, Clock } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { useElapsed } from "@/hooks/use-elapsed"
import type { Order, OrderItem } from "@/hooks/use-orders"
import { orderDestination } from "@/lib/orders/order-display"

interface DisplayCardProps {
  order: Order
  /** Todos los ítems de la orden — se muestran siempre, tocables solo si `interactive`. */
  items: OrderItem[]
  /** Solo la columna "Listo" es accionable — ver comentario de armado en `page.tsx`. */
  interactive: boolean
  busy: boolean
  onDeliverAll: (order: Order, items: OrderItem[]) => void
  onDeliverItem: (item: OrderItem) => void
}

/**
 * Tarjeta de una orden dentro de una columna del board de despacho.
 *
 * Escala tipográfica: mismo mecanismo que el KDS (`order-card.tsx`) — `clamp()`
 * sobre `--board-col`, la variable que la grilla setea con el ancho REAL de
 * columna medido (ver `page.tsx`). Los factores acá son mucho más chicos que
 * los del KDS: ahí una tarjeta ocupa toda una columna a pantalla completa, acá
 * varias tarjetas se apilan y scrollean dentro de la misma columna.
 *
 * Tarjetas de "Pendiente"/"En proceso" son informativas, NO botones: sin
 * `role="button"`, sin `onClick`, cursor default y opacidad reducida — tienen
 * que VERSE que no se tocan (el backend además las rechaza para module=display,
 * ver `assertModuleCanSetStatus` en orders-core.php).
 */
export function DisplayCard({ order, items, interactive, busy, onDeliverAll, onDeliverItem }: DisplayCardProps) {
  const elapsed = useElapsed(order.sentAt ?? order.createdAt, { warnMin: 5, lateMin: 15 })
  const destination = orderDestination(order)

  const cardClass = `flex flex-col gap-1.5 rounded-lg border bg-card p-3 text-left shadow-sm transition-opacity ${
    interactive ? "border-l-4 border-l-emerald-500 disabled:opacity-60" : "cursor-default opacity-70"
  }`

  const header = (
    <>
      <div className="flex items-baseline justify-between gap-2">
        {/* El dato accionable, y por eso el más grande de la tarjeta — pero ya
            no un titular de TV: en 3 columnas es el dato principal de una fila. */}
        <span
          className="flex min-w-0 flex-1 items-center gap-1.5 truncate font-bold"
          style={{ fontSize: "clamp(1rem, calc(var(--board-col, 320px) * 0.045 + 0.35vw), 1.5rem)" }}
        >
          <destination.icon className="size-[1em] shrink-0" />
          <span className="truncate">{destination.label}</span>
        </span>
        <span className="flex shrink-0 items-center gap-1 text-muted-foreground tabular-nums">
          <Clock className="size-3.5" />
          <span style={{ fontSize: "clamp(0.75rem, calc(var(--board-col, 320px) * 0.02 + 0.2vw), 0.95rem)" }}>
            {elapsed.label}
          </span>
        </span>
      </div>

      <div className="flex items-center gap-2">
        <span
          className="font-semibold tabular-nums"
          style={{ fontSize: "clamp(0.9rem, calc(var(--board-col, 320px) * 0.03 + 0.25vw), 1.15rem)" }}
        >
          #{order.orderNumber ?? "—"}
        </span>
        {order.customerName && <Badge variant="outline">{order.customerName}</Badge>}
      </div>
    </>
  )

  const list = (
    <ul className="flex flex-col gap-1">
      {items.map((item) => {
        const done = item.status === "ready" || item.status === "delivered"
        const clickable = interactive && item.status === "ready"
        return (
          <li
            key={item.id}
            role={clickable ? "button" : undefined}
            onClick={clickable ? (e) => { e.stopPropagation(); onDeliverItem(item) } : undefined}
            className={`flex items-center justify-between gap-2 rounded-md px-2 py-1 ${
              done ? "bg-emerald-500/10" : "bg-muted/50"
            }`}
            style={{ fontSize: "clamp(0.8rem, calc(var(--board-col, 320px) * 0.02 + 0.2vw), 1rem)" }}
          >
            <span><span className="font-semibold tabular-nums">{item.qty}×</span> {item.name}</span>
            {/* Check y no cubiertos: la misma pantalla la usa un depósito. */}
            {done && <Check className="size-4 shrink-0 text-emerald-500" />}
          </li>
        )
      })}
    </ul>
  )

  if (interactive) {
    return (
      <button type="button" disabled={busy} onClick={() => onDeliverAll(order, items.filter((i) => i.status === "ready"))} className={cardClass}>
        {header}
        {list}
      </button>
    )
  }

  // No-interactivo: `div` a propósito, no `button` — sin affordance de click,
  // sin cursor pointer, sin hover. El tap acá no debe parecer que hace algo.
  return (
    <div className={cardClass}>
      {header}
      {list}
    </div>
  )
}
