"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { Plus, Truck } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"

import { useRemisiones, REMISION_MOTIVO_LABELS, type Remision } from "@/hooks/use-remisiones"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { resolveDateLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"

const STATUS_LABEL: Record<number, string> = {
  0: "Cancelada",
  1: "Activa",
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

function destinationLabel(r: Remision): string {
  const parts = [r.destinationContactName, r.destinationNote].filter((s): s is string => !!s && s.trim() !== "")
  return parts.length > 0 ? parts.join(" — ") : "—"
}

// Las columnas se arman con la config del tenant (la fecha se formatea con su
// locale), así que son función del bootstrap y no una constante de módulo.
const buildColumns = (
  config: TenantLocaleConfig | null | undefined,
): ColumnDef<Remision>[] => [
  {
    id: "docNumber",
    header: "Nº",
    cell: ({ row }) => (
      <span className="tabular-nums text-sm text-muted-foreground">
        {row.original.docNumber ?? "—"}
      </span>
    ),
    meta: { label: "Nº de remisión" },
  },
  {
    accessorKey: "transferDate",
    header: "Fecha",
    cell: ({ row }) => formatDate(row.original.transferDate, config),
  },
  {
    id: "motivo",
    header: "Motivo",
    cell: ({ row }) => REMISION_MOTIVO_LABELS[row.original.motivo] ?? row.original.motivo,
  },
  {
    id: "origin",
    header: "Origen",
    cell: ({ row }) => outletLabel(row.original.outletName, row.original.locationName),
  },
  {
    id: "destination",
    header: "Destino",
    cell: ({ row }) => destinationLabel(row.original),
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

export default function RemisionesPage() {
  const router = useRouter()
  const { data, isLoading } = useRemisiones()
  const { data: bootstrap } = useBootstrap()
  const rows = data?.rows ?? []

  const columns = React.useMemo(() => buildColumns(bootstrap), [bootstrap])

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Remisiones</h1>
          <p className="text-sm text-muted-foreground">
            Documento de traslado de mercadería — venta, devolución a proveedor, consignación,
            exposición o compra. El traslado entre sucursales/depósitos propios vive en Transferencias.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={() => router.push("/remisiones/new")}>
            <Plus className="mr-2 h-4 w-4" />
            Nueva remisión
          </Button>
        </div>
      </header>

      {!isLoading && rows.length === 0 ? (
        <EmptyState
          icon={Truck}
          title="Sin remisiones"
          description="Documentá un traslado de mercadería creando una nueva remisión."
        />
      ) : (
        <DataTable
          tableId="remisiones"
          columns={columns}
          data={rows}
          isLoading={isLoading}
          onRowClick={(row) => router.push(`/remisiones/${row.remisionId}`)}
        />
      )}
    </div>
  )
}
