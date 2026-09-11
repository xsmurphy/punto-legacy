"use client"

/**
 * Detalle de una orden en el PANEL — ítems, línea de tiempo de estados,
 * cliente, espacio, responsable, repartidor, totales y, si es de delivery, el
 * mapa de la entrega.
 *
 * ── Página y no Dialog/Sheet ────────────────────────────────────────────────
 * context/14 §2.2 pone el Dialog como default para un panel de detalle. Acá se
 * elige página, con el mismo criterio que `/transactions/[id]` (context/39),
 * que reemplazó justamente a un Dialog embebido:
 *   - el contenido es DENSO y ancho: tabla de ítems, línea de tiempo larga (una
 *     orden con varias vueltas acumula decenas de transiciones) y un mapa;
 *   - tiene URL propia: se comparte ("mirá la orden 1532") y sobrevive a un
 *     recargar; un modal no;
 *   - "Volver" regresa al tab Listado con el rango intacto (`?tab=listado`).
 * Un Sheet lateral queda descartado por la misma §2.2: es para paneles
 * auxiliares, no para contenido denso que necesita ancho.
 *
 * ── Solo lectura ────────────────────────────────────────────────────────────
 * Operar la orden (cobrar, cancelar, reasignar) es de la caja
 * (`components/orders/order-detail-view.tsx`), con el permiso del operador del
 * PIN. El panel mira hacia atrás; no se duplican acciones acá.
 *
 * Datos: `OrderCoreService::find()` vía `useOrderDetail` (cliente del panel).
 */

import * as React from "react"
import Link from "next/link"
import { useParams } from "next/navigation"
import { ArrowLeft, ClipboardList, History, Lock, Truck } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { OrderStatusBadge } from "@/components/orders/order-status-badge"
import { OrderDeliverySection } from "@/components/orders/order-delivery-map"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"
import { isAddonChild, useOrderDetail, type Order, type OrderItem } from "@/hooks/use-orders"
import { formatMoney } from "@/lib/format"
import { formatDateTime, parseNaive } from "@/lib/format-date"
import { KDS_ITEM_VISUALS } from "@/lib/kds/kds-visuals"
import { eventActorLabel, eventTransitionLabel } from "@/lib/orders/order-event-label"
import {
  orderDestination,
  orderFulfillmentLabel,
  orderSourceLabel,
  orderTotal,
} from "@/lib/orders/order-display"
import { formatMinutes, orderStageDurations } from "@/lib/orders/order-stage-marks"
import { resolveNumberLocale } from "@/lib/tenant-locale"
import { cn } from "@/lib/utils"
import type { Bootstrap } from "@/lib/types/bootstrap"

/** Mismo permiso que el reporte desde el que se llega (`/reports/orders`). */
const VIEW_PERMISSION = "reports.sales.view"

export default function OrderDetailPage() {
  const params = useParams<{ id: string }>()
  const id = typeof params?.id === "string" ? params.id : null
  const { data: bootstrap } = useBootstrap()
  const canView = usePermission(VIEW_PERMISSION)
  const { data: order, isLoading, error } = useOrderDetail(bootstrap && canView ? id : null)

  if (!bootstrap || (canView && isLoading)) {
    return <DetailSkeleton />
  }

  if (!canView) {
    return (
      <Shell>
        <EmptyState
          icon={Lock}
          title="No tenés acceso a este detalle"
          description="Ver órdenes requiere el permiso de reportes de ventas. Pedíselo a quien administra el comercio."
        />
      </Shell>
    )
  }

  if (error || !order) {
    return (
      <Shell>
        <EmptyState
          icon={ClipboardList}
          title="No encontramos esta orden"
          description="Puede que el enlace esté incompleto. Volvé al listado de órdenes y abrila desde ahí."
        />
      </Shell>
    )
  }

  return <OrderDetail order={order} bootstrap={bootstrap} />
}

function OrderDetail({ order, bootstrap }: { order: Order; bootstrap: Bootstrap }) {
  const destination = orderDestination(order)
  const DestinationIcon = destination.icon

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold">Orden #{order.orderNumber ?? "—"}</h1>
            <OrderStatusBadge order={order} interactive={false} />
          </div>
          <p className="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
            <DestinationIcon className="size-3.5" aria-hidden />
            <span>{destination.label}</span>
            {order.createdAt && <span>· {formatDateTime(order.createdAt, "d MMM yyyy HH:mm")}</span>}
            {order.outletName && <span>· {order.outletName}</span>}
          </p>
        </div>
        <p className="text-2xl font-semibold tabular-nums">{formatMoney(orderTotal(order), bootstrap)}</p>
      </header>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="flex flex-col gap-4 lg:col-span-2">
          <ItemsCard order={order} bootstrap={bootstrap} />
          {order.fulfillment === "delivery" && (
            <Card>
              <CardHeader className="pb-2">
                <CardTitle>Entrega</CardTitle>
                <p className="text-sm text-muted-foreground">
                  Desde la sucursal de la orden hasta el destino que quedó guardado al crearla.
                </p>
              </CardHeader>
              <CardContent>
                <OrderDeliverySection order={order} bootstrap={bootstrap} />
              </CardContent>
            </Card>
          )}
          <TimelineCard order={order} bootstrap={bootstrap} />
        </div>
        <SummaryCard order={order} />
      </div>
    </div>
  )
}

// ── Ítems ───────────────────────────────────────────────────────────────────

function ItemsCard({ order, bootstrap }: { order: Order; bootstrap: Bootstrap }) {
  const items = order.items ?? []
  const qtyFormat = React.useMemo(
    () => new Intl.NumberFormat(resolveNumberLocale(bootstrap), { maximumFractionDigits: 3 }),
    [bootstrap],
  )

  // Motivo de anulación por línea, resuelto contra la línea de tiempo: la
  // pregunta "¿por qué falta esto?" se hace mirando la línea, no el historial.
  const cancelReason = React.useMemo(() => {
    const map = new Map<string, string>()
    for (const ev of order.events ?? []) {
      if (ev.scope === "item" && ev.orderItemId && ev.toStatus === "cancelled" && ev.reason) {
        map.set(ev.orderItemId, ev.reason)
      }
    }
    return map
  }, [order.events])

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle>Ítems ({items.filter((i) => !isAddonChild(i)).length})</CardTitle>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <EmptyState
            icon={ClipboardList}
            title="Sin ítems"
            description="Esta orden se creó sin líneas."
            showMarquee={false}
            className="border-0 p-0"
          />
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Ítem</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead className="text-right">Cant.</TableHead>
                  <TableHead className="text-right">Precio</TableHead>
                  <TableHead className="text-right">Subtotal</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item) => (
                  <ItemRow
                    key={item.id}
                    item={item}
                    reason={cancelReason.get(item.id) ?? null}
                    qty={qtyFormat.format(item.qty)}
                    bootstrap={bootstrap}
                  />
                ))}
              </TableBody>
              <TableFooter>
                <TableRow>
                  <TableCell colSpan={4} className="text-right font-medium">
                    Total
                  </TableCell>
                  <TableCell className="text-right font-semibold tabular-nums">
                    {formatMoney(orderTotal(order), bootstrap)}
                  </TableCell>
                </TableRow>
              </TableFooter>
            </Table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function ItemRow({
  item,
  reason,
  qty,
  bootstrap,
}: {
  item: OrderItem
  reason: string | null
  qty: string
  bootstrap: Bootstrap
}) {
  const child = isAddonChild(item)
  const cancelled = item.status === "cancelled"
  // Una línea hija (add-on) no tiene ciclo ni precio propio: viaja con su
  // padre y su recargo ya está en el precio del padre (context/41). Se
  // muestra indentada y con el recargo como dato, sin sumarlo otra vez.
  return (
    <TableRow className={cn(child && "text-muted-foreground")}>
      <TableCell className={cn(child && "pl-8")}>
        <span className={cn(cancelled && "text-muted-foreground line-through")}>
          {child ? `+ ${item.name}` : item.name}
        </span>
        {item.note && <span className="block text-xs text-muted-foreground">{item.note}</span>}
        {cancelled && reason && (
          <span className="block text-xs text-muted-foreground">Motivo de la anulación: {reason}</span>
        )}
      </TableCell>
      <TableCell className="text-muted-foreground">
        {child ? "" : (KDS_ITEM_VISUALS[item.status]?.label ?? item.status)}
      </TableCell>
      <TableCell className={cn("text-right tabular-nums", cancelled && "line-through")}>{qty}</TableCell>
      <TableCell className="text-right tabular-nums">
        {child
          ? item.priceDelta
            ? `+ ${formatMoney(item.priceDelta, bootstrap)}`
            : "—"
          : formatMoney(item.price ?? 0, bootstrap)}
      </TableCell>
      <TableCell className={cn("text-right tabular-nums", cancelled && "text-muted-foreground line-through")}>
        {child ? "" : formatMoney((item.price ?? 0) * item.qty, bootstrap)}
      </TableCell>
    </TableRow>
  )
}

// ── Línea de tiempo ─────────────────────────────────────────────────────────

function TimelineCard({ order, bootstrap }: { order: Order; bootstrap: Bootstrap }) {
  const events = React.useMemo(() => order.events ?? [], [order.events])
  const durations = orderStageDurations(events, order.createdAt)

  const itemNames = React.useMemo(
    () => new Map((order.items ?? []).map((i) => [i.id, i.name])),
    [order.items],
  )

  // Minutos desde el cambio de estado ANTERIOR de la orden — solo en los de
  // orden: entre dos líneas de ítem no dice nada útil.
  const sincePrevious = React.useMemo(() => {
    let last: number | null = null
    return events.map((ev) => {
      if (ev.scope !== "order") return null
      const t = ev.createdAt ? (parseNaive(ev.createdAt)?.getTime() ?? null) : null
      const diff = t !== null && last !== null && t > last ? (t - last) / 60_000 : null
      if (t !== null) last = t
      return diff
    })
  }, [events])

  const show = (m: number | null) => (m === null ? "—" : formatMinutes(m, bootstrap))

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle>Línea de tiempo</CardTitle>
        <p className="text-sm text-muted-foreground">
          Cada cambio de estado y quién lo hizo. Las demoras se miden igual que en
          el dashboard de órdenes.
        </p>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <StatsRow>
          <StatTile label="Espera" value={show(durations.toProgress)} />
          <StatTile label="Proceso" value={show(durations.progressToReady)} />
          <StatTile label="Entrega" value={show(durations.readyToDelivered)} />
          <StatTile label="Total" value={show(durations.total)} emphasis />
        </StatsRow>
        {durations.skipped && (
          <p className="text-sm text-muted-foreground">
            Esta orden pasó a Entregada sin marcar En proceso o Lista: tiene total
            pero no demoras por etapa, y en el dashboard cuenta entre las que
            saltaron etapas.
          </p>
        )}

        {events.length === 0 ? (
          <EmptyState
            icon={History}
            title="Sin movimientos registrados"
            description="Esta orden no tiene historial de estados, así que no aporta a los tiempos del dashboard."
            showMarquee={false}
            className="border-0 p-0"
          />
        ) : (
          <ol className="flex flex-col">
            {events.map((ev, idx) => {
              const elapsed = sincePrevious[idx]
              const isItem = ev.scope === "item"
              return (
                <li key={idx} className="flex gap-3 border-b border-border py-2 last:border-0">
                  <span className="w-28 shrink-0 text-xs text-muted-foreground tabular-nums">
                    {ev.createdAt ? formatDateTime(ev.createdAt, "d MMM HH:mm") : "—"}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className={cn("text-sm", isItem ? "text-muted-foreground" : "font-medium")}>
                      {isItem && `${(ev.orderItemId && itemNames.get(ev.orderItemId)) || "Ítem"}: `}
                      {eventTransitionLabel(ev)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {eventActorLabel(ev)}
                      {ev.stationName ? ` · ${ev.stationName}` : ""}
                      {ev.reason ? ` · ${ev.reason}` : ""}
                    </p>
                  </div>
                  {elapsed !== null && (
                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                      +{formatMinutes(elapsed, bootstrap)}
                    </span>
                  )}
                </li>
              )
            })}
          </ol>
        )}
      </CardContent>
    </Card>
  )
}

// ── Resumen ─────────────────────────────────────────────────────────────────

function SummaryCard({ order }: { order: Order }) {
  const isDelivery = order.fulfillment === "delivery"
  return (
    <Card className="h-fit">
      <CardHeader className="pb-2">
        <CardTitle>Resumen</CardTitle>
      </CardHeader>
      <CardContent>
        <dl className="flex flex-col divide-y divide-border">
          <SummaryRow label="Cliente">{order.customerName ?? <Muted>Sin cliente</Muted>}</SummaryRow>
          <SummaryRow label="Origen">{orderSourceLabel(order)}</SummaryRow>
          <SummaryRow label="Entrega">{orderFulfillmentLabel(order)}</SummaryRow>
          {order.spaceName && <SummaryRow label="Espacio">{order.spaceName}</SummaryRow>}
          <SummaryRow label="Responsable">{order.userName ?? <Muted>Sin registrar</Muted>}</SummaryRow>
          {isDelivery && (
            <SummaryRow label="Repartidor">
              {order.courierName ? (
                <span className="inline-flex items-center gap-1.5">
                  <Truck className="size-3.5 text-muted-foreground" aria-hidden />
                  {order.courierName}
                </span>
              ) : (
                <Muted>Sin asignar</Muted>
              )}
            </SummaryRow>
          )}
          <SummaryRow label="Sucursal">{order.outletName ?? <Muted>—</Muted>}</SummaryRow>
          <SummaryRow label="Creada">
            {order.createdAt ? formatDateTime(order.createdAt, "d MMM yyyy HH:mm") : <Muted>—</Muted>}
          </SummaryRow>
          {order.sentAt && (
            <SummaryRow label="Enviada">{formatDateTime(order.sentAt, "d MMM yyyy HH:mm")}</SummaryRow>
          )}
          {order.closedAt && (
            <SummaryRow label="Cerrada">{formatDateTime(order.closedAt, "d MMM yyyy HH:mm")}</SummaryRow>
          )}
          <SummaryRow label="Cobro">
            {order.saleTransactionId ? (
              <Link href={`/transactions/${order.saleTransactionId}`} className="hover:underline">
                Ver venta
              </Link>
            ) : (
              <Muted>Sin cobrar</Muted>
            )}
          </SummaryRow>
          {order.note && <SummaryRow label="Nota">{order.note}</SummaryRow>}
        </dl>
      </CardContent>
    </Card>
  )
}

function SummaryRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2">
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-right text-sm font-medium">{children}</dd>
    </div>
  )
}

function Muted({ children }: { children: React.ReactNode }) {
  return <span className="font-normal text-muted-foreground">{children}</span>
}

// ── Chrome ──────────────────────────────────────────────────────────────────

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <BackLink />
        <h1 className="text-2xl font-semibold">Orden</h1>
      </header>
      {children}
    </div>
  )
}

function DetailSkeleton() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-2">
        <Skeleton className="h-7 w-32" />
        <Skeleton className="h-8 w-48" />
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="flex flex-col gap-4 lg:col-span-2">
          <Skeleton className="h-64 w-full" />
          <Skeleton className="h-80 w-full" />
        </div>
        <Skeleton className="h-96 w-full" />
      </div>
    </div>
  )
}

function BackLink() {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href="/reports/orders?tab=listado">
        <ArrowLeft className="size-3.5" />
        Volver a órdenes
      </Link>
    </Button>
  )
}
