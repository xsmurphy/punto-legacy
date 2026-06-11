"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import Link from "next/link"
import { toast } from "sonner"
import { Plus, AlertCircle, Package, Archive } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
import { DataTable } from "@/components/data-table/data-table"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useArchiveItem, useItems } from "@/hooks/use-items"
import { ImportItemsDialog } from "@/components/items/import-dialog"
import { NewItemKindDialog } from "@/components/items/new-item-kind-dialog"
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
  const archive = useArchiveItem()

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
          <div className="flex items-center gap-2.5">
            <div className="relative size-8 shrink-0 overflow-hidden rounded border bg-muted">
              {row.original.coverImageUrl ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={row.original.coverImageUrl}
                  alt=""
                  className="size-full object-cover"
                  loading="lazy"
                />
              ) : (
                <Package className="absolute inset-0 m-auto size-4 opacity-30" />
              )}
            </div>
            <Link
              href={`/items/${row.original.itemId}`}
              className="font-medium hover:underline"
              onClick={(e) => e.stopPropagation()}
            >
              {row.original.itemName || "(sin nombre)"}
            </Link>
          </div>
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
        accessorKey: "categoryName",
        header: "Categoría",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? v : <span className="opacity-40">—</span>
        },
        meta: { label: "Categoría" },
      },
      {
        accessorKey: "brandName",
        header: "Marca",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? v : <span className="opacity-40">—</span>
        },
        meta: { label: "Marca" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? v : <span className="opacity-40 text-xs">Todas</span>
        },
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "itemUOM",
        header: "UOM",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? (
            <Badge variant="secondary" className="text-[10px] font-normal">
              {v}
            </Badge>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Unidad de medida" },
      },
      {
        accessorKey: "itemCost",
        header: "Costo",
        cell: ({ getValue }) => {
          const v = getValue() as number | string | null
          const n = typeof v === "string" ? parseFloat(v) : v
          if (!n || n <= 0) return <span className="opacity-40">—</span>
          return (
            <span className="tabular-nums text-muted-foreground">
              {formatMoney(n, bootstrap)}
            </span>
          )
        },
        meta: { label: "Costo", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "itemPrice",
        header: "Precio",
        cell: ({ row, getValue }) => {
          const v = getValue() as number | string | null
          const n = typeof v === "string" ? parseFloat(v) : v
          const discount = Number(row.original.itemDiscount ?? 0)
          if (!n) return <span className="opacity-40">—</span>
          if (discount > 0) {
            const final = n * (1 - discount / 100)
            return (
              <span className="tabular-nums">
                <span className="block text-[10px] text-destructive line-through">
                  {formatMoney(n, bootstrap)}
                </span>
                {formatMoney(final, bootstrap)}
              </span>
            )
          }
          return (
            <span className="tabular-nums">{formatMoney(n, bootstrap)}</span>
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
        meta: { label: "Estado", className: "w-24" },
      },
    ],
    [bootstrap],
  )

  // Visibilidad por defecto: marca/sucursal/UOM/costo arrancan ocultas (el usuario
  // las activa desde el menú "Columnas"). En TanStack: false = oculta. Persistido por DataTable.
  const initialColumnVisibility = React.useMemo(
    () => ({
      brandName: false,
      outletName: false,
      itemUOM: false,
      itemCost: false,
    }),
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex items-end justify-between gap-4">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Artículos</h1>
          <p className="text-sm text-muted-foreground">
            Catálogo de productos, servicios e insumos.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <ImportItemsDialog />
          <NewItemKindDialog />
        </div>
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
            initialColumnVisibility={initialColumnVisibility}
            enableSelection
            bulkActions={(selected, clear) => (
              <BulkArchiveDialog
                items={selected}
                isArchived={showArchived}
                onConfirm={async () => {
                  try {
                    await Promise.all(
                      selected.map((i) => archive.mutateAsync(i.itemId)),
                    )
                    toast.success(
                      `${selected.length} ${selected.length === 1 ? "artículo archivado" : "artículos archivados"}`,
                    )
                    clear()
                  } catch (e) {
                    toast.error("No se pudo archivar", {
                      description: e instanceof Error ? e.message : undefined,
                    })
                  }
                }}
              />
            )}
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

function BulkArchiveDialog({
  items,
  isArchived,
  onConfirm,
}: {
  items: ItemListItem[]
  isArchived: boolean
  onConfirm: () => Promise<void>
}) {
  const [open, setOpen] = React.useState(false)
  // Solo permitimos bulk archive sobre activos (no hay "desarchivar" todavía).
  if (isArchived) return null
  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      <AlertDialogTrigger asChild>
        <Button variant="outline" size="sm" className="h-7 gap-1.5 text-xs">
          <Archive className="size-3.5" />
          Archivar
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            ¿Archivar {items.length} {items.length === 1 ? "artículo" : "artículos"}?
          </AlertDialogTitle>
          <AlertDialogDescription>
            Los artículos archivados dejan de aparecer en la caja. Podés
            verlos cambiando el filtro a &quot;Archivados&quot;.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            onClick={async (e) => {
              e.preventDefault()
              await onConfirm()
              setOpen(false)
            }}
          >
            Archivar
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
