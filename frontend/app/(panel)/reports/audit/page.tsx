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
import { format } from "date-fns"
import { es } from "date-fns/locale"
import { AlertCircle, ArrowLeft, ShieldCheck } from "lucide-react"

import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
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

/**
 * ¿La fila quedó a nombre de una TERMINAL en vez de una persona?
 *
 * Bajo el realm `pos-app` el backend atribuye la fila al operador que probó su
 * PIN (`meta.actor === "operator"`). Cuando no hubo ninguno, el `userId` es el
 * contacto que PAREÓ la tablet y no se sabe quién la estaba usando
 * (`meta.actor === "device"`) — y eso hay que decirlo, porque una fila que dice
 * un nombre sin aclararlo se lee como si esa persona hubiera estado ahí.
 *
 * Las filas anteriores al fix de atribución no traen `meta.actor`; no se marcan
 * (no hay dato para afirmar una cosa ni la otra).
 */
function isUnattributedDevice(row: AuditRow): boolean {
  return row.meta?.actor === "device"
}

/**
 * Retención de la tabla `tenant_audit`: el job de pg_cron `purge-tenant-audit`
 * borra todos los días lo que tenga más de 2 meses (migs 36 y 150). Se nombra
 * en el empty state porque explica el único caso en que el vacío NO es
 * "no hubo actividad" sino "ya se purgó".
 */
const AUDIT_RETENTION_LABEL = "2 meses"

export default function AuditReportPage() {
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<{ rows: AuditRow[] }>("audit", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  // El empty state nombra el período consultado. Un reporte de auditoría vacío
  // es ambiguo por naturaleza —"no pasó nada" y "esto no está funcionando" se
  // ven igual—, y es la ambigüedad que hizo que se reportara como bug. Decir
  // qué rango se consultó y que la retención es de dos meses convierte el vacío
  // en una respuesta en vez de una duda.
  const rangeLabel = React.useMemo(
    () => ({
      from: format(range.from, "d 'de' MMMM", { locale: es }),
      to: format(range.to, "d 'de' MMMM", { locale: es }),
    }),
    [range],
  )

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
        cell: ({ row, getValue }) => (
          <span className="flex flex-col">
            <span className="font-medium">
              {(getValue() as string) || "(desconocido)"}
            </span>
            {isUnattributedDevice(row.original) && (
              <span className="text-xs text-muted-foreground">
                Caja sin operador identificado
              </span>
            )}
          </span>
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
            title={`Sin actividad entre el ${rangeLabel.from} y el ${rangeLabel.to}`}
            description={
              <>
                La auditoría está activa: nadie del comercio realizó acciones
                registrables en este período. Probá con un rango más amplio.
                <br />
                Los registros se conservan {AUDIT_RETENTION_LABEL}; un rango
                anterior a eso siempre va a salir vacío.
              </>
            }
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
