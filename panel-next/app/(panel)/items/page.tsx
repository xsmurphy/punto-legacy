"use client"

import * as React from "react"
import { useRouter, useSearchParams } from "next/navigation"
import Link from "next/link"
import { toast } from "sonner"
import {
  Plus,
  AlertCircle,
  Package,
  Archive,
  FolderPlus,
  FolderOpen,
  ChevronLeft,
  Loader2,
  FolderMinus,
  Pencil,
  Barcode,
  Trash2,
  Layers,
} from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"
import { EmptyState } from "@/components/empty-state"

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
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useArchiveItem,
  useDeleteItem,
  useGroupItems,
  useItem,
  useItems,
  useRenameItem,
  useUngroupItems,
} from "@/hooks/use-items"
import { BulkEditDialog } from "@/components/items/bulk-edit-dialog"
import { ImportItemsDialog } from "@/components/items/import-dialog"
import { NewItemKindDialog } from "@/components/items/new-item-kind-dialog"
import { formatMoney } from "@/lib/format"
import {
  KIND_META,
  ALL_KINDS,
  type ItemKind,
  type ItemListItem,
} from "@/lib/types/item"
import { useAgentPageSnapshot } from "@/lib/agent/use-agent-page-snapshot"

export default function ItemsPage() {
  // useSearchParams() requiere Suspense boundary durante prerender (Next 15+
  // App Router). Sin esto el build estático falla con CSR bailout.
  return (
    <React.Suspense fallback={null}>
      <ItemsPageInner />
    </React.Suspense>
  )
}

function ItemsPageInner() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const parentId = searchParams.get("parent")
  const [kindFilter, setKindFilter] = React.useState<"all" | ItemKind>("all")
  const [showArchived, setShowArchived] = React.useState(false)
  const [showVariants, setShowVariants] = React.useState(false)
  const { data, isLoading, error } = useItems({
    archived: showArchived,
    parentId: parentId ?? undefined,
    includeVariants: showVariants,
  })
  const { data: bootstrap } = useBootstrap()
  const archive = useArchiveItem()
  const hardDelete = useDeleteItem()
  const group = useGroupItems()
  const ungroup = useUngroupItems()
  const rename = useRenameItem()
  // Cuando viewing un grupo, traemos el detalle del item-grupo para mostrar
  // y editar su nombre. Si no estamos en grupo, no hace nada (enabled:false).
  const groupItem = useItem(parentId ?? undefined)
  const [renameDialogOpen, setRenameDialogOpen] = React.useState(false)
  const [barcodeDialogItems, setBarcodeDialogItems] = React.useState<ItemListItem[] | null>(null)
  const [groupDialogItems, setGroupDialogItems] = React.useState<ItemListItem[] | null>(null)
  const [bulkEditItems, setBulkEditItems] = React.useState<ItemListItem[] | null>(null)
  const [pendingClearSelection, setPendingClearSelection] = React.useState<(() => void) | null>(null)
  const isViewingGroup = !!parentId

  const filteredRows = React.useMemo(() => {
    const rows = data?.items ?? []
    if (kindFilter === "all") return rows
    return rows.filter((r) => r.kind === kindFilter)
  }, [data, kindFilter])

  useAgentPageSnapshot(
    {
      route: "/items",
      routeLabel: "Listado de artículos",
      summary: {
        carpeta: parentId ?? null,
        tipoFiltro: kindFilter !== "all" ? kindFilter : null,
        archivedVisible: showArchived,
        filasVisibles: filteredRows.length,
      },
    },
    [parentId, kindFilter, showArchived, filteredRows.length],
  )

  const columns = React.useMemo<ColumnDef<ItemListItem>[]>(
    () => [
      {
        accessorKey: "itemName",
        header: "Nombre",
        cell: ({ row }) => {
          const isGroup = !!row.original.itemIsParent
          const childCount = row.original.childCount ?? 0
          return (
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
                ) : isGroup ? (
                  <FolderOpen className="absolute inset-0 m-auto size-4 text-amber-500" />
                ) : (
                  <Package className="absolute inset-0 m-auto size-4 opacity-30" />
                )}
              </div>
              {isGroup ? (
                <button
                  type="button"
                  className="flex items-center gap-1.5 text-left font-medium hover:underline"
                  onClick={(e) => {
                    e.stopPropagation()
                    router.push(`/items?parent=${row.original.itemId}`)
                  }}
                >
                  {row.original.itemName || "(sin nombre)"}
                  <Badge variant="secondary" className="text-[10px]">
                    {childCount} {childCount === 1 ? "item" : "items"}
                  </Badge>
                </button>
              ) : (
                <Link
                  href={`/items/${row.original.itemId}`}
                  className="font-medium hover:underline flex items-center gap-1.5"
                  onClick={(e) => e.stopPropagation()}
                >
                  {row.original.itemName || "(sin nombre)"}
                  {row.original.hasVariants && (
                    <Badge variant="secondary" className="text-[10px]">
                      Variantes ({row.original.variantCount ?? 0})
                    </Badge>
                  )}
                  {row.original.variantParentId && (
                    <Badge variant="outline" className="text-[10px] text-muted-foreground">
                      variante
                    </Badge>
                  )}
                </Link>
              )}
            </div>
          )
        },
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
      {
        accessorKey: "itemDate",
        header: "Fecha",
        enableSorting: true,
        cell: ({ row }) => {
          const v = row.original.itemDate
          return v ? new Intl.DateTimeFormat("es-PY", { day: "2-digit", month: "2-digit", year: "numeric" }).format(new Date(v)) : <span className="opacity-40">—</span>
        },
        meta: { label: "Fecha", className: "tabular-nums whitespace-nowrap" },
      },
    ],
    [bootstrap, router],
  )

  // Visibilidad por defecto: marca/sucursal/UOM/costo arrancan ocultas (el usuario
  // las activa desde el menú "Columnas"). En TanStack: false = oculta. Persistido por DataTable.
  const initialColumnVisibility = React.useMemo(
    () => ({
      brandName: false,
      outletName: false,
      itemUOM: false,
      itemCost: false,
      itemDate: false,
    }),
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          {isViewingGroup ? (
            <>
              <button
                type="button"
                onClick={() => router.push("/items")}
                className="flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
              >
                <ChevronLeft className="size-3.5" />
                Volver a todos los artículos
              </button>
              <h1 className="text-2xl font-semibold">
                {groupItem.data?.itemName || "Items del grupo"}
              </h1>
              <p className="text-sm text-muted-foreground">
                Mostrando los artículos pertenecientes a este grupo.
              </p>
            </>
          ) : (
            <>
              <h1 className="text-2xl font-semibold">Artículos</h1>
              <p className="text-sm text-muted-foreground">
                Catálogo de productos, servicios e insumos.
              </p>
            </>
          )}
        </div>
        <div className="flex items-center gap-2">
          {isViewingGroup ? (
            <>
              <Button
                variant="outline"
                className="gap-1.5"
                onClick={() => setRenameDialogOpen(true)}
              >
                <Pencil className="size-4" />
                Renombrar
              </Button>
              <RenameGroupDialog
                open={renameDialogOpen}
                onOpenChange={setRenameDialogOpen}
                currentName={groupItem.data?.itemName || ""}
                loading={rename.isPending}
                onSubmit={async (newName) => {
                  if (!parentId) return
                  try {
                    await rename.mutateAsync({ itemId: parentId, name: newName })
                    toast.success("Grupo renombrado")
                    setRenameDialogOpen(false)
                  } catch (e) {
                    toast.error("No se pudo renombrar", {
                      description: e instanceof Error ? e.message : undefined,
                    })
                  }
                }}
              />
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="outline" className="gap-1.5">
                  <FolderMinus className="size-4" />
                  Desagrupar
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>¿Desagrupar este grupo?</AlertDialogTitle>
                  <AlertDialogDescription>
                    Los artículos volverán al listado principal. El grupo se
                    archiva — los artículos no se pierden.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancelar</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={async () => {
                      if (!parentId) return
                      try {
                        const r = await ungroup.mutateAsync(parentId)
                        toast.success(
                          `${r.ungrouped} ${r.ungrouped === 1 ? "artículo desagrupado" : "artículos desagrupados"}`,
                        )
                        router.push("/items")
                      } catch (e) {
                        toast.error("No se pudo desagrupar", {
                          description: e instanceof Error ? e.message : undefined,
                        })
                      }
                    }}
                  >
                    Desagrupar
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
            </>
          ) : (
            <>
              <ImportItemsDialog />
              <NewItemKindDialog />
            </>
          )}
        </div>
      </header>

      {/* Dialog: generar códigos de barra de la selección */}
      <BarcodeDialog
        open={!!barcodeDialogItems}
        items={barcodeDialogItems ?? []}
        onClose={() => setBarcodeDialogItems(null)}
        onConfirm={(qty) => {
          if (!barcodeDialogItems) return
          const ids = barcodeDialogItems
            .map((i) => `${i.itemId}-${qty}`)
            .join("|")
          window.open(`/items/barcodes?ids=${encodeURIComponent(ids)}`, "_blank")
          setBarcodeDialogItems(null)
        }}
      />

      {/* Dialog: edición masiva sobre la selección */}
      <BulkEditDialog
        open={!!bulkEditItems}
        items={bulkEditItems ?? []}
        onClose={() => setBulkEditItems(null)}
        onSuccess={() => {
          pendingClearSelection?.()
          setPendingClearSelection(null)
        }}
      />

      {/* Dialog: crear grupo a partir de la selección */}
      <CreateGroupDialog
        open={!!groupDialogItems}
        items={groupDialogItems ?? []}
        loading={group.isPending}
        onClose={() => setGroupDialogItems(null)}
        onConfirm={async (name) => {
          if (!groupDialogItems) return
          try {
            const r = await group.mutateAsync({
              itemIds: groupDialogItems.map((i) => i.itemId),
              groupName: name,
            })
            toast.success(
              `Grupo "${name}" creado con ${r.childCount} ${r.childCount === 1 ? "item" : "items"}`,
            )
            pendingClearSelection?.()
            setPendingClearSelection(null)
            setGroupDialogItems(null)
          } catch (e) {
            toast.error("No se pudo crear el grupo", {
              description: e instanceof Error ? e.message : undefined,
            })
          }
        }}
      />

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudieron cargar los artículos</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      <DataTable
            tableId="items"
            data={filteredRows}
            columns={columns}
            getRowId={(r) => r.itemId}
            onRowClick={(r) => {
              if (r.itemIsParent) {
                router.push(`/items?parent=${r.itemId}`)
              } else {
                router.push(`/items/${r.itemId}`)
              }
            }}
            isLoading={isLoading}
            searchPlaceholder="Buscar por nombre o SKU…"
            exportFileName="articulos"
            initialColumnVisibility={initialColumnVisibility}
            enableSelection
            bulkActions={(selected, clear) => (
              <>
                {/* Edición masiva: aplica un patch común a todos los seleccionados */}
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7 gap-1.5 text-xs"
                  onClick={() => {
                    setBulkEditItems(selected)
                    setPendingClearSelection(() => clear)
                  }}
                >
                  <Pencil className="size-3.5" />
                  Editar
                </Button>
                {/* Códigos de barra: para ítems no-grupo */}
                {selected.filter((i) => !i.itemIsParent).length > 0 && (
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 gap-1.5 text-xs"
                    onClick={() =>
                      setBarcodeDialogItems(selected.filter((i) => !i.itemIsParent))
                    }
                  >
                    <Barcode className="size-3.5" />
                    Códigos
                  </Button>
                )}
                {/* Agrupar solo cuando estamos en top-level (no dentro de un grupo)
                    y hay al menos 2 items NO-grupo seleccionados. */}
                {!isViewingGroup && selected.filter((i) => !i.itemIsParent).length >= 2 && (
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 gap-1.5 text-xs"
                    onClick={() => {
                      setGroupDialogItems(selected.filter((i) => !i.itemIsParent))
                      setPendingClearSelection(() => clear)
                    }}
                  >
                    <FolderPlus className="size-3.5" />
                    Agrupar
                  </Button>
                )}
                {/* Activos → Archivar. Archivados → Eliminar definitivamente. */}
                {showArchived ? (
                  <BulkDeleteDialog
                    items={selected}
                    onConfirm={async () => {
                      let archived = 0
                      for (const i of selected) {
                        try {
                          await hardDelete.mutateAsync(i.itemId)
                        } catch (e) {
                          // 409 = referenciado (ventas / stock / inventario / compuestos).
                          // Ambos mensajes del backend incluyen "Permanecerá archivado".
                          const msg = e instanceof Error ? e.message : "Error"
                          if (msg.includes("Permanecerá archivado")) {
                            archived++
                            toast.warning(`"${i.itemName}" no se pudo eliminar`, {
                              description: msg,
                            })
                          } else {
                            toast.error(`No se pudo eliminar "${i.itemName}"`, {
                              description: msg,
                            })
                          }
                        }
                      }
                      const deleted = selected.length - archived
                      if (deleted > 0) {
                        toast.success(
                          `${deleted} ${deleted === 1 ? "artículo eliminado" : "artículos eliminados"} definitivamente`,
                        )
                      }
                      clear()
                    }}
                  />
                ) : (
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
              </>
            )}
            emptyMessage={
              <EmptyState
                icon={Package}
                title="Sin artículos todavía"
                description={
                  <>
                    Creá el primero con el botón <strong>Nuevo artículo</strong>{" "}
                    arriba a la derecha.
                  </>
                }
              />
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
                <Button
                  variant={showVariants ? "default" : "outline"}
                  size="sm"
                  className="h-9 gap-1.5"
                  onClick={() => setShowVariants((v) => !v)}
                >
                  <Layers className="size-3.5" />
                  {showVariants ? "Ocultar variantes" : "Ver variantes"}
                </Button>
              </>
            }
          />
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

function BulkDeleteDialog({
  items,
  onConfirm,
}: {
  items: ItemListItem[]
  onConfirm: () => Promise<void>
}) {
  const [open, setOpen] = React.useState(false)
  const [pending, setPending] = React.useState(false)
  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      <AlertDialogTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className="h-7 gap-1.5 text-xs text-destructive hover:text-destructive"
        >
          <Trash2 className="size-3.5" />
          Eliminar definitivamente
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            ¿Eliminar {items.length}{" "}
            {items.length === 1 ? "artículo" : "artículos"} definitivamente?
          </AlertDialogTitle>
          <AlertDialogDescription>
            Esta acción no se puede deshacer. Los artículos con ventas
            registradas no serán eliminados y recibirás un aviso por cada uno.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            onClick={async (e) => {
              e.preventDefault()
              setPending(true)
              await onConfirm()
              setPending(false)
              setOpen(false)
            }}
            disabled={pending}
          >
            {pending && <Loader2 className="size-4 animate-spin" />}
            Eliminar definitivamente
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}

function CreateGroupDialog({
  open,
  items,
  loading,
  onClose,
  onConfirm,
}: {
  open: boolean
  items: ItemListItem[]
  loading: boolean
  onClose: () => void
  onConfirm: (name: string) => Promise<void>
}) {
  const [name, setName] = React.useState("")
  React.useEffect(() => {
    if (open) setName("")
  }, [open])

  return (
    <AlertDialog open={open} onOpenChange={(v) => !v && onClose()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Agrupar artículos</AlertDialogTitle>
          <AlertDialogDescription>
            Vamos a crear un grupo con los <strong>{items.length}</strong>{" "}
            artículos seleccionados. El grupo aparece como una sola fila en el
            listado y al clickearlo verás los items dentro.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <div className="flex flex-col gap-1.5 py-2">
          <Label className="text-xs">Nombre del grupo</Label>
          <Input
            autoFocus
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Ej: Bebidas, Promo verano, Combos del día…"
            onKeyDown={(e) => {
              if (e.key === "Enter" && name.trim()) {
                e.preventDefault()
                onConfirm(name.trim())
              }
            }}
          />
        </div>
        <AlertDialogFooter>
          <AlertDialogCancel onClick={onClose}>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            onClick={async (e) => {
              e.preventDefault()
              if (!name.trim()) return
              await onConfirm(name.trim())
            }}
            disabled={!name.trim() || loading}
          >
            {loading && <Loader2 className="size-3.5 animate-spin" />}
            Crear grupo
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}

function RenameGroupDialog({
  open,
  onOpenChange,
  currentName,
  loading,
  onSubmit,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  currentName: string
  loading: boolean
  onSubmit: (name: string) => Promise<void>
}) {
  const [name, setName] = React.useState(currentName)
  React.useEffect(() => {
    if (open) setName(currentName)
  }, [open, currentName])

  const isValid = name.trim() !== "" && name.trim() !== currentName

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Renombrar grupo</AlertDialogTitle>
          <AlertDialogDescription>
            Cambiá el nombre del grupo. Los artículos dentro no se ven
            afectados — solo cambia cómo aparece este grupo en el listado.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <div className="flex flex-col gap-1.5 py-2">
          <Label className="text-xs">Nuevo nombre</Label>
          <Input
            autoFocus
            value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && isValid) {
                e.preventDefault()
                onSubmit(name.trim())
              }
            }}
          />
        </div>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            onClick={async (e) => {
              e.preventDefault()
              if (!isValid) return
              await onSubmit(name.trim())
            }}
            disabled={!isValid || loading}
          >
            {loading && <Loader2 className="size-3.5 animate-spin" />}
            Guardar
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}

function BarcodeDialog({
  open,
  items,
  onClose,
  onConfirm,
}: {
  open: boolean
  items: ItemListItem[]
  onClose: () => void
  onConfirm: (qty: number) => void
}) {
  const [qty, setQty] = React.useState("1")
  React.useEffect(() => {
    if (open) setQty("1")
  }, [open])

  const n = parseInt(qty, 10)
  const isValid = Number.isInteger(n) && n > 0 && n < 1000
  const total = isValid ? n * items.length : 0

  return (
    <AlertDialog open={open} onOpenChange={(v) => !v && onClose()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Generar códigos de barra</AlertDialogTitle>
          <AlertDialogDescription>
            Vamos a abrir una página imprimible con los códigos de los{" "}
            <strong>{items.length}</strong>{" "}
            {items.length === 1 ? "artículo seleccionado" : "artículos seleccionados"}.
            Elegí cuántas etiquetas querés por artículo.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <div className="flex flex-col gap-1.5 py-2">
          <Label className="text-xs">Etiquetas por artículo</Label>
          <Input
            autoFocus
            type="number"
            min={1}
            max={999}
            value={qty}
            onChange={(e) => setQty(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && isValid) {
                e.preventDefault()
                onConfirm(n)
              }
            }}
            className="tabular-nums text-right"
          />
          {isValid && (
            <p className="text-xs text-muted-foreground tabular-nums">
              Total: {total} etiquetas
            </p>
          )}
        </div>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            onClick={(e) => {
              e.preventDefault()
              if (!isValid) return
              onConfirm(n)
            }}
            disabled={!isValid}
          >
            Generar
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
