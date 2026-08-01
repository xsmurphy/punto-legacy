"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Pencil, Plus, Trash2 } from "lucide-react"
import { toast } from "sonner"

import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
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
import { Separator } from "@/components/ui/separator"
import {
  useRegistersAdmin,
  useCreateRegister,
  useUpdateRegister,
  useDeleteRegister,
  type RegisterFiscal,
  type RegisterListItem,
} from "@/hooks/use-registers-admin"

const EMPTY_FISCAL: RegisterFiscal = {
  invoiceAuth: "",
  invoicePrefix: "",
  invoiceAuthStart: "",
  invoiceAuthExpiration: "",
}

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
  const [editFiscal, setEditFiscal] = React.useState<RegisterFiscal>(EMPTY_FISCAL)

  const [deleteTarget, setDeleteTarget] = React.useState<RegisterListItem | null>(null)

  const registers = (data?.registers ?? []).filter((r) => r.outletId === outletId)

  function openEdit(reg: RegisterListItem) {
    setEditTarget(reg)
    setEditName(reg.name)
    setEditStatus(reg.status)
    setEditFiscal({ ...EMPTY_FISCAL, ...reg.fiscal })
  }

  function patchFiscal(p: Partial<RegisterFiscal>) {
    setEditFiscal((f) => ({ ...f, ...p }))
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
      id: "timbrado",
      header: "Timbrado",
      // La caja es el punto de expedición (context/29 §1): su timbrado se
      // administra ACÁ. Facturación electrónica y la numeración fiscal solo
      // lo leen.
      cell: ({ row }) => {
        const f = row.original.fiscal
        if (!f.invoiceAuth) {
          return <span className="text-sm text-muted-foreground">Sin timbrado</span>
        }
        return (
          <span className="text-sm tabular-nums">
            {f.invoiceAuth}
            {f.invoicePrefix ? ` · ${f.invoicePrefix}` : ""}
          </span>
        )
      },
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <RowActions
          actions={[
            { label: "Editar", icon: Pencil, onSelect: () => openEdit(row.original) },
            {
              label: "Eliminar",
              icon: Trash2,
              variant: "destructive",
              onSelect: () => setDeleteTarget(row.original),
            },
          ]}
        />
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
        <DialogContent className="sm:max-w-2xl">
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

            <Separator />

            {/* Timbrado — la caja es el punto de expedición: estos datos son
                de la caja (sirven a la numeración fiscal y a la impresión,
                tenga o no el comercio facturación electrónica). */}
            <div className="space-y-3">
              <div>
                <h3 className="text-base font-semibold tracking-tight">Timbrado</h3>
                <p className="text-sm text-muted-foreground">
                  El timbrado que la SET asignó a este punto de expedición.
                </p>
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="edit-stamp-auth">Número de timbrado</Label>
                  <Input
                    id="edit-stamp-auth"
                    value={editFiscal.invoiceAuth}
                    onChange={(e) => patchFiscal({ invoiceAuth: e.target.value.replace(/\D/g, "") })}
                    placeholder="12345678"
                    className="tabular-nums"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="edit-stamp-prefix">Establecimiento y punto (EEE-PPP)</Label>
                  <Input
                    id="edit-stamp-prefix"
                    value={editFiscal.invoicePrefix}
                    onChange={(e) => patchFiscal({ invoicePrefix: e.target.value.replace(/[^0-9-]/g, "").slice(0, 7) })}
                    placeholder="001-001"
                    className="tabular-nums"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="edit-stamp-start">Vigente desde</Label>
                  <Input
                    id="edit-stamp-start"
                    type="date"
                    value={editFiscal.invoiceAuthStart}
                    onChange={(e) => patchFiscal({ invoiceAuthStart: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="edit-stamp-exp">Vence</Label>
                  <Input
                    id="edit-stamp-exp"
                    type="date"
                    value={editFiscal.invoiceAuthExpiration}
                    onChange={(e) => patchFiscal({ invoiceAuthExpiration: e.target.value })}
                  />
                </div>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditTarget(null)}>Cancelar</Button>
            <Button
              disabled={!editName.trim() || updateRegister.isPending}
              onClick={() => {
                if (!editTarget) return
                updateRegister.mutate(
                  { id: editTarget.id, name: editName.trim(), status: editStatus, fiscal: editFiscal },
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
