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
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type OrderRow, type OrdersReportResponse } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"

// transactionStatus → label/variant
const STATUS_MAP: Record<number, { label: string; variant: "default" | "secondary" | "outline" | "destructive" }> = {
  0: { label: "Pendiente", variant: "outline" },
  1: { label: "Pendiente", variant: "outline" },
  2: { label: "En espera", variant: "secondary" },
  3: { label: "En proceso", variant: "secondary" },
  4: { label: "Finalizado", variant: "default" },
  5: { label: "Enviado", variant: "default" },
  6: { label: "Cancelado", variant: "destructive" },
}

function niceDate(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(undefined, {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
}

interface OrdersListProps {
  backHref: string
}

export function OrdersList({ backHref }: OrdersListProps) {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<OrdersReportResponse>("orders", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  const columns = React.useMemo<ColumnDef<OrderRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha / Hora",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{niceDate((getValue() as string) ?? "")}</span>
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
        cell: ({ getValue }) => {
          const st = Number(getValue())
          const b = STATUS_MAP[st] ?? { label: String(st), variant: "secondary" as const }
          return <Badge variant={b.variant} className="text-[10px]">{b.label}</Badge>
        },
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
