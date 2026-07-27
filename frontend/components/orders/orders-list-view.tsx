"use client"

/**
 * Vista lista de /pos/ordenes — tabla compacta táctil con buscador en tiempo
 * real sobre las órdenes YA cargadas (nº de orden, cliente, ítems, nota).
 *
 * NO usa el `<DataTable>` del panel a propósito: ese trae paginación, export
 * y column-toggle, que en una caja táctil estorban más de lo que aportan. Acá
 * alcanza `<Table>` de shadcn + un `<Input>` de búsqueda client-side.
 *
 * El estado del buscador vive ACÁ (no en la página) para que tipear
 * re-renderice solo esta vista y no el mapa, que se mantiene montado.
 */

import * as React from "react"
import { Search } from "lucide-react"

import { Badge } from "@/components/ui/badge"
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
import { formatMoney } from "@/lib/format-money"
import { formatTime } from "@/lib/format-date"
import { useCatalogStore } from "@/lib/catalog/store"
import type { Order } from "@/hooks/use-orders"
import {
  STATUS_VARIANT,
  orderDestination,
  orderItemsSummary,
  orderSearchHaystack,
  orderTotal,
  statusLabelFor,
} from "@/lib/orders/order-display"

export function OrdersListView({
  orders,
  onOpenOrder,
}: {
  orders: Order[]
  onOpenOrder: (order: Order) => void
}) {
  const config = useCatalogStore((s) => s.config)
  const [query, setQuery] = React.useState("")

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
          placeholder="Buscar por número, cliente o ítem"
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
                <TableHead className="w-20">Orden</TableHead>
                <TableHead>Cliente</TableHead>
                <TableHead className="hidden md:table-cell">Ítems</TableHead>
                <TableHead className="w-32">Destino</TableHead>
                <TableHead className="w-20">Hora</TableHead>
                <TableHead className="w-32">Estado</TableHead>
                <TableHead className="w-28 text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((order) => {
                const destination = orderDestination(order)
                return (
                <TableRow
                  key={order.id}
                  // h-14: fila táctil (>44px) — se abre con el dedo en tablet.
                  className="h-14 cursor-pointer"
                  onClick={() => onOpenOrder(order)}
                >
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
                    <span className="flex items-center gap-1.5">
                      <destination.icon className="size-3.5 shrink-0" aria-hidden />
                      <span className="truncate">{destination.label}</span>
                    </span>
                  </TableCell>
                  <TableCell className="tabular-nums text-muted-foreground">
                    {order.sentAt
                      ? formatTime(order.sentAt)
                      : order.createdAt
                        ? formatTime(order.createdAt)
                        : "—"}
                  </TableCell>
                  <TableCell>
                    <Badge variant={STATUS_VARIANT[order.status]}>
                      {statusLabelFor(order)}
                    </Badge>
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
