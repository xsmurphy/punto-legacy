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
import { useBootstrap } from "@/hooks/use-bootstrap"
import { resolveDateLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"

const STATUS_LABEL: Record<number, string> = {
  0: "Cancelada",
  1: "Completada",
}

const STATUS_VARIANT: Record<number, "default" | "secondary" | "destructive" | "outline"> = {
  0: "secondary",
  1: "default",
}

function formatDate(iso: string, config: TenantLocaleConfig | null | undefined): string {
  return new Date(iso).toLocaleString(resolveDateLocale(config), {
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

// Las columnas se arman con la config del tenant (la fecha se formatea con su
// locale), así que son función del bootstrap y no una constante de módulo.
const buildColumns = (
  config: TenantLocaleConfig | null | undefined,
): ColumnDef<StockTransfer>[] => [
  {
    id: "docNumber",
    header: "Nº",
    // Correlativo por sucursal (mig 129). Los registros anteriores a la
    // migración pueden no tener número.
    cell: ({ row }) => (
      <span className="tabular-nums text-sm text-muted-foreground">
        {row.original.docNumber ?? "—"}
      </span>
    ),
    meta: { label: "Nº de transferencia" },
  },
  {
    accessorKey: "createdAt",
    header: "Fecha",
    cell: ({ row }) => formatDate(row.original.createdAt, config),
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
  const { data: bootstrap } = useBootstrap()
  const rows = data?.rows ?? []

  const columns = React.useMemo(() => buildColumns(bootstrap), [bootstrap])

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
