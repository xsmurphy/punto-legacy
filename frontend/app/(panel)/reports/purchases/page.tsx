"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { Plus, Receipt, ArrowLeft } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type PurchaseReportRow,
  type PurchasesReportResponse,
} from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { EmptyState } from "@/components/empty-state"

/**
 * Reporte de compras y gastos — espejo del listado de `panel/a_purchase.php`,
 * pero conceptualmente vive como REPORTE (no como sección de primer nivel).
 * El acceso al form de crear compra se hace via el item "Compras y Gastos"
 * del menú user del sidebar (→ `/purchase`), no desde acá. El botón "Nueva"
 * acá es un atajo a la misma URL.
 *
 * Consume el endpoint canónico de reporte (`/v1/reports/purchases?view=general`,
 * `Reports/PurchasesService::general`) — no el CRUD `/v1/purchases` (ese sigue
 * siendo el correcto para el form de `/purchase` y su detalle). El de reporte
 * ya trae `authNo`/`prefix`/`userName` resueltos y cubre compras contado
 * (transactionType=1) y crédito (transactionType=4).
 *
 * Filtros activos: rango de fechas (DateRangePicker, server-side).
 * Click en fila → `/purchase/[id]` para ver el detalle completo (mismo id:
 * `transactionId` — el CRUD busca por transactionId, ver PurchasesService::find).
 */
export default function PurchasesReportPage() {
  const router = useRouter()
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const purchases = useReport<PurchasesReportResponse>("purchases", {
    from: opts.from,
    to: opts.to,
    params: { view: "general" },
  })

  const columns = React.useMemo<ColumnDef<PurchaseReportRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        meta: { label: "Fecha" },
        cell: ({ row }) => formatDate(row.original.date),
      },
      {
        accessorKey: "supplierName",
        header: "Proveedor",
        meta: { label: "Proveedor" },
        cell: ({ row }) =>
          row.original.supplierName || (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        id: "document",
        header: "Documento",
        meta: { label: "Documento" },
        cell: ({ row }) => {
          const { prefix, invoiceNo } = row.original
          if (!invoiceNo) return <span className="text-muted-foreground">—</span>
          const num = String(invoiceNo).padStart(7, "0")
          return (
            <span className="font-mono text-xs">
              {prefix ? `${prefix}-${num}` : num}
            </span>
          )
        },
      },
      {
        accessorKey: "authNo",
        header: "Timbrado",
        meta: { label: "Timbrado", className: "text-muted-foreground" },
        cell: ({ row }) => (
          <span className="text-muted-foreground text-xs">
            {row.original.authNo || "—"}
          </span>
        ),
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        meta: { label: "Sucursal" },
        cell: ({ row }) =>
          row.original.outletName || (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        meta: { label: "Usuario" },
        cell: ({ row }) =>
          row.original.userName || (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        accessorKey: "dueDate",
        header: "Vencimiento",
        meta: { label: "Vencimiento" },
        cell: ({ row }) =>
          row.original.dueDate ? (
            formatDate(row.original.dueDate)
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        id: "condition",
        header: "Condición",
        meta: { label: "Condición" },
        cell: ({ row }) => {
          const t = row.original.transactionType
          if (t === 1) return <Badge variant="secondary">Contado</Badge>
          if (t === 4) return <Badge variant="outline">Crédito</Badge>
          return <Badge variant="outline">{t}</Badge>
        },
      },
      {
        accessorKey: "total",
        header: () => <div className="text-right">Total</div>,
        meta: {
          label: "Total",
          className: "text-right",
          footerSum: true,
          footerFormat: (sum) => (
            <div className="text-right font-medium tabular-nums">
              {formatMoney(sum, bootstrap)}
            </div>
          ),
        },
        cell: ({ row }) => (
          <div className="text-right font-medium tabular-nums">
            {formatMoney(row.original.total, bootstrap)}
          </div>
        ),
      },
      {
        accessorKey: "transactionStatus",
        header: "Estado",
        meta: { label: "Estado" },
        cell: ({ row }) => {
          const s = row.original.transactionStatus
          if (s === "1") return <Badge variant="secondary">Completa</Badge>
          if (s === "6") return <Badge variant="destructive">Anulada</Badge>
          return <Badge variant="outline">{s}</Badge>
        },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-4">
      <header className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Compras y gastos</h1>
          <p className="text-sm text-muted-foreground">
            Historial de facturas de compra a proveedores
          </p>
        </div>
        <Button asChild>
          <Link href="/purchase">
            <Plus className="mr-1.5 size-4" />
            Nueva compra
          </Link>
        </Button>
      </header>

      <DataTable<PurchaseReportRow>
        tableId="purchases-report"
        data={purchases.data?.rows ?? []}
        columns={columns}
        getRowId={(r) => r.transactionId}
        isLoading={purchases.isLoading}
        onRowClick={(r) => router.push(`/purchase/${r.transactionId}`)}
        toolbarSlot={
          <DateRangePicker value={range} onChange={setRange} />
        }
        emptyMessage={
          <EmptyState
            icon={Receipt}
            title="Sin compras registradas en este período"
            description="Ajustá el rango de fechas o registrá una compra nueva."
            actions={
              <Button asChild size="sm" variant="outline">
                <Link href="/purchase">
                  <Plus className="mr-1.5 size-4" />
                  Registrar primera compra
                </Link>
              </Button>
            }
          />
        }
        exportFileName="compras"
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

function formatDate(s: string): string {
  try {
    const d = new Date(s)
    const dd = String(d.getDate()).padStart(2, "0")
    const mm = String(d.getMonth() + 1).padStart(2, "0")
    return `${dd}/${mm}/${d.getFullYear()}`
  } catch {
    return s
  }
}
