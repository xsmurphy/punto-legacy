"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import Link from "next/link"
import { Plus, AlertCircle, Package } from "lucide-react"
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
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useItems } from "@/hooks/use-items"
import { formatMoney } from "@/lib/format"
import {
  KIND_META,
  ALL_KINDS,
  type ItemKind,
  type ItemListItem,
} from "@/lib/types/item"

export default function ItemsPage() {
  const router = useRouter()
  const [kindFilter, setKindFilter] = React.useState<"all" | ItemKind>("all")
  const [showArchived, setShowArchived] = React.useState(false)
  const { data, isLoading, error } = useItems({ archived: showArchived })
  const { data: bootstrap } = useBootstrap()

  const filteredRows = React.useMemo(() => {
    const rows = data?.items ?? []
    if (kindFilter === "all") return rows
    return rows.filter((r) => r.kind === kindFilter)
  }, [data, kindFilter])

  const columns = React.useMemo<ColumnDef<ItemListItem>[]>(
    () => [
      {
        accessorKey: "itemName",
        header: "Nombre",
        cell: ({ row }) => (
          <Link
            href={`/items/${row.original.itemId}`}
            className="font-medium hover:underline"
            onClick={(e) => e.stopPropagation()}
          >
            {row.original.itemName || "(sin nombre)"}
          </Link>
        ),
      },
      {
        accessorKey: "itemSKU",
        header: "SKU",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? (
            <span className="tabular-nums text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "SKU", className: "tabular-nums" },
      },
      {
        accessorKey: "kind",
        header: "Tipo",
        cell: ({ getValue }) => {
          const k = getValue() as ItemKind
          const meta = KIND_META[k]
          return (
            <Badge variant="outline" className="text-[10px]">
              {meta?.label ?? k}
            </Badge>
          )
        },
        meta: { label: "Tipo" },
      },
      {
        accessorKey: "itemPrice",
        header: "Precio",
        cell: ({ getValue }) => {
          const v = getValue() as number | string | null
          const n = typeof v === "string" ? parseFloat(v) : v
          return (
            <span className="tabular-nums">
              {formatMoney(n ?? 0, bootstrap)}
            </span>
          )
        },
        meta: { label: "Precio", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "itemStatus",
        header: "Estado",
        cell: ({ getValue }) => {
          const s = getValue() as number
          return (
            <Badge
              variant={s === 1 ? "default" : "secondary"}
              className={s === 1 ? "" : "bg-muted text-muted-foreground"}
            >
              {s === 1 ? "Activo" : "Archivado"}
            </Badge>
          )
        },
        meta: { className: "w-24" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex items-end justify-between gap-4">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Artículos</h1>
          <p className="text-sm text-muted-foreground">
            Productos, servicios y otros ítems del catálogo. Cada ítem puede
            tener precio, costo, stock, categoría y marca asignados.
          </p>
        </div>
        <Button asChild>
          <Link href="/items/new">
            <Plus className="size-4" />
            Nuevo artículo
          </Link>
        </Button>
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudieron cargar los artículos</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      <Card className="overflow-hidden">
        <CardContent className="p-4">
          <DataTable
            tableId="items"
            data={filteredRows}
            columns={columns}
            getRowId={(r) => r.itemId}
            onRowClick={(r) => router.push(`/items/${r.itemId}`)}
            isLoading={isLoading}
            searchPlaceholder="Buscar por nombre o SKU…"
            exportFileName="articulos"
            emptyMessage={
              <div className="flex flex-col items-center gap-2 text-muted-foreground">
                <Package className="size-8 opacity-30" />
                <p>No hay artículos todavía.</p>
                <p className="text-xs">
                  Creá el primero con el botón <strong>Nuevo artículo</strong>{" "}
                  arriba a la derecha.
                </p>
              </div>
            }
            toolbarSlot={
              <>
                <Select
                  value={kindFilter}
                  onValueChange={(v) => setKindFilter(v as typeof kindFilter)}
                >
                  <SelectTrigger className="h-9 w-[170px]">
                    <SelectValue placeholder="Tipo" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">Todos los tipos</SelectItem>
                    {ALL_KINDS.map((k) => (
                      <SelectItem key={k} value={k}>
                        {KIND_META[k].label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Select
                  value={showArchived ? "archived" : "active"}
                  onValueChange={(v) => setShowArchived(v === "archived")}
                >
                  <SelectTrigger className="h-9 w-[130px]">
                    <SelectValue placeholder="Estado" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="active">Activos</SelectItem>
                    <SelectItem value="archived">Archivados</SelectItem>
                  </SelectContent>
                </Select>
              </>
            }
          />
        </CardContent>
      </Card>
    </div>
  )
}
