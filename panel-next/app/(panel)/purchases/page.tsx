"use client"

import * as React from "react"
import { Plus, Receipt } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePurchases, type PurchaseListRow } from "@/hooks/use-purchases"
import { formatMoney } from "@/lib/format"
import { PurchaseFormSheet } from "@/components/purchases/purchase-form-sheet"

/**
 * Listado de compras del panel — espejo del `panel/a_purchase.php`.
 *
 * Esta primera vuelta soporta solo compras (transactionType=1). No incluye
 * orden de compra, devolución ni reposición — agregables después como
 * variantes del mismo form.
 */
export default function PurchasesPage() {
  const { data: bootstrap } = useBootstrap()
  const [createOpen, setCreateOpen] = React.useState(false)
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
          // El prefix del backend es "authNo;prefix" — extraemos solo el prefix.
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
          // 1 = compra completa (caso default), 0 = orden pendiente.
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
          <h1 className="text-2xl font-semibold">Compras y gastos</h1>
          <p className="text-sm text-muted-foreground">
            Registro de facturas de compra a proveedores
          </p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>
          <Plus className="mr-1.5 size-4" />
          Nueva compra
        </Button>
      </header>

      <DataTable<PurchaseListRow>
        tableId="purchases"
        data={purchases.data?.rows ?? []}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={purchases.isLoading}
        emptyMessage={
          <div className="flex flex-col items-center gap-2 py-12 text-center text-sm text-muted-foreground">
            <Receipt className="size-8 opacity-50" />
            <div>Sin compras registradas en este período.</div>
            <Button size="sm" variant="outline" onClick={() => setCreateOpen(true)}>
              <Plus className="mr-1.5 size-4" />
              Registrar primera compra
            </Button>
          </div>
        }
        exportFileName="compras"
      />

      <PurchaseFormSheet
        open={createOpen}
        onOpenChange={setCreateOpen}
        defaultOutletId={bootstrap?.activeOutletId ?? ""}
      />
    </div>
  )
}

/** Formato corto de fecha "DD/MM/YYYY". El backend devuelve ISO con TZ. */
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
