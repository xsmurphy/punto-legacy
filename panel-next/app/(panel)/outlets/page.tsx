"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import Link from "next/link"
import { Plus, AlertCircle, MapPin } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
import { DataTable } from "@/components/data-table/data-table"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useOutlets } from "@/hooks/use-outlets"
import type { OutletListItem } from "@/lib/types/outlet"

export default function OutletsPage() {
  const router = useRouter()
  const { data, isLoading, error } = useOutlets()
  const [statusFilter, setStatusFilter] = React.useState<"all" | "active" | "inactive">("all")

  // Filtrado custom por estado (lo aplicamos antes de pasar a DataTable).
  // El search global del DataTable cubre nombre/dirección/teléfono/ruc.
  const filteredRows = React.useMemo(() => {
    const rows = data?.rows ?? []
    if (statusFilter === "active") return rows.filter((r) => r.status === 1)
    if (statusFilter === "inactive") return rows.filter((r) => r.status !== 1)
    return rows
  }, [data, statusFilter])

  const columns = React.useMemo<ColumnDef<OutletListItem>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            <Link
              href={`/outlets/${row.original.id}`}
              className="font-medium hover:underline"
              onClick={(e) => e.stopPropagation()}
            >
              {row.original.name || "(sin nombre)"}
            </Link>
            {row.original.ecom && (
              <Badge variant="outline" className="text-[10px]">E-com</Badge>
            )}
          </div>
        ),
      },
      {
        accessorKey: "address",
        header: "Dirección",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? (
            <span className="text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Dirección" },
      },
      {
        accessorKey: "phone",
        header: "Teléfono",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? (
            <span className="text-muted-foreground tabular-nums">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Teléfono" },
      },
      {
        accessorKey: "ruc",
        header: "RUC",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? (
            <span className="text-muted-foreground tabular-nums">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "RUC", className: "tabular-nums" },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ getValue }) => {
          const s = getValue() as number
          return (
            <Badge
              variant={s === 1 ? "default" : "secondary"}
              className={s === 1 ? "" : "bg-muted text-muted-foreground"}
            >
              {s === 1 ? "Activa" : "Inactiva"}
            </Badge>
          )
        },
        meta: { className: "w-24" },
      },
    ],
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex items-end justify-between gap-4">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Sucursales</h1>
          <p className="text-sm text-muted-foreground">
            Puntos de venta con su propia caja e inventario.
          </p>
        </div>
        <Button asChild>
          <Link href="/outlets/new">
            <Plus className="size-4" />
            Nueva sucursal
          </Link>
        </Button>
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudieron cargar las sucursales</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      <Card className="overflow-hidden">
        <CardContent className="p-4">
          <DataTable
            tableId="outlets"
            data={filteredRows}
            columns={columns}
            getRowId={(r) => r.id}
            onRowClick={(r) => router.push(`/outlets/${r.id}`)}
            isLoading={isLoading}
            searchPlaceholder="Buscar por nombre, dirección, RUC…"
            exportFileName="sucursales"
            emptyMessage={
              <div className="flex flex-col items-center gap-2 text-muted-foreground">
                <MapPin className="size-8 opacity-30" />
                <p>No hay sucursales todavía.</p>
                <p className="text-xs">
                  Creá la primera con el botón <strong>Nueva sucursal</strong>{" "}
                  arriba a la derecha.
                </p>
              </div>
            }
            toolbarSlot={
              <Select
                value={statusFilter}
                onValueChange={(v) => setStatusFilter(v as typeof statusFilter)}
              >
                <SelectTrigger className="h-9 w-[140px]">
                  <SelectValue placeholder="Estado" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todas</SelectItem>
                  <SelectItem value="active">Activas</SelectItem>
                  <SelectItem value="inactive">Inactivas</SelectItem>
                </SelectContent>
              </Select>
            }
          />
        </CardContent>
      </Card>
    </div>
  )
}
