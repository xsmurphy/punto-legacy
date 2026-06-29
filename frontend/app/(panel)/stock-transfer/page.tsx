"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { Plus, ArrowLeftRight } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"

import { useStockTransfers, type StockTransfer } from "@/hooks/use-stock-transfers"

const STATUS_LABEL: Record<number, string> = {
  0: "Cancelada",
  1: "Completada",
}

const STATUS_VARIANT: Record<number, "default" | "secondary" | "destructive" | "outline"> = {
  0: "secondary",
  1: "default",
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("es-PY", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
}

function outletLabel(outletName: string, locationName: string | null): string {
  return locationName ? `${outletName} → ${locationName}` : outletName
}

const columns: ColumnDef<StockTransfer>[] = [
  {
    accessorKey: "createdAt",
    header: "Fecha",
    cell: ({ row }) => formatDate(row.original.createdAt),
  },
  {
    id: "from",
    header: "Origen",
    cell: ({ row }) =>
      outletLabel(row.original.fromOutletName, row.original.fromLocationName),
  },
  {
    id: "to",
    header: "Destino",
    cell: ({ row }) =>
      outletLabel(row.original.toOutletName, row.original.toLocationName),
  },
  {
    accessorKey: "itemsCount",
    header: "Items",
  },
  {
    accessorKey: "status",
    header: "Estado",
    cell: ({ row }) => (
      <Badge variant={STATUS_VARIANT[row.original.status]}>
        {STATUS_LABEL[row.original.status] ?? "Desconocido"}
      </Badge>
    ),
  },
]

export default function StockTransferPage() {
  const router = useRouter()
  const { data, isLoading } = useStockTransfers()
  const rows = data?.rows ?? []

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Transferencias de stock</h1>
          <p className="text-sm text-muted-foreground">Mové stock entre sucursales o depósitos.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={() => router.push("/stock-transfer/new")}>
            <Plus className="mr-2 h-4 w-4" />
            Nueva transferencia
          </Button>
        </div>
      </header>

      {!isLoading && rows.length === 0 ? (
        <EmptyState
          icon={ArrowLeftRight}
          title="Sin transferencias"
          description="Mové stock entre sucursales o depósitos creando una nueva transferencia."
        />
      ) : (
        <DataTable
          tableId="stock-transfers"
          columns={columns}
          data={rows}
          isLoading={isLoading}
          onRowClick={(row) => router.push(`/stock-transfer/${row.stockTransferId}`)}
        />
      )}
    </div>
  )
}
