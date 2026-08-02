"use client"

/**
 * Vista lista de /pos/ordenes — tabla compacta táctil con buscador en tiempo
 * real sobre las órdenes YA cargadas (nº de orden, cliente, ítems, origen,
 * tipo de entrega).
 *
 * NO usa el `<DataTable>` del panel a propósito: ese trae paginación, export
 * y column-toggle, que en una caja táctil estorban más de lo que aportan. Acá
 * alcanza `<Table>` de shadcn + un `<Input>` de búsqueda client-side.
 *
 * El estado del buscador vive ACÁ (no en la página) para que tipear
 * re-renderice solo esta vista y no el mapa, que se mantiene montado.
 *
 * Columnas: Origen (de dónde vino) / Orden (#N) / Cliente / Ítems (oculto en
 * pantallas angostas) / Tipo (cómo se entrega) / Tiempo (relativo) / Estado /
 * Total. Origen y Tipo son dos ejes separados a propósito — ver
 * `orderSourceLabel`/`orderFulfillmentLabel` en `lib/orders/order-display.ts`.
 */

import * as React from "react"
import { Search } from "lucide-react"

import { Input } from "@/components/ui/input"
import { EmptyState } from "@/components/empty-state"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { cn } from "@/lib/utils"
import { formatMoney } from "@/lib/format-money"
import { formatDateTime, formatRelativeShort } from "@/lib/format-date"
import { useCatalogStore } from "@/lib/catalog/store"
import type { Order } from "@/hooks/use-orders"
import { OrderStatusBadge } from "@/components/orders/order-status-badge"
import {
  STATUS_ACCENT,
  orderFulfillmentLabel,
  orderItemsSummary,
  orderSearchHaystack,
  orderSourceLabel,
  orderTotal,
} from "@/lib/orders/order-display"

/** Cuánto tarda en "envejecer" una celda de tiempo relativo antes de recalcularse. */
const RELATIVE_TIME_TICK_MS = 30_000

export function OrdersListView({
  orders,
  onOpenOrder,
}: {
  orders: Order[]
  onOpenOrder: (order: Order) => void
}) {
  const config = useCatalogStore((s) => s.config)
  const [query, setQuery] = React.useState("")

  // El "hace N min" no se actualiza solo si se calcula una sola vez en el
  // render: forzamos un re-render cada 30s con este tick, sin tocar `orders`.
  const [, setTick] = React.useState(0)
  React.useEffect(() => {
    const id = setInterval(() => setTick((t) => t + 1), RELATIVE_TIME_TICK_MS)
    return () => clearInterval(id)
  }, [])

  const filtered = React.useMemo(() => {
    const q = query.trim().toLowerCase()
    if (q === "") return orders
    return orders.filter((o) => orderSearchHaystack(o).includes(q))
  }, [orders, query])

  return (
    <div className="flex flex-col gap-3">
      <div className="relative">
        <Search
          className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden
        />
        <Input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Buscar por número, cliente, ítem u origen"
          aria-label="Buscar órdenes"
          className="pl-9"
        />
      </div>

      {filtered.length === 0 ? (
        <EmptyState
          ghost
          icon={Search}
          title="Sin resultados"
          description="Probá con otro número de orden, cliente o ítem."
        />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-32">Origen</TableHead>
                <TableHead className="w-20">Orden</TableHead>
                <TableHead>Cliente</TableHead>
                <TableHead className="hidden md:table-cell">Ítems</TableHead>
                <TableHead className="w-24">Tipo</TableHead>
                <TableHead className="w-28">Tiempo</TableHead>
                <TableHead className="w-32">Estado</TableHead>
                <TableHead className="w-28 text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((order) => {
                const accent = STATUS_ACCENT[order.status]
                const timestamp = order.sentAt ?? order.createdAt
                return (
                  <TableRow
                    key={order.id}
                    // h-14: fila táctil (>44px) — se abre con el dedo en tablet.
                    // border-l-4: el color de estado se lee de reojo y sirve de
                    // filtro visual rápido (STATUS_ACCENT, fuente única con el KDS).
                    className={cn("h-14 cursor-pointer border-l-4", !accent && "border-l-border")}
                    style={accent ? { borderLeftColor: accent } : undefined}
                    onClick={() => onOpenOrder(order)}
                  >
                    <TableCell className="max-w-32 truncate text-muted-foreground">
                      {orderSourceLabel(order)}
                    </TableCell>
                    <TableCell className="font-semibold tabular-nums">
                      #{order.orderNumber ?? "—"}
                    </TableCell>
                    <TableCell className="max-w-40 truncate">
                      {order.customerName ?? (
                        <span className="text-muted-foreground">Sin cliente</span>
                      )}
                    </TableCell>
                    <TableCell className="hidden max-w-72 truncate text-muted-foreground md:table-cell">
                      {orderItemsSummary(order)}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {orderFulfillmentLabel(order)}
                    </TableCell>
                    <TableCell
                      className="tabular-nums text-muted-foreground"
                      title={timestamp ? formatDateTime(timestamp) : undefined}
                    >
                      {timestamp ? formatRelativeShort(timestamp) : "—"}
                    </TableCell>
                    <TableCell>
                      <OrderStatusBadge order={order} />
                    </TableCell>
                    <TableCell className="text-right font-semibold tabular-nums">
                      {formatMoney(orderTotal(order), config)}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  )
}
