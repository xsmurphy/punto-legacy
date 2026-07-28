"use client"

import { Badge } from "@/components/ui/badge"
import type { Order, OrderItem } from "@/hooks/use-orders"
import { DisplayCard } from "./display-card"

interface DisplayColumnProps {
  label: string
  orders: Order[]
  /** Solo "Listo" es accionable — el resto es informativo (ver `page.tsx`). */
  interactive: boolean
  busyIds: Set<string>
  onDeliverAll: (order: Order, items: OrderItem[]) => void
  onDeliverItem: (item: OrderItem) => void
}

/** Una columna del board — cabecera con label + contador, lista scrolleable de tarjetas. */
export function DisplayColumn({ label, orders, interactive, busyIds, onDeliverAll, onDeliverItem }: DisplayColumnProps) {
  return (
    <div className="flex h-full min-h-0 flex-col gap-2">
      <header className="flex shrink-0 items-center gap-2 px-1">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">{label}</h2>
        <Badge variant="secondary">{orders.length}</Badge>
      </header>

      <div className="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto overscroll-contain pb-1">
        {orders.length === 0 ? (
          <p className="px-1 text-sm text-muted-foreground">Nada acá por ahora</p>
        ) : (
          orders.map((order) => (
            <DisplayCard
              key={order.id}
              order={order}
              items={order.items ?? []}
              interactive={interactive}
              busy={busyIds.has(order.id)}
              onDeliverAll={onDeliverAll}
              onDeliverItem={onDeliverItem}
            />
          ))
        )}
      </div>
    </div>
  )
}
