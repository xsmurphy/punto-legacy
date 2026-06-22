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
import { Textarea } from "@/components/ui/textarea"
import { EmptyState } from "@/components/empty-state"

import { useOutlets } from "@/hooks/use-outlets"
import { useOutletLocations } from "@/hooks/use-outlet-locations"
import {
  useInventoryCounts,
  useCreateInventoryCount,
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
  const [locationId, setLocationId] = React.useState<string>("")
  const [note, setNote]             = React.useState<string>("")

  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []

  // useOutletLocations retorna el array directamente (QueryResult<OutletLocation[]>)
  const { data: locations } = useOutletLocations(outletId || null)

  const create = useCreateInventoryCount()

  async function handleCreate() {
    if (!outletId) {
      toast.error("Seleccioná una sucursal")
      return
    }
    try {
      const result = await create.mutateAsync({
        outletId,
        locationId: locationId || undefined,
        note: note.trim() || undefined,
      })
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
      setLocationId("")
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
            <Select value={outletId} onValueChange={(v) => { setOutletId(v); setLocationId("") }}>
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
                  <SelectItem value="">Todos los depósitos</SelectItem>
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
            <Label htmlFor="note">Nota (opcional)</Label>
            <Textarea
              id="note"
              placeholder="Descripción del conteo..."
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={2}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)}>
            Cancelar
          </Button>
          <Button onClick={handleCreate} disabled={create.isPending || !outletId}>
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
    <div className="space-y-4 p-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Boxes className="h-5 w-5 text-muted-foreground" />
          <h1 className="text-xl font-semibold">Conteo de inventario</h1>
        </div>
        <NewSessionDialog />
      </div>

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
