"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { Plus, Boxes, Loader2 } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogTrigger,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import { EmptyState } from "@/components/empty-state"
import { CategoryMultiSelect } from "@/components/categories/category-multi-select"

import { useOutlets } from "@/hooks/use-outlets"
import { useOutletLocations } from "@/hooks/use-outlet-locations"
import { useCategories } from "@/hooks/use-categories"
import { useDebounce } from "@/hooks/use-debounce"
import {
  useInventoryCounts,
  useCreateInventoryCount,
  useInventoryCountPreview,
  type InventoryCountScopeInput,
  type InventoryCountSession,
} from "@/hooks/use-inventory-counts"
import { formatMoney as _formatMoney } from "@/lib/format"

function formatMoney(v: number): string {
  return _formatMoney(v, undefined)
}

const STATUS_LABEL: Record<number, string> = {
  0: "Cancelado",
  1: "En progreso",
  2: "Finalizado",
}

const STATUS_VARIANT: Record<number, "default" | "secondary" | "destructive" | "outline"> = {
  0: "destructive",
  1: "default",
  2: "secondary",
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

function NewSessionDialog() {
  const router  = useRouter()
  const [open, setOpen]             = React.useState(false)
  const [outletId, setOutletId]     = React.useState<string>("")
  const [locationId, setLocationId] = React.useState<string>("__none__")
  const [categoryIds, setCategoryIds]           = React.useState<string[]>([])
  const [includeZeroStock, setIncludeZeroStock] = React.useState(false)
  const [note, setNote]             = React.useState<string>("")

  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []

  // useOutletLocations retorna el array directamente (QueryResult<OutletLocation[]>)
  const { data: locations } = useOutletLocations(outletId || null)

  const { data: categoriesData } = useCategories()
  const categoryOptions = React.useMemo(
    () => (categoriesData?.categories ?? []).map((c) => ({ id: c.id, name: c.name })),
    [categoriesData],
  )

  const create = useCreateInventoryCount()

  // Alcance que se manda al backend, uno solo para el preview y para el
  // create — no puede divergir lo que se cuenta de lo que se crea.
  const scope: InventoryCountScopeInput | null = React.useMemo(
    () =>
      outletId
        ? {
            outletId,
            locationId: locationId !== "__none__" ? locationId : undefined,
            categoryIds,
            includeZeroStock,
          }
        : null,
    [outletId, locationId, categoryIds, includeZeroStock],
  )

  // Debounce: tocar tres categorías seguidas dispararía tres previews.
  const debouncedScope = useDebounce(scope, 300)
  const preview = useInventoryCountPreview(debouncedScope)
  const previewStale = scope !== debouncedScope || preview.isFetching
  const previewCount = preview.data?.count ?? 0

  async function handleCreate() {
    if (!scope) {
      toast.error("Seleccioná una sucursal")
      return
    }
    try {
      const result = await create.mutateAsync({ ...scope, note: note.trim() || undefined })
      toast.success(`Sesión creada con ${result.itemCount} artículos`)
      setOpen(false)
      router.push(`/inventory-count/${result.id}`)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al crear la sesión")
    }
  }

  function handleOpenChange(v: boolean) {
    setOpen(v)
    if (!v) {
      setOutletId("")
      setLocationId("__none__")
      setCategoryIds([])
      setIncludeZeroStock(false)
      setNote("")
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogTrigger asChild>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Nueva sesión
        </Button>
      </DialogTrigger>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Nueva sesión de conteo</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label htmlFor="outlet">Sucursal</Label>
            <Select value={outletId} onValueChange={(v) => { setOutletId(v); setLocationId("__none__") }}>
              <SelectTrigger id="outlet">
                <SelectValue placeholder="Seleccioná una sucursal" />
              </SelectTrigger>
              <SelectContent>
                {outlets.map((o) => (
                  <SelectItem key={o.id} value={o.id}>
                    {o.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {outletId && (locations?.length ?? 0) > 0 && (
            <div className="space-y-1.5">
              <Label htmlFor="location">Depósito (opcional)</Label>
              <Select value={locationId} onValueChange={setLocationId}>
                <SelectTrigger id="location">
                  <SelectValue placeholder="Todos los depósitos" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">Todos los depósitos</SelectItem>
                  {(locations ?? []).map((l) => (
                    <SelectItem key={l.id} value={l.id}>
                      {l.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="categories">Categorías (opcional)</Label>
            <CategoryMultiSelect
              id="categories"
              options={categoryOptions}
              value={categoryIds}
              onChange={setCategoryIds}
              placeholder="Todas las categorías"
            />
            <p className="text-xs text-muted-foreground">
              Sin selección se cuentan todas. Un artículo entra si la categoría es
              la principal o una de las secundarias.
            </p>
          </div>

          <div className="flex items-start justify-between gap-4 rounded-md border p-3">
            <div className="space-y-0.5">
              <Label htmlFor="include-zero">Incluir artículos sin stock en la sucursal</Label>
              <p className="text-xs text-muted-foreground">
                Por defecto se cuentan solo los artículos con movimiento en la sucursal
                elegida. Activalo para el primer conteo de una sucursal nueva.
              </p>
            </div>
            <Switch
              id="include-zero"
              checked={includeZeroStock}
              onCheckedChange={setIncludeZeroStock}
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="note">Nota (opcional)</Label>
            <Textarea
              id="note"
              placeholder="Descripción del conteo..."
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={2}
            />
          </div>

          {/* Posición fija: el bloque existe apenas hay sucursal y solo cambia
              su texto, para que el botón de crear no se mueva bajo el cursor. */}
          {outletId && (
            <p className="text-sm text-muted-foreground" aria-live="polite">
              {previewStale ? (
                <span className="inline-flex items-center gap-2">
                  <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  Calculando el alcance…
                </span>
              ) : preview.isError ? (
                "No se pudo calcular el alcance."
              ) : previewCount === 0 ? (
                "El alcance elegido no incluye ningún artículo."
              ) : (
                <>
                  Vas a contar <strong className="text-foreground">{previewCount}</strong>{" "}
                  {previewCount === 1 ? "artículo" : "artículos"}.
                </>
              )}
            </p>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)}>
            Cancelar
          </Button>
          {/* Si el preview falla no bloqueamos la creación: el backend valida
              el alcance de nuevo y devuelve el 422 con el motivo real. */}
          <Button
            onClick={handleCreate}
            disabled={
              create.isPending ||
              !outletId ||
              previewStale ||
              (preview.isSuccess && previewCount === 0)
            }
          >
            {create.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Crear sesión
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

const columns: ColumnDef<InventoryCountSession>[] = [
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
    meta: { label: "Nº de conteo" },
  },
  {
    accessorKey: "startedAt",
    header: "Fecha inicio",
    cell: ({ row }) => formatDate(row.original.startedAt),
  },
  {
    accessorKey: "outletName",
    header: "Sucursal",
  },
  {
    accessorKey: "locationName",
    header: "Depósito",
    cell: ({ row }) => row.original.locationName ?? "—",
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
  {
    id: "progress",
    header: "Progreso",
    cell: ({ row }) => `${row.original.countedItems} / ${row.original.totalItems}`,
  },
  {
    accessorKey: "totalCostDelta",
    header: "Diferencia ($)",
    cell: ({ row }) => {
      const v = row.original.totalCostDelta
      const color = v < 0 ? "text-red-500" : v > 0 ? "text-green-600" : ""
      return <span className={color}>{formatMoney(v)}</span>
    },
  },
]

export default function InventoryCountPage() {
  const router  = useRouter()
  const { data, isLoading } = useInventoryCounts()
  const rows = data?.rows ?? []

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Conteo de inventario</h1>
          <p className="text-sm text-muted-foreground">Toma física de inventario por sesión.</p>
        </div>
        <div className="flex items-center gap-2">
          <NewSessionDialog />
        </div>
      </header>

      {!isLoading && rows.length === 0 ? (
        <EmptyState
          icon={Boxes}
          title="Sin sesiones de conteo"
          description="Creá una nueva sesión para iniciar la toma física de inventario."
        />
      ) : (
        <DataTable
          tableId="inventory-counts"
          columns={columns}
          data={rows}
          isLoading={isLoading}
          onRowClick={(row) => router.push(`/inventory-count/${row.inventoryCountId}`)}
        />
      )}
    </div>
  )
}
