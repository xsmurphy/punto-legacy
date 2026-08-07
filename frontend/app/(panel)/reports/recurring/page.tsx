"use client"

/**
 * Reporte Facturas Recurrentes — espejo de panel/reports/recurring.html.
 *
 * Backend: GET /v1/reports/recurring → { rows: [...] }
 * (sin paginación por fecha — lista todas las recurrencias de la empresa)
 *
 * Reporte SNAPSHOT — NO date-scoped. Muestra el listado de facturas
 * recurrentes activas/pausadas con su próxima fecha de emisión.
 * estado: 1=activa, 2=pausada.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, RefreshCw } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type RecurringRow, type RecurringReportResponse } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

const FRECUENCY_LABELS: Record<string, string> = {
  weekly: "Semanal",
  biweekly: "Quincenal",
  monthly: "Mensual",
  bimonthly: "Bimestral",
  quarterly: "Trimestral",
  semiannual: "Semestral",
  annual: "Anual",
}


export default function RecurringReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { data, isLoading, error } = useReport<RecurringReportResponse>("recurring", {})
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  const totals = React.useMemo(() => {
    let active = 0
    let paused = 0
    let totalAmount = 0
    rows.forEach((r) => {
      if (r.status === 1) active++
      else paused++
      totalAmount += r.total
    })
    return { active, paused, totalAmount }
  }, [rows])

  const columns = React.useMemo<ColumnDef<RecurringRow>[]>(
    () => [
      {
        accessorKey: "clientName",
        header: "Cliente",
        cell: ({ row }) => {
          const r = row.original
          const name = [r.clientName, r.clientSecondName].filter(Boolean).join(" ")
          return (
            <span className="font-medium">{name || "(sin nombre)"}</span>
          )
        },
        meta: { label: "Cliente" },
      },
      {
        accessorKey: "invoiceNo",
        header: "N° Factura",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "N° Factura", className: "tabular-nums" },
      },
      {
        accessorKey: "frecuency",
        header: "Frecuencia",
        cell: ({ getValue }) => {
          const f = getValue() as string
          return (
            <Badge variant="outline" className="text-[10px]">
              {(FRECUENCY_LABELS[f] ?? f) || "—"}
            </Badge>
          )
        },
        meta: { label: "Frecuencia" },
      },
      {
        accessorKey: "nextDate",
        header: "Próxima emisión",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatDate((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Próxima emisión", className: "tabular-nums" },
      },
      {
        accessorKey: "endDate",
        header: "Vence",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatDate((getValue() as string) ?? "")}
          </span>
        ),
        meta: { label: "Vence", className: "tabular-nums" },
      },
      {
        accessorKey: "total",
        header: "Monto",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Monto", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ getValue }) => {
          const s = Number(getValue())
          return s === 1 ? (
            <Badge variant="default" className="text-[10px]">Activa</Badge>
          ) : (
            <Badge variant="secondary" className="text-[10px]">Pausada</Badge>
          )
        },
        meta: { label: "Estado" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <BackLink />
        <h1 className="text-2xl font-semibold">Facturas Recurrentes</h1>
        <p className="text-sm text-muted-foreground">
          Listado de facturas programadas para emisión automática.
        </p>
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

      {!isLoading && rows.length > 0 && (
        <StatsRow>
          <StatTile label="Activas" value={formatInt(totals.active, bootstrap)} emphasis />
          <StatTile label="Pausadas" value={formatInt(totals.paused, bootstrap)} />
          <StatTile
            label="Monto recurrente total"
            value={formatMoney(totals.totalAmount, bootstrap)}
          />
        </StatsRow>
      )}

      <DataTable
        tableId="report-recurring"
        data={rows}
        columns={columns}
        getRowId={(r) => r.recurringId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por cliente, N° factura…"
        exportFileName="facturas_recurrentes"
        emptyMessage={
          <EmptyState
            icon={RefreshCw}
            title="Sin facturas recurrentes"
            description="No hay facturas programadas para emisión automática."
          />
        }
      />
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
      <Link href="/reports">
        <ArrowLeft className="size-3.5" />
        Volver a reportes
      </Link>
    </Button>
  )
}
