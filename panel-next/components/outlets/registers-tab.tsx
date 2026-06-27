"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Pencil, Plus, Trash2 } from "lucide-react"
import { toast } from "sonner"

import { DataTable } from "@/components/data-table/data-table"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
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
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  useRegistersAdmin,
  useCreateRegister,
  useUpdateRegister,
  useDeleteRegister,
  type RegisterListItem,
} from "@/hooks/use-registers-admin"

/**
 * CRUD de cajas para una sucursal específica. Scoped por `outletId`: filtra
 * la lista global y el form de crear no pregunta sucursal (la asume).
 *
 * Vive bajo /outlets/[id] (tab "Cajas") porque Caja es siempre dependiente
 * de Sucursal en la jerarquía Company → Outlet → Register.
 */
export function RegistersTab({ outletId }: { outletId: string }) {
  const { data, isLoading } = useRegistersAdmin()
  const createRegister = useCreateRegister()
  const updateRegister = useUpdateRegister()
  const deleteRegister = useDeleteRegister()

  const [showCreate, setShowCreate] = React.useState(false)
  const [newName, setNewName] = React.useState("")

  const [editTarget, setEditTarget] = React.useState<RegisterListItem | null>(null)
  const [editName, setEditName] = React.useState("")
  const [editStatus, setEditStatus] = React.useState(true)

  const [deleteTarget, setDeleteTarget] = React.useState<RegisterListItem | null>(null)

  const registers = (data?.registers ?? []).filter((r) => r.outletId === outletId)

  function openEdit(reg: RegisterListItem) {
    setEditTarget(reg)
    setEditName(reg.name)
    setEditStatus(reg.status)
  }

  const columns = React.useMemo<ColumnDef<RegisterListItem>[]>(() => [
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ row }) =>
        row.original.status ? (
          <Badge variant="default">Activa</Badge>
        ) : (
          <Badge variant="secondary">Inactiva</Badge>
        ),
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <div className="flex gap-1">
          <Button variant="ghost" size="sm" onClick={() => openEdit(row.original)}>
            <Pencil className="size-4 mr-1.5" />
            Editar
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="text-destructive hover:text-destructive"
            onClick={() => setDeleteTarget(row.original)}
          >
            <Trash2 className="size-4 mr-1.5" />
            Eliminar
          </Button>
        </div>
      ),
    },
  ], [])

  return (
    <>
      <div className="flex justify-end mb-4">
        <Button onClick={() => { setNewName(""); setShowCreate(true) }}>
          <Plus className="size-4 mr-1.5" />
          Nueva caja
        </Button>
      </div>

      <DataTable
        tableId={`registers-outlet-${outletId}`}
        columns={columns}
        data={registers}
        isLoading={isLoading}
        searchPlaceholder="Buscar caja..."
        exportFileName={null}
      />

      <Dialog open={showCreate} onOpenChange={setShowCreate}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="text-2xl font-semibold">Nueva caja</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="new-name">Nombre</Label>
              <Input
                id="new-name"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="Caja Principal"
                autoFocus
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCreate(false)}>Cancelar</Button>
            <Button
              disabled={!newName.trim() || createRegister.isPending}
              onClick={() => {
                createRegister.mutate(
                  { outletId, name: newName.trim() },
                  {
                    onSuccess: () => { toast.success("Caja creada"); setShowCreate(false) },
                    onError: (err) => toast.error(err.message),
                  }
                )
              }}
            >
              Crear
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={editTarget !== null} onOpenChange={(o) => { if (!o) setEditTarget(null) }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="text-2xl font-semibold">Editar caja</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="edit-name">Nombre</Label>
              <Input
                id="edit-name"
                value={editName}
                onChange={(e) => setEditName(e.target.value)}
              />
            </div>
            <div className="flex items-center gap-3">
              <Switch id="edit-status" checked={editStatus} onCheckedChange={setEditStatus} />
              <Label htmlFor="edit-status">Activa</Label>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditTarget(null)}>Cancelar</Button>
            <Button
              disabled={!editName.trim() || updateRegister.isPending}
              onClick={() => {
                if (!editTarget) return
                updateRegister.mutate(
                  { id: editTarget.id, name: editName.trim(), status: editStatus },
                  {
                    onSuccess: () => { toast.success("Caja actualizada"); setEditTarget(null) },
                    onError: (err) => toast.error(err.message),
                  }
                )
              }}
            >
              Guardar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={deleteTarget !== null} onOpenChange={(o) => { if (!o) setDeleteTarget(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar caja</AlertDialogTitle>
            <AlertDialogDescription>
              {`¿Eliminar "${deleteTarget?.name}"? Si tiene transacciones históricas, se desactivará en lugar de eliminarse.`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!deleteTarget) return
                deleteRegister.mutate(deleteTarget.id, {
                  onSuccess: (res) => {
                    if (res.deleted === 'soft') {
                      toast.info("Caja desactivada — tiene transacciones históricas")
                    } else {
                      toast.success("Caja eliminada")
                    }
                    setDeleteTarget(null)
                  },
                  onError: (err) => {
                    toast.error(err.message)
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
    </>
  )
}
