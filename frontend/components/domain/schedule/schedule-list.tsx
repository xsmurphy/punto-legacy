"use client"

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, CalendarDays } from "lucide-react"

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
import { useReport, type ScheduleRow, type ScheduleReportResponse } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"

// status: 0=Pendiente, 4=Cancelado, 5=No show, 6=Finalizado, 7=Bloqueado
const STATUS_MAP: Record<number, { label: string; variant: "default" | "secondary" | "outline" | "destructive" }> = {
  0: { label: "Pendiente", variant: "outline" },
  4: { label: "Cancelado", variant: "destructive" },
  5: { label: "No show", variant: "secondary" },
  6: { label: "Finalizado", variant: "default" },
  7: { label: "Bloqueado", variant: "secondary" },
}


interface ScheduleListProps {
  backHref: string
  /** Filtrar por cliente (UUID). Cuando se pasa, se omite el BackLink y el header de página. */
  customerIdFilter?: string
}

export function ScheduleList({ backHref, customerIdFilter }: ScheduleListProps) {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(
    () => ({
      ...rangeToBackend(range),
      params: {
        view: "detail",
        ...(customerIdFilter ? { customerId: customerIdFilter } : {}),
      },
    }),
    [range, customerIdFilter],
  )

  const { data, isLoading, error } = useReport<ScheduleReportResponse>("schedule", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])
  const summary = data?.summary

  const columns = React.useMemo<ColumnDef<ScheduleRow>[]>(
    () => [
      {
        accessorKey: "fromDate",
        header: "Fecha / Hora",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatDateTime((getValue() as string) ?? "", "d MMM yyyy HH:mm")}</span>
        ),
        meta: { label: "Fecha / Hora", className: "tabular-nums" },
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
        accessorKey: "responsibleName",
        header: "Responsable",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Responsable" },
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
        accessorKey: "items",
        header: "Servicios",
        cell: ({ getValue }) => {
          const items = getValue() as string[]
          if (!items?.length) return <span className="opacity-40">—</span>
          return (
            <div className="flex flex-wrap gap-1">
              {items.map((item, i) => (
                <Badge key={i} variant="outline" className="text-[10px]">
                  {item}
                </Badge>
              ))}
            </div>
          )
        },
        meta: { label: "Servicios" },
      },
      {
        accessorKey: "transactionStatus",
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
          return (
            <span className="tabular-nums font-medium">
              {formatMoney(v, bootstrap)}
            </span>
          )
        },
        meta: { label: "Total", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "note",
        header: "Nota",
        cell: ({ getValue }) => {
          const v = (getValue() as string) ?? ""
          return v ? <span className="text-xs truncate">{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Nota" },
      },
    ],
    [bootstrap],
  )

  const initialColumnVisibility = React.useMemo(() => ({ note: false }), [])

  return (
    <div className="flex flex-col gap-6">
      {!customerIdFilter && (
        <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div className="flex flex-col gap-1">
            <BackLink backHref={backHref} />
            <h1 className="text-2xl font-semibold">Agendamientos</h1>
            <p className="text-sm text-muted-foreground">
              Citas programadas del período con su estado de asistencia.
            </p>
          </div>
          <DateRangePicker value={range} onChange={setRange} />
        </header>
      )}
      {customerIdFilter && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">Agendamientos de este cliente</p>
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

      {!isLoading && summary && summary.totals > 0 && (
        <div className="flex flex-wrap gap-4 border-y py-3 text-sm">
          <Stat label="Total" value={formatInt(summary.totals, bootstrap)} emphasis />
          <Stat label="Pendientes" value={formatInt(summary.new, bootstrap)} />
          <Stat label="Finalizados" value={formatInt(summary.ended, bootstrap)} />
          <Stat label="Cancelados" value={formatInt(summary.cancelled, bootstrap)} />
          <Stat label="No shows" value={formatInt(summary.noshow, bootstrap)} />
        </div>
      )}

      <DataTable
        tableId="report-schedule"
        data={rows}
        columns={columns}
        initialColumnVisibility={initialColumnVisibility}
        getRowId={(r) => r.transactionId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por cliente, responsable, servicio…"
        exportFileName="agendamientos"
        emptyMessage={
          <EmptyState
            icon={CalendarDays}
            title="Sin agendamientos"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
  )
}

function Stat({ label, value, emphasis }: { label: string; value: string; emphasis?: boolean }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</span>
      <span
        className={
          emphasis
            ? "text-base font-semibold tabular-nums"
            : "text-sm font-medium tabular-nums"
        }
      >
        {value}
      </span>
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
