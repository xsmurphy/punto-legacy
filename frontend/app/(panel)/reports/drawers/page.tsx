"use client"

/**
 * Reporte de Control de Cajas (Drawers).
 *
 * Replica funcional del legacy `panel/reports/drawers.html` + a_report_drawers.js:
 * lista paginada de aperturas/cierres con totales, diferencia, estado, usuario
 * que abre y cierra. Date range arriba; DataTable abajo.
 *
 * El backend (`/v1/reports/drawers`) ya devuelve los nombres resueltos
 * (outletName, registerName, openUserName, closeUserName), los componentes de
 * venta (`sold`, `cashSold`, `expense`, `income`, `return`) y —desde la mig
 * 164— el CUADRE ya resuelto: `expectedAmount`, `difference` y `cashStatus`.
 *
 * El cuadre NO se calcula acá. Antes sí, y estaba mal de dos maneras: sumaba
 * TODOS los medios de pago contra un monto contado que es solo efectivo (todo
 * turno con tarjeta salía con un faltante inventado), y recomputaba el
 * esperado con datos de hoy, así que el veredicto de un cierre viejo cambiaba
 * solo. Ahora el esperado se congela al cerrar y el veredicto lo emite
 * `Reports\CashCountStatus`, que es donde vive la tolerancia del comercio.
 *
 * Este semáforo es del PANEL, no de la caja: el cajero no ve la diferencia
 * (y con `blindControl` encendido ni siquiera ve el esperado — esa es toda la
 * gracia de la modalidad). El dueño la ve acá.
 *
 * Corregir el arqueo (fechas y montos de apertura/cierre) se hace desde el
 * menú de la fila — el cierre se carga a mano en el POS y se equivoca. Cerrar
 * y eliminar una caja siguen sin exponerse acá.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Pencil, Wallet } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type DrawerRow, type DrawersReportResponse } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { DrawerDetailModal } from "@/components/reports/drawer-detail-modal"
import { DrawerCorrectDialog } from "@/components/reports/drawer-correct-dialog"
import { CashCountBadge } from "@/components/reports/cash-count-badge"
import { EmptyState } from "@/components/empty-state"
import { formatDateTime } from "@/lib/format-date"

export default function DrawersReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const [selectedDrawer, setSelectedDrawer] = React.useState<DrawerRow | null>(null)
  const [correctDrawer, setCorrectDrawer] = React.useState<DrawerRow | null>(null)
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<DrawersReportResponse>("drawers", opts)

  const rows = data?.rows ?? []
  const tolerance = data?.tolerance

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
          <span className="tabular-nums">{formatDateTime((getValue() as string) ?? "")}</span>
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
            <span className="tabular-nums text-xs">{formatDateTime(r.closeDate ?? "")}</span>
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
        accessorKey: "expectedAmount",
        header: "Esperado",
        cell: ({ row }) => {
          const r = row.original
          const exp = r.expectedAmount
          if (exp === null || exp === undefined) {
            return <span className="text-muted-foreground">—</span>
          }
          return (
            <span className="tabular-nums">{formatMoney(parseNum(exp), bootstrap)}</span>
          )
        },
        meta: { label: "Esperado", className: "tabular-nums text-right" },
      },
      {
        // `accessorKey` (y no una columna `id` calculada) para que el sort de
        // la tabla agrupe los estados: ordenando por esta columna, todos los
        // faltantes quedan juntos. Escanear la columna es el pedido concreto
        // del owner ("ver de un vistazo dónde hubo diferencias").
        accessorKey: "cashStatus",
        header: "Cuadre",
        cell: ({ row }) => {
          const r = row.original
          return (
            <CashCountBadge
              status={r.cashStatus}
              difference={r.difference === null ? null : parseNum(r.difference)}
              expectedSource={r.expectedSource}
              tolerance={tolerance}
              bootstrap={bootstrap}
            />
          )
        },
        meta: { label: "Cuadre" },
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => (
          // stopPropagation: la fila entera abre el detalle (onRowClick) y sin
          // esto abrir el menú abriría también el modal de detrás.
          <div onClick={(e) => e.stopPropagation()}>
            <RowActions
              actions={[
                {
                  label: "Corregir arqueo",
                  icon: Pencil,
                  onSelect: () => setCorrectDrawer(row.original),
                },
              ]}
            />
          </div>
        ),
        meta: { className: "w-12" },
      },
    ],
    [bootstrap, tolerance],
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
        tolerance={tolerance}
        onClose={() => setSelectedDrawer(null)}
        onClosed={() => {
          // El hook ya invalida la query — solo cerramos el modal.
          setSelectedDrawer(null)
        }}
      />

      <DrawerCorrectDialog
        drawer={correctDrawer}
        onClose={() => setCorrectDrawer(null)}
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

