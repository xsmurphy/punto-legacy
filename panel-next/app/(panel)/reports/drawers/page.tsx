"use client"

/**
 * Reporte de Control de Cajas (Drawers).
 *
 * Replica funcional del legacy `panel/reports/drawers.html` + a_report_drawers.js:
 * lista paginada de aperturas/cierres con totales, diferencia, estado, usuario
 * que abre y cierra. Date range arriba; DataTable abajo.
 *
 * El backend (`/v1/reports/drawers`) ya devuelve los nombres resueltos
 * (outletName, registerName, openUserName, closeUserName) y los componentes
 * de venta (`sold`, `expense`, `income`, `return`) para el cálculo de la
 * diferencia entre caja teórica y caja contada.
 *
 * Acciones (cerrar / corregir / eliminar) las deja como follow-up — este
 * slice cubre solo el listado y la diferencia, que es el 80% del uso real.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, ArrowDown, ArrowUp, Wallet } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type DrawerRow, type DrawersReportResponse } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { DrawerDetailModal } from "@/components/reports/drawer-detail-modal"
import { cn } from "@/lib/utils"
import { EmptyState } from "@/components/empty-state"

export default function DrawersReportPage() {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const [selectedDrawer, setSelectedDrawer] = React.useState<DrawerRow | null>(null)
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<DrawersReportResponse>("drawers", opts)

  const rows = data?.rows ?? []

  /** Caja teórica = openAmount + ventas en efectivo + ingresos − extracciones.
   *  Este cálculo lo hace el legacy en cliente porque combina varios campos
   *  agregados que el endpoint devuelve crudos. La diferencia con `closeAmount`
   *  es lo que detecta faltantes o sobrantes en arqueo. */
  const computeTheoretical = React.useCallback((r: DrawerRow): number => {
    const open    = parseNum(r.openAmount)
    const sold    = parseNum(r.sold)
    const expense = parseNum(r.expense)
    const income  = parseNum(r.income)
    const ret     = parseNum(r.return)
    // Aproximación: solo cash impacta el cajón pero acá no tenemos el split por
    // método de pago — el legacy lo recomputa en el detail. Para el listado, el
    // cálculo agregado es: openAmount + sold + income − expense − return.
    return open + sold + income - expense - Math.abs(ret)
  }, [])

  const computeDiff = React.useCallback(
    (r: DrawerRow): number => parseNum(r.closeAmount) - computeTheoretical(r),
    [computeTheoretical],
  )

  const columns = React.useMemo<ColumnDef<DrawerRow>[]>(
    () => [
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="font-medium">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "registerName",
        header: "Caja",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Caja" },
      },
      {
        accessorKey: "openDate",
        header: "Apertura",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{niceDateTime((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Apertura", className: "tabular-nums" },
      },
      {
        accessorKey: "openUserName",
        header: "Por",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Abrió" },
      },
      {
        accessorKey: "openAmount",
        header: "Monto inicial",
        cell: ({ getValue }) => (
          <span className="tabular-nums">
            {formatMoney(parseNum(getValue()), bootstrap)}
          </span>
        ),
        meta: { label: "Monto inicial", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "isClosed",
        header: "Cierre",
        cell: ({ row }) => {
          const r = row.original
          if (!r.isClosed) {
            return <Badge variant="default">En curso</Badge>
          }
          return (
            <span className="tabular-nums text-xs">{niceDateTime(r.closeDate ?? "")}</span>
          )
        },
        meta: { label: "Cierre" },
      },
      {
        accessorKey: "closeAmount",
        header: "Monto cierre",
        cell: ({ row }) => {
          const r = row.original
          if (!r.isClosed) return <span className="text-muted-foreground">—</span>
          return (
            <span className="tabular-nums">
              {formatMoney(parseNum(r.closeAmount), bootstrap)}
            </span>
          )
        },
        meta: { label: "Monto cierre", className: "tabular-nums text-right" },
      },
      {
        id: "diff",
        header: "Diferencia",
        cell: ({ row }) => {
          const r = row.original
          if (!r.isClosed) return <span className="text-muted-foreground">—</span>
          const d = computeDiff(r)
          if (Math.abs(d) < 1) {
            // Considerado "ok" — el legacy usaba tolerancia <1 unidad.
            return (
              <span className="inline-flex items-center gap-1 text-emerald-600">
                <Wallet className="size-3.5" />
                OK
              </span>
            )
          }
          const isShort = d < 0
          return (
            <span
              className={cn(
                "inline-flex items-center gap-1 tabular-nums",
                isShort ? "text-destructive" : "text-amber-600",
              )}
            >
              {isShort ? (
                <ArrowDown className="size-3.5" />
              ) : (
                <ArrowUp className="size-3.5" />
              )}
              {formatMoney(Math.abs(d), bootstrap)}
            </span>
          )
        },
        meta: { label: "Diferencia", className: "tabular-nums" },
      },
    ],
    [bootstrap, computeDiff],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Control de Cajas</h1>
          <p className="text-sm text-muted-foreground">
            Aperturas y cierres del período. La diferencia compara el monto contado
            con el teórico (apertura + ventas + ingresos − extracciones).
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudieron cargar los cierres</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      <DataTable
        tableId="report-drawers"
        data={rows}
        columns={columns}
        getRowId={(r) => r.drawerId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por sucursal, caja, usuario…"
        exportFileName="control_de_cajas"
        onRowClick={(row) => setSelectedDrawer(row)}
        emptyMessage={
          <EmptyState
            icon={Wallet}
            title="Sin aperturas en este período"
            description="Ajustá el rango de fechas o esperá la próxima apertura."
          />
        }
      />

      <DrawerDetailModal
        drawer={selectedDrawer}
        onClose={() => setSelectedDrawer(null)}
        onClosed={() => {
          // El hook ya invalida la query — solo cerramos el modal.
          setSelectedDrawer(null)
        }}
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

function parseNum(v: unknown): number {
  if (typeof v === "number") return v
  if (typeof v === "string" && v !== "") {
    const n = Number(v)
    return Number.isFinite(n) ? n : 0
  }
  return 0
}

function niceDateTime(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(undefined, {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  })
}
