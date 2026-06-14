"use client"

import * as React from "react"
import Link from "next/link"
import { Plus, Receipt, ArrowLeft } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePurchases, type PurchaseListRow } from "@/hooks/use-purchases"
import { formatMoney } from "@/lib/format"
import { EmptyState } from "@/components/empty-state"

/**
 * Reporte de compras y gastos — espejo del listado de `panel/a_purchase.php`,
 * pero conceptualmente vive como REPORTE (no como sección de primer nivel).
 * El acceso al form de crear compra se hace via el item "Compras y Gastos"
 * del menú user del sidebar (→ `/purchase`), no desde acá. El botón "Nueva"
 * acá es un atajo a la misma URL.
 *
 * Esta primera vuelta soporta solo compras (transactionType=1). Orden,
 * devolución y reposición — variantes del mismo form — vienen después.
 */
export default function PurchasesReportPage() {
  const { data: bootstrap } = useBootstrap()
  const purchases = usePurchases({ limit: 50 })

  const columns = React.useMemo<ColumnDef<PurchaseListRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ row }) => formatDate(row.original.date),
      },
      {
        accessorKey: "supplierName",
        header: "Proveedor",
        cell: ({ row }) =>
          row.original.supplierName ?? (
            <span className="text-muted-foreground italic">Sin proveedor</span>
          ),
      },
      {
        accessorKey: "invoiceNo",
        header: "Factura",
        cell: ({ row }) => {
          const { invoiceNo, invoicePrefix } = row.original
          if (invoiceNo === null) return <span className="text-muted-foreground">—</span>
          const prefix = invoicePrefix ? invoicePrefix.split(";").pop() : ""
          const num = String(invoiceNo).padStart(7, "0")
          return (
            <span className="font-mono text-xs">
              {prefix ? `${prefix}-${num}` : num}
            </span>
          )
        },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ row }) =>
          row.original.outletName ?? (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        accessorKey: "dueDate",
        header: "Vencimiento",
        cell: ({ row }) =>
          row.original.dueDate ? (
            formatDate(row.original.dueDate)
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        accessorKey: "total",
        header: () => <div className="text-right">Total</div>,
        cell: ({ row }) => (
          <div className="text-right font-medium tabular-nums">
            {formatMoney(row.original.total, bootstrap)}
          </div>
        ),
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ row }) => {
          const s = row.original.status
          if (s === 1) return <Badge variant="secondary">Completa</Badge>
          if (s === 0) return <Badge variant="outline">Orden</Badge>
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

      <DataTable<PurchaseListRow>
        tableId="purchases-report"
        data={purchases.data?.rows ?? []}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={purchases.isLoading}
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
