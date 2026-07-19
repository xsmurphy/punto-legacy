"use client"

import * as React from "react"
import { Clock, Store, UtensilsCrossed } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { useElapsed } from "@/hooks/use-elapsed"
import type { Order, OrderItem } from "@/hooks/use-orders"
import type { KdsConfig } from "@/lib/kds/config"

/**
 * Tarjeta de orden del KDS. Tap en la tarjeta = bump de TODOS los ítems
 * visibles (avanza pending→preparing o preparing→ready según corresponda);
 * tap en una línea de ítem = bump de ESE ítem solo. `delivered` NO se toca
 * acá — eso es responsabilidad de la pantalla de mozos (auth server-side lo
 * bloquea para module=kds, ver api/v1/orders-core.php).
 *
 * Colores de urgencia (verde/ámbar/rojo): excepción documentada a Regla #5
 * (14-ui-conventions.md) — son semántica de alerta operativa, no decoración,
 * mismo criterio que `variant="destructive"` de Badge/Button.
 */

const TIER_BORDER: Record<string, string> = {
  fresh: "border-l-emerald-500",
  warn: "border-l-amber-500",
  late: "border-l-red-500",
}

const TIER_TEXT: Record<string, string> = {
  fresh: "text-emerald-500",
  warn: "text-amber-500",
  late: "text-red-500",
}

const SOURCE_LABEL: Record<string, string> = {
  counter: "Mostrador",
  table: "Mesa",
  ecommerce: "E-commerce",
  schedule: "Agenda",
}

const ITEM_STATUS_LABEL: Record<string, string> = {
  pending: "Pendiente",
  preparing: "Preparando",
  ready: "Listo",
  delivered: "Entregado",
  cancelled: "Cancelado",
}

interface OrderCardProps {
  order: Order
  config: KdsConfig
  busy: boolean
  onBumpOrder: (order: Order, items: OrderItem[]) => void
  onBumpItem: (item: OrderItem) => void
}

export function OrderCard({ order, config, busy, onBumpOrder, onBumpItem }: OrderCardProps) {
  const elapsed = useElapsed(order.sentAt ?? order.createdAt, { warnMin: config.warnMin, lateMin: config.lateMin })

  const items = React.useMemo(() => {
    const all = order.items ?? []
    if (config.stationIds.length === 0) return all
    return all.filter((i) => i.stationId && config.stationIds.includes(i.stationId))
  }, [order.items, config.stationIds])

  const bumpable = items.filter((i) => i.status === "pending" || i.status === "preparing")
  const compact = config.density === "compact"

  return (
    <button
      type="button"
      disabled={busy || bumpable.length === 0}
      onClick={() => onBumpOrder(order, bumpable)}
      className={`flex flex-col gap-2 rounded-lg border border-l-4 bg-card p-3 text-left shadow-sm transition-opacity disabled:opacity-60 ${TIER_BORDER[elapsed.tier]}`}
      style={{ fontSize: "clamp(0.875rem, 1vw + 0.5rem, 1.25rem)" }}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="font-bold tabular-nums" style={{ fontSize: "clamp(1.5rem, 2vw + 1rem, 2.5rem)" }}>
          #{order.orderNumber ?? "—"}
        </span>
        <span className={`flex items-center gap-1 font-semibold tabular-nums ${TIER_TEXT[elapsed.tier]}`}>
          <Clock className="size-4" />
          {elapsed.label}
        </span>
      </div>

      <div className="flex items-center gap-2 text-muted-foreground">
        <Store className="size-3.5" />
        <span className="text-sm">{SOURCE_LABEL[order.source] ?? order.source}</span>
        {order.note && <Badge variant="outline" className="ml-auto">{order.note}</Badge>}
      </div>

      <ul className={`flex flex-col ${compact ? "gap-0.5" : "gap-1.5"}`}>
        {items.map((item) => (
          <li
            key={item.id}
            role="button"
            onClick={(e) => {
              e.stopPropagation()
              if (item.status === "pending" || item.status === "preparing") onBumpItem(item)
            }}
            className={`flex items-start justify-between gap-2 rounded-md px-2 py-1 ${
              item.status === "ready" ? "bg-emerald-500/10" : item.status === "cancelled" ? "opacity-40 line-through" : "bg-muted/50"
            }`}
          >
            <span className="flex-1">
              <span className="font-semibold tabular-nums">{item.qty}×</span> {item.name}
              {item.note && <span className="block text-sm text-muted-foreground">{item.note}</span>}
            </span>
            {item.status === "ready" && (
              <UtensilsCrossed className="size-4 shrink-0 text-emerald-500" />
            )}
            {item.status !== "pending" && item.status !== "ready" && (
              <Badge variant="secondary" className="shrink-0">{ITEM_STATUS_LABEL[item.status]}</Badge>
            )}
          </li>
        ))}
      </ul>
    </button>
  )
}
