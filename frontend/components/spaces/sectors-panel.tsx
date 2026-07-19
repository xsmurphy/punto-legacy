"use client"

import * as React from "react"
import { toast } from "sonner"
import { Plus, Pencil, Trash2, Check, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
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

import {
  useSpaceSectors,
  useCreateSpaceSector,
  useUpdateSpaceSector,
  useDeleteSpaceSector,
  type SpaceSector,
} from "@/hooks/use-space-sectors"

/**
 * CRUD chico de sectores — lista embebida con inline-rename, sin DataTable
 * (regla #3 de context/14-ui-conventions.md: listado corto embebido usa
 * grilla/Table simple, no el DataTable de listados largos).
 */
export function SectorsPanel({
  outletId,
  selectedSectorId,
  onSelectSector,
}: {
  outletId: string
  selectedSectorId: string | null
  onSelectSector: (id: string | null) => void
}) {
  const { data } = useSpaceSectors(outletId)
  const sectors = data?.sectors ?? []
  const createMutation = useCreateSpaceSector()
  const updateMutation = useUpdateSpaceSector()
  const deleteMutation = useDeleteSpaceSector()

  const [newName, setNewName] = React.useState("")
  const [editingId, setEditingId] = React.useState<string | null>(null)
  const [editingName, setEditingName] = React.useState("")
  const [deleteTarget, setDeleteTarget] = React.useState<SpaceSector | null>(null)

  function handleCreate() {
    const name = newName.trim()
    if (!name) return
    createMutation.mutate(
      { outletId, name, sort: sectors.length },
      {
        onSuccess: (sector) => {
          setNewName("")
          onSelectSector(sector.id)
        },
        onError: (e) => toast.error(e.message),
      },
    )
  }

  function startEdit(s: SpaceSector) {
    setEditingId(s.id)
    setEditingName(s.name)
  }

  function saveEdit() {
    if (!editingId) return
    const name = editingName.trim()
    if (!name) return
    updateMutation.mutate(
      { id: editingId, values: { name } },
      {
        onSuccess: () => setEditingId(null),
        onError: (e) => toast.error(e.message),
      },
    )
  }

  return (
    <div className="flex flex-col gap-2">
      <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Sectores</p>
      <div className="flex flex-wrap items-center gap-1.5">
        {sectors.map((s) => (
          <div key={s.id} className="flex items-center">
            {editingId === s.id ? (
              <div className="flex items-center gap-1">
                <Input
                  value={editingName}
                  onChange={(e) => setEditingName(e.target.value)}
                  className="h-8 w-32"
                  autoFocus
                  onKeyDown={(e) => {
                    if (e.key === "Enter") saveEdit()
                    if (e.key === "Escape") setEditingId(null)
                  }}
                />
                <Button size="icon" variant="ghost" className="size-8" onClick={saveEdit}>
                  <Check className="size-3.5" />
                </Button>
                <Button size="icon" variant="ghost" className="size-8" onClick={() => setEditingId(null)}>
                  <X className="size-3.5" />
                </Button>
              </div>
            ) : (
              <Badge
                variant={selectedSectorId === s.id ? "default" : "secondary"}
                className="cursor-pointer gap-1.5 py-1.5 pl-3 pr-1.5 text-sm font-normal"
                onClick={() => onSelectSector(s.id)}
              >
                {s.name}
                <button
                  type="button"
                  onClick={(e) => { e.stopPropagation(); startEdit(s) }}
                  className="rounded p-0.5 hover:bg-foreground/10"
                  aria-label="Renombrar"
                >
                  <Pencil className="size-3" />
                </button>
                <button
                  type="button"
                  onClick={(e) => { e.stopPropagation(); setDeleteTarget(s) }}
                  className="rounded p-0.5 hover:bg-foreground/10"
                  aria-label="Eliminar"
                >
                  <Trash2 className="size-3" />
                </button>
              </Badge>
            )}
          </div>
        ))}

        <div className="flex items-center gap-1">
          <Input
            value={newName}
            onChange={(e) => setNewName(e.target.value)}
            placeholder="Nuevo sector…"
            className="h-8 w-36"
            onKeyDown={(e) => e.key === "Enter" && handleCreate()}
          />
          <Button size="icon" variant="outline" className="size-8" onClick={handleCreate} disabled={!newName.trim()}>
            <Plus className="size-3.5" />
          </Button>
        </div>
      </div>

      <AlertDialog open={deleteTarget !== null} onOpenChange={(o) => { if (!o) setDeleteTarget(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar sector</AlertDialogTitle>
            <AlertDialogDescription>
              {`¿Eliminar "${deleteTarget?.name}"? Los espacios existentes conservan el sector como referencia histórica pero dejan de listarlo como destino nuevo.`}
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
                    if (selectedSectorId === deleteTarget.id) onSelectSector(null)
                    setDeleteTarget(null)
                  },
                })
              }}
            >
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
