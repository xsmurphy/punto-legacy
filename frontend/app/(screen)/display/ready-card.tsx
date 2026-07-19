"use client"

import * as React from "react"
import { Clock, UtensilsCrossed } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { useElapsed } from "@/hooks/use-elapsed"
import type { Order, OrderItem } from "@/hooks/use-orders"

const SOURCE_LABEL: Record<string, string> = {
  counter: "Mostrador",
  table: "Espacio",
  ecommerce: "E-commerce",
  schedule: "Agenda",
}

interface ReadyCardProps {
  order: Order
  readyItems: OrderItem[]
  busy: boolean
  onDeliverAll: (order: Order, items: OrderItem[]) => void
  onDeliverItem: (item: OrderItem) => void
}

/** Pantalla de mozos: tarjeta de "listo para entregar". Layout más simple que
 * el KDS a propósito — solo lee ítems `ready` y los marca `delivered`. */
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
      <div className="flex items-center justify-between gap-2">
        <span className="font-bold tabular-nums" style={{ fontSize: "clamp(2rem, 3vw + 1rem, 3.5rem)" }}>
          #{order.orderNumber ?? "—"}
        </span>
        <span className="flex items-center gap-1 text-muted-foreground tabular-nums">
          <Clock className="size-4" />
          {elapsed.label}
        </span>
      </div>

      <div className="flex items-center gap-2 text-muted-foreground">
        <Badge variant="secondary">{SOURCE_LABEL[order.source] ?? order.source}</Badge>
        {order.spaceSessionId && <Badge variant="outline">Espacio</Badge>}
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
            <UtensilsCrossed className="size-4 shrink-0 text-emerald-500" />
          </li>
        ))}
      </ul>
    </button>
  )
}
