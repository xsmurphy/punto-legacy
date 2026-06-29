"use client"

/**
 * Reporte de Auditoría — acciones mutantes de usuarios del comercio.
 *
 * Backend: GET /v1/reports/audit?from=&to=
 * → { rows: AuditRow[] }
 *
 * Solo visible para roles con privilegio (el backend ya gate-ea).
 * Muestra fecha, usuario, acción humanizada, sucursal e IP.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, ShieldCheck } from "lucide-react"

import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"
import { useReport, type AuditRow } from "@/hooks/use-reports"

/** Humaniza method + endpoint a una etiqueta legible. */
function humanizeAction(method: string, endpoint: string): string {
  const m = (method ?? "").toUpperCase()
  const e = (endpoint ?? "").replace(/\/api\/v1\//, "").replace(/\/v1\//, "").replace(/^\//, "")

  const MAP: Array<[RegExp, string]> = [
    [/^items?(\/|$)/i,          m === "POST" ? "Creó artículo"     : m === "DELETE" ? "Eliminó artículo"  : "Editó artículo"],
    [/^contacts?(\/|$)/i,       m === "POST" ? "Creó contacto"     : m === "DELETE" ? "Eliminó contacto"  : "Editó contacto"],
    [/^transactions?(\/|$)/i,   m === "POST" ? "Creó transacción"  : m === "DELETE" ? "Anuló transacción" : "Editó transacción"],
    [/^outlets?(\/|$)/i,        m === "POST" ? "Creó sucursal"     : m === "DELETE" ? "Eliminó sucursal"  : "Editó sucursal"],
    [/^settings?(\/|$)/i,                                                                                    "Cambió configuración"],
    [/^purchases?(\/|$)/i,      m === "POST" ? "Creó compra"       : m === "DELETE" ? "Eliminó compra"    : "Editó compra"],
    [/^expenses?(\/|$)/i,       m === "POST" ? "Creó gasto"        : m === "DELETE" ? "Eliminó gasto"     : "Editó gasto"],
    [/^reports\/drawers/i,                                                                                   "Cerró/corrigió caja"],
    [/^stock(\/|$)/i,           m === "POST" ? "Ajustó stock"      : "Editó stock"],
    [/^taxonomy(\/|$)/i,        m === "POST" ? "Creó categoría"    : m === "DELETE" ? "Eliminó categoría" : "Editó categoría"],
    [/^users?(\/|$)/i,          m === "POST" ? "Creó usuario"      : m === "DELETE" ? "Eliminó usuario"   : "Editó usuario"],
  ]

  for (const [re, label] of MAP) {
    if (re.test(e)) return label
  }

  // Fallback: method + endpoint crudo
  return `${m} /${e}`
}

export default function AuditReportPage() {
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<{ rows: AuditRow[] }>("audit", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  const columns = React.useMemo<ColumnDef<AuditRow>[]>(
    () => [
      {
        accessorKey: "createdAt",
        header: "Fecha",
        cell: ({ getValue }) => {
          const v = getValue() as string
          if (!v) return <span className="text-muted-foreground">—</span>
          const d = new Date(v)
          return (
            <span className="tabular-nums text-sm">
              {d.toLocaleDateString("es", { day: "2-digit", month: "short", year: "numeric" })}{" "}
              <span className="text-muted-foreground text-xs">
                {d.toLocaleTimeString("es", { hour: "2-digit", minute: "2-digit" })}
              </span>
            </span>
          )
        },
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        cell: ({ getValue }) => (
          <span className="font-medium">{(getValue() as string) || "(desconocido)"}</span>
        ),
        meta: { label: "Usuario" },
      },
      {
        id: "action",
        header: "Acción",
        accessorFn: (row) => humanizeAction(row.method, row.endpoint),
        cell: ({ getValue }) => (
          <span className="text-sm">{getValue() as string}</span>
        ),
        meta: { label: "Acción" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-sm text-muted-foreground">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "ip",
        header: "IP",
        cell: ({ getValue }) => (
          <span className="font-mono text-xs text-muted-foreground">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "IP" },
      },
    ],
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Auditoría</h1>
          <p className="text-sm text-muted-foreground">
            Registro de acciones realizadas por usuarios del comercio.
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
        tableId="report-audit"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder="Buscar por usuario, acción o IP…"
        exportFileName="auditoria_tenant"
        emptyMessage={
          <EmptyState
            icon={ShieldCheck}
            title="Sin registros de auditoría"
            description="Ajustá el rango de fechas y volvé a consultar."
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
