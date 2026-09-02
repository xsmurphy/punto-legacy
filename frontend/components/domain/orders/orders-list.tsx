"use client"

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, ClipboardList } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { EmptyState } from "@/components/empty-state"
import { OrderStatusBadge } from "@/components/orders/order-status-badge"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type OrderRow, type OrdersReportResponse } from "@/hooks/use-reports"
import type { Order } from "@/hooks/use-orders"
import { formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"

/**
 * Adapta la fila liviana del reporte (`OrderRow`) al shape completo de
 * `Order` que espera `OrderStatusBadge` — este reporte NO trae `fulfillment`
 * ni el resto de los campos operativos de `pos_order`, así que se completan
 * con neutro/null. `interactive={false}` (solo lectura acá) hace que el
 * componente solo toque `status`/`fulfillment` para pintar el Badge, nunca
 * el resto. Evita redefinir STATUS_MAP en este archivo (esa duplicación
 * causó el bug T5 original).
 */
function toOrderStub(row: OrderRow): Order {
  return {
    id: row.id,
    companyId: "",
    outletId: "",
    registerId: null,
    source: row.channel === "ecom" ? "ecommerce" : "counter",
    status: row.status,
    orderNumber: null,
    spaceSessionId: null,
    customerId: null,
    courierId: null,
    courierName: null,
    customerName: row.customerName || null,
    customerLat: null,
    customerLng: null,
    spaceName: null,
    userId: null,
    note: null,
    channelRef: null,
    saleTransactionId: null,
    createdAt: row.date,
    sentAt: null,
    closedAt: null,
    fulfillment: "dine_in",
    deliveryAddressId: null,
    deliveryAddress: null,
    deliveryReference: null,
    deliveryLat: null,
    deliveryLng: null,
  }
}


interface OrdersListProps {
  backHref: string
  /** Filtrar por cliente (UUID). Cuando se pasa, se omite el BackLink y el header de página. */
  customerIdFilter?: string
}

export function OrdersList({ backHref, customerIdFilter }: OrdersListProps) {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(
    () => ({
      ...rangeToBackend(range),
      ...(customerIdFilter ? { params: { customerId: customerIdFilter } } : {}),
    }),
    [range, customerIdFilter],
  )

  const { data, isLoading, error } = useReport<OrdersReportResponse>("orders", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  const columns = React.useMemo<ColumnDef<OrderRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha / Hora",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatDateTime((getValue() as string) ?? "", "d MMM yyyy HH:mm")}</span>
        ),
        meta: { label: "Fecha / Hora", className: "tabular-nums" },
      },
      {
        accessorKey: "orderNo",
        header: "Orden",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? <span className="tabular-nums">{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Orden", className: "tabular-nums" },
      },
      {
        accessorKey: "customerName",
        header: "Cliente",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? <span className="font-medium">{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Cliente" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "channel",
        header: "Canal",
        cell: ({ getValue }) => {
          const c = getValue() as string
          return c === "ecom" ? (
            <Badge variant="secondary" className="text-[10px]">Online</Badge>
          ) : (
            <Badge variant="outline" className="text-[10px]">Local</Badge>
          )
        },
        meta: { label: "Canal" },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ row }) => <OrderStatusBadge order={toOrderStub(row.original)} interactive={false} />,
        meta: { label: "Estado" },
      },
      {
        accessorKey: "total",
        header: "Total",
        cell: ({ getValue }) => {
          const v = Number(getValue()) || 0
          if (v === 0) return <span className="opacity-40">—</span>
          return <span className="tabular-nums font-medium">{formatMoney(v, bootstrap)}</span>
        },
        meta: { label: "Total", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      {!customerIdFilter && (
        <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div className="flex flex-col gap-1">
            <BackLink backHref={backHref} />
            <h1 className="text-2xl font-semibold">Órdenes</h1>
            <p className="text-sm text-muted-foreground">
              Órdenes del período con su estado y canal de venta.
            </p>
          </div>
          <DateRangePicker value={range} onChange={setRange} />
        </header>
      )}
      {customerIdFilter && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">Órdenes de este cliente</p>
          <DateRangePicker value={range} onChange={setRange} />
        </div>
      )}

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      <DataTable
        tableId="report-orders"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder="Buscar por orden, cliente, sucursal…"
        exportFileName="ordenes"
        emptyMessage={
          <EmptyState
            icon={ClipboardList}
            title="Sin órdenes en este período"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
  )
}

function BackLink({ backHref }: { backHref: string }) {
  const isPos = backHref.includes("/pos")
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href={backHref}>
        <ArrowLeft className="size-3.5" />
        {!isPos && "Volver a reportes"}
      </Link>
    </Button>
  )
}
