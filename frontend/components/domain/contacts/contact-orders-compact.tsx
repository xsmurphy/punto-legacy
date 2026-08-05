"use client"

import * as React from "react"
import { ShoppingBag } from "lucide-react"
import { Input } from "@/components/ui/input"
import { Skeleton } from "@/components/ui/skeleton"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"
import { useOrdersByCustomer, type Order } from "@/hooks/use-orders"
import { OrderStatusBadge } from "@/components/orders/order-status-badge"
import { orderTotal, orderSearchHaystack } from "@/lib/orders/order-display"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"


interface Props {
  customerId: string
}

/**
 * Tab "Órdenes" de la ficha del cliente (T5, reporte del tester 2026-08-03).
 * Lee las órdenes REALES del módulo Órdenes (`pos_order`, vía
 * `useOrdersByCustomer`) — antes leía el reporte legacy `orders`
 * (`transaction` type=12, pedido online viejo) y por eso nunca mostraba nada:
 * las órdenes del POS no viven ahí. El estado se pinta con `OrderStatusBadge`
 * compartido (no interactivo acá: la ficha del cliente es de solo lectura,
 * cambiar el estado de una orden es un flujo de /pos/ordenes) — NO se
 * redefine el mapeo de estados en este componente, que fue justo la causa
 * del bug (STATUS_MAP numérico legacy vs estados string de `pos_order`).
 */
export function ContactOrdersCompact({ customerId }: Props) {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const [search, setSearch] = React.useState("")

  const backendRange = React.useMemo(() => rangeToBackend(range), [range])
  const { data, isLoading } = useOrdersByCustomer(customerId, backendRange)
  const rows: Order[] = React.useMemo(() => data?.orders ?? [], [data])

  const filtered = React.useMemo(() => {
    if (!search.trim()) return rows
    const q = search.toLowerCase()
    return rows.filter((r) => orderSearchHaystack(r).includes(q))
  }, [rows, search])

  return (
    <div className="flex flex-col gap-3">
      {/* Barra de filtros */}
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <Input
          placeholder="Buscar orden…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-8 text-sm sm:max-w-[200px]"
        />
        <div className="sm:ml-auto">
          <DateRangePicker value={range} onChange={setRange} />
        </div>
      </div>

      {/* Lista */}
      {isLoading ? (
        <div className="flex flex-col gap-2">
          {[1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-16 w-full rounded-lg" />
          ))}
        </div>
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={ShoppingBag}
          title="Sin órdenes"
          description="No hay órdenes en el rango seleccionado."
        />
      ) : (
        <ul className="divide-y divide-border rounded-lg border">
          {filtered.map((order) => (
            <li key={order.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
              <div className="flex min-w-0 flex-col gap-0.5">
                <span className="font-medium tabular-nums truncate">
                  {order.orderNumber !== null ? `#${order.orderNumber}` : "—"}
                </span>
                <span className="text-xs text-muted-foreground truncate">{formatDateTime(order.createdAt ?? "", "d MMM yyyy HH:mm")}</span>
              </div>
              <div className="flex shrink-0 flex-col items-end gap-0.5">
                <span className="tabular-nums font-medium">{formatMoney(orderTotal(order), bootstrap)}</span>
                <OrderStatusBadge order={order} interactive={false} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
