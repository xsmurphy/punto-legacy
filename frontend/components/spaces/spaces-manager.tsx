"use client"

import * as React from "react"
import { toast } from "sonner"
import { LayoutGrid, Plus, Pencil, Trash2, Users } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { EmptyState } from "@/components/empty-state"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"

import { useOutlets } from "@/hooks/use-outlets"
import { useSpaceSectors } from "@/hooks/use-space-sectors"
import {
  useSpaces,
  useBulkCreateSpaces,
  useUpdateSpace,
  useDeleteSpace,
  type Space,
  type SpaceShape,
} from "@/hooks/use-spaces"
import { LayoutEditor } from "@/components/spaces/layout-editor"
import { SectorsPanel } from "@/components/spaces/sectors-panel"

const SHAPE_LABELS: Record<SpaceShape, string> = {
  square:      "Cuadrada",
  round:       "Redonda",
  rect:        "Rectangular",
  bar:         "Barra",
  decor_wall: "Pared (decorativo)",
  decor_plant:"Planta (decorativo)",
}

const REAL_SHAPES: SpaceShape[] = ["square", "round", "rect"]

export function SpacesManager() {
  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []
  const [outletId, setOutletId] = React.useState("")
  React.useEffect(() => {
    if (!outletId && outlets.length > 0) setOutletId(outlets[0].id)
  }, [outlets, outletId])

  // Todo espacio SIEMPRE pertenece a un sector (space.sectorid NOT NULL,
  // mig 82) — el chip de sector del manager sigue el mismo criterio: uno
  // SIEMPRE activo, default = el primero de la sucursal (el backend ya
  // garantiza al menos un sector "Salón" por outlet vía
  // SpaceSectorService::ensureDefaultSector()).
  const [sectorFilter, setSectorFilter] = React.useState<string | null>(null)

  const { data: sectorsData } = useSpaceSectors(outletId)
  const sectors = sectorsData?.sectors ?? []

  // Bugfix (prod): el filtro de sector NO se reseteaba al cambiar de
  // sucursal → el bulk create mandaba sectorId de la sucursal vieja +
  // outletId de la nueva y `assertSectorBelongs`/`resolveOutletAndSector`
  // (api/lib/Spaces/SpaceService.php) rechazaba con "sectorId inválido para
  // este outlet/tenant". El backend ahora deriva el outlet DESDE el sector
  // cuando hay sectorId (mismatch imposible por construcción), pero acá
  // además se recalcula el sector activo apenas cambian los sectores
  // disponibles (outlet nuevo o el sector activo ya no pertenece a este
  // outlet) — nunca queda un sectorId huérfano de la sucursal anterior.
  React.useEffect(() => {
    if (sectors.length === 0) return
    if (!sectorFilter || !sectors.some((s) => s.id === sectorFilter)) {
      setSectorFilter(sectors[0].id)
    }
  }, [sectors, sectorFilter])

  const { data: tablesData, isLoading } = useSpaces(outletId, sectorFilter ?? undefined)
  const tables = (tablesData?.spaces ?? []).filter((t) => REAL_SHAPES.includes(t.shape))

  const bulkCreate = useBulkCreateSpaces()
  const [bulkCount, setBulkCount] = React.useState(4)

  const [editTarget, setEditTarget] = React.useState<Space | null>(null)
  const [deleteTarget, setDeleteTarget] = React.useState<Space | null>(null)
  const deleteMutation = useDeleteSpace()

  function handleBulkCreate() {
    if (!outletId || bulkCount < 1) return
    bulkCreate.mutate(
      { outletId, count: bulkCount, sectorId: sectorFilter ?? undefined },
      {
        onSuccess: (res) => toast.success(`${res.spaceIds.length} espacios creados`),
        onError: (e) => toast.error(e.message),
      },
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Espacios</h1>
          <p className="text-sm text-muted-foreground">
            Sectores, espacios y el plano del local — mesas, sillas de atención, habitaciones, según
            tu rubro. Los estados (libre, ocupado, pagando) se calculan solos a partir de las
            sesiones activas — no se editan a mano.
          </p>
        </div>
        <div className="flex flex-col gap-1.5 max-w-xs">
          <Label htmlFor="outlet-selector">Sucursal</Label>
          <Select value={outletId} onValueChange={setOutletId}>
            <SelectTrigger id="outlet-selector">
              <SelectValue placeholder="Elegí una sucursal…" />
            </SelectTrigger>
            <SelectContent>
              {outlets.map((o) => (
                <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </header>

      {!outletId ? (
        <EmptyState
          icon={LayoutGrid}
          title="Seleccioná una sucursal"
          description="Elegí una sucursal para ver y administrar sus espacios."
          showMarquee={false}
        />
      ) : (
        <>
          <SectorsPanel outletId={outletId} selectedSectorId={sectorFilter ?? sectors[0]?.id ?? ""} onSelectSector={setSectorFilter} />

          <Tabs defaultValue="tables">
            <TabsList>
              <TabsTrigger value="tables">Espacios</TabsTrigger>
              <TabsTrigger value="layout">Editor de layout</TabsTrigger>
            </TabsList>

            <TabsContent value="tables" className="mt-4 flex flex-col gap-4">
              <div className="flex items-center gap-2">
                <Input
                  type="number"
                  min={1}
                  max={50}
                  value={bulkCount}
                  onChange={(e) => setBulkCount(Math.max(1, Math.min(50, Number(e.target.value))))}
                  className="h-9 w-20"
                />
                <Button variant="outline" onClick={handleBulkCreate} disabled={bulkCreate.isPending || !sectorFilter}>
                  <Plus className="size-4 mr-1.5" />
                  Crear {bulkCount} espacios numerados
                  {sectorFilter ? ` en ${sectors.find((s) => s.id === sectorFilter)?.name ?? "este sector"}` : ""}
                </Button>
              </div>

              {isLoading ? (
                <p className="text-sm text-muted-foreground">Cargando espacios…</p>
              ) : tables.length === 0 ? (
                <EmptyState
                  icon={LayoutGrid}
                  title="Sin espacios"
                  description='Usá "Crear espacios numerados" arriba para dar de alta la primera tanda.'
                  showMarquee={false}
                />
              ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                  {tables.map((t) => (
                    <TableCard
                      key={t.id}
                      table={t}
                      sectorName={sectors.find((s) => s.id === t.sectorId)?.name}
                      onEdit={() => setEditTarget(t)}
                      onDelete={() => setDeleteTarget(t)}
                    />
                  ))}
                </div>
              )}
            </TabsContent>

            <TabsContent value="layout" className="mt-4">
              <LayoutEditor outletId={outletId} />
            </TabsContent>
          </Tabs>
        </>
      )}

      <EditSpaceDialog table={editTarget} sectors={sectors} onClose={() => setEditTarget(null)} />

      <AlertDialog open={deleteTarget !== null} onOpenChange={(o) => { if (!o) setDeleteTarget(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Deshabilitar espacio</AlertDialogTitle>
            <AlertDialogDescription>
              {`¿Deshabilitar "${deleteTarget?.name}"? Deja de aparecer en el plano operativo del POS. El historial de sesiones se conserva.`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!deleteTarget) return
                deleteMutation.mutate(deleteTarget.id, {
                  onSuccess: () => {
                    toast.success("Espacio deshabilitado")
                    setDeleteTarget(null)
                  },
                })
              }}
            >
              Deshabilitar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function TableCard({
  table,
  sectorName,
  onEdit,
  onDelete,
}: {
  table: Space
  sectorName?: string
  onEdit: () => void
  onDelete: () => void
}) {
  return (
    <Card className="flex flex-col gap-2 p-3">
      <div className="flex items-center justify-between">
        <span className="font-semibold">{table.name}</span>
        <Badge variant="outline">{SHAPE_LABELS[table.shape]}</Badge>
      </div>
      <div className="flex items-center gap-3 text-sm text-muted-foreground">
        <span className="flex items-center gap-1"><Users className="size-3.5" />{table.seats}</span>
        {sectorName && <span className="truncate">{sectorName}</span>}
      </div>
      <div className="flex gap-1 border-t pt-2 -mx-3 px-3 -mb-3 pb-3">
        <Button variant="ghost" size="sm" onClick={onEdit}>
          <Pencil className="size-3.5 mr-1.5" /> Editar
        </Button>
        <Button variant="ghost" size="sm" className="text-destructive hover:text-destructive" onClick={onDelete}>
          <Trash2 className="size-3.5 mr-1.5" /> Deshabilitar
        </Button>
      </div>
    </Card>
  )
}

function EditSpaceDialog({
  table,
  sectors,
  onClose,
}: {
  table: Space | null
  sectors: { id: string; name: string }[]
  onClose: () => void
}) {
  const update = useUpdateSpace()
  const [name, setName] = React.useState("")
  const [seats, setSeats] = React.useState(4)
  const [shape, setShape] = React.useState<SpaceShape>("square")
  const [sectorId, setSectorId] = React.useState<string>("")

  React.useEffect(() => {
    if (!table) return
    setName(table.name)
    setSeats(table.seats)
    setShape(table.shape)
    setSectorId(table.sectorId ?? "")
  }, [table])

  function handleSave() {
    if (!table || !sectorId) return
    update.mutate(
      { id: table.id, values: { name: name.trim(), seats, shape, sectorId } },
      {
        onSuccess: () => { toast.success("Espacio actualizado"); onClose() },
        onError: (e) => toast.error(e.message),
      },
    )
  }

  return (
    <Dialog open={table !== null} onOpenChange={(o) => { if (!o) onClose() }}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">Editar espacio</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="table-name">Nombre / número</Label>
            <Input id="table-name" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="table-seats">Asientos</Label>
              <Input
                id="table-seats"
                type="number"
                min={1}
                max={40}
                value={seats}
                onChange={(e) => setSeats(Math.max(1, Math.min(40, Number(e.target.value))))}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="table-shape">Forma</Label>
              <Select value={shape} onValueChange={(v) => setShape(v as SpaceShape)}>
                <SelectTrigger id="table-shape"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {REAL_SHAPES.map((s) => (
                    <SelectItem key={s} value={s}>{SHAPE_LABELS[s]}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="table-sector">Sector</Label>
            <Select value={sectorId} onValueChange={setSectorId}>
              <SelectTrigger id="table-sector"><SelectValue /></SelectTrigger>
              <SelectContent>
                {sectors.map((s) => (
                  <SelectItem key={s.id} value={s.id}>{s.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
        <DialogFooter className="mt-2">
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button onClick={handleSave} disabled={update.isPending || !name.trim() || !sectorId}>Guardar</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
