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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs"
import { useScreens, useRevokeScreen, type Screen } from "@/hooks/use-screens"
import { useOutlets } from "@/hooks/use-outlets"
import {
  useRegistersAdmin,
  useCreateRegister,
  useUpdateRegister,
  useDeleteRegister,
  type RegisterListItem,
} from "@/hooks/use-registers-admin"

// ---------------------------------------------------------------------------
// ScreensTab — lógica idéntica al componente original (sin cambios funcionales)
// ---------------------------------------------------------------------------

function ScreensTab() {
  const { data, isLoading } = useScreens()
  const revoke = useRevokeScreen()
  const [revokeId, setRevokeId] = React.useState<string | null>(null)

  function niceDate(iso: string | null) {
    if (!iso) return "—"
    return new Intl.DateTimeFormat("es", {
      day: "numeric", month: "short",
      hour: "2-digit", minute: "2-digit",
    }).format(new Date(iso))
  }

  const columns = React.useMemo<ColumnDef<Screen>[]>(() => [
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => (
        <span className="font-medium">{row.original.name}</span>
      ),
    },
    {
      accessorKey: "registerName",
      header: "Caja",
      cell: ({ row }) => row.original.registerName ?? "—",
    },
    {
      accessorKey: "ipLast",
      header: "IP",
      cell: ({ row }) => (
        <span className="font-mono text-sm">{row.original.ipLast ?? "—"}</span>
      ),
    },
    {
      accessorKey: "lastSeenAt",
      header: "Última actividad",
      cell: ({ row }) => niceDate(row.original.lastSeenAt),
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ row }) =>
        row.original.status === 1 ? (
          <Badge variant="default">Activa</Badge>
        ) : (
          <Badge variant="secondary">Revocada</Badge>
        ),
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) =>
        row.original.status === 1 ? (
          <Button
            variant="ghost"
            size="sm"
            className="text-destructive hover:text-destructive"
            onClick={() => setRevokeId(row.original.id)}
          >
            <Trash2 className="size-4 mr-1.5" />
            Revocar
          </Button>
        ) : null,
    },
  ], [])

  const screens = data?.screens ?? []

  return (
    <>
      <DataTable
        tableId="screens"
        columns={columns}
        data={screens}
        isLoading={isLoading}
        searchPlaceholder="Buscar pantalla..."
        exportFileName={null}
      />

      <AlertDialog open={revokeId !== null} onOpenChange={(o) => { if (!o) setRevokeId(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revocar pantalla</AlertDialogTitle>
            <AlertDialogDescription>
              La pantalla dejará de funcionar inmediatamente. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!revokeId) return
                revoke.mutate(revokeId, {
                  onSuccess: () => { toast.success("Pantalla revocada"); setRevokeId(null) },
                  onError: (err) => { toast.error(err.message) },
                })
              }}
            >
              Revocar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

// ---------------------------------------------------------------------------
// RegistersTab — CRUD de cajas
// ---------------------------------------------------------------------------

function RegistersTab() {
  const { data, isLoading } = useRegistersAdmin()
  const { data: outletsData } = useOutlets()
  const createRegister  = useCreateRegister()
  const updateRegister  = useUpdateRegister()
  const deleteRegister  = useDeleteRegister()

  // Dialog: nueva caja
  const [showCreate, setShowCreate] = React.useState(false)
  const [newOutletId, setNewOutletId] = React.useState("")
  const [newName, setNewName]         = React.useState("")

  // Dialog: editar caja
  const [editTarget, setEditTarget] = React.useState<RegisterListItem | null>(null)
  const [editName, setEditName]     = React.useState("")
  const [editStatus, setEditStatus] = React.useState(true)

  // AlertDialog: eliminar
  const [deleteTarget, setDeleteTarget] = React.useState<RegisterListItem | null>(null)

  // useOutlets devuelve { rows: OutletListItem[] }
  const outlets   = outletsData?.rows ?? []
  const registers = data?.registers ?? []

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
      accessorKey: "outletName",
      header: "Sucursal",
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
          <Button
            variant="ghost"
            size="sm"
            onClick={() => openEdit(row.original)}
          >
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
        <Button onClick={() => { setNewOutletId(""); setNewName(""); setShowCreate(true) }}>
          <Plus className="size-4 mr-1.5" />
          Nueva caja
        </Button>
      </div>

      <DataTable
        tableId="registers"
        columns={columns}
        data={registers}
        isLoading={isLoading}
        searchPlaceholder="Buscar caja..."
        exportFileName={null}
      />

      {/* Dialog: nueva caja */}
      <Dialog open={showCreate} onOpenChange={setShowCreate}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Nueva caja</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="new-outlet">Sucursal</Label>
              <Select value={newOutletId} onValueChange={setNewOutletId}>
                <SelectTrigger id="new-outlet">
                  <SelectValue placeholder="Seleccionar sucursal..." />
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
            <div className="space-y-1.5">
              <Label htmlFor="new-name">Nombre</Label>
              <Input
                id="new-name"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="Caja Principal"
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCreate(false)}>Cancelar</Button>
            <Button
              disabled={!newOutletId || !newName.trim() || createRegister.isPending}
              onClick={() => {
                createRegister.mutate(
                  { outletId: newOutletId, name: newName.trim() },
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

      {/* Dialog: editar caja */}
      <Dialog open={editTarget !== null} onOpenChange={(o) => { if (!o) setEditTarget(null) }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Editar caja</DialogTitle>
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
              <Switch
                id="edit-status"
                checked={editStatus}
                onCheckedChange={setEditStatus}
              />
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

      {/* AlertDialog: eliminar caja */}
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

// ---------------------------------------------------------------------------
// DevicesPage — Tabs: Pantallas cliente + Cajas
// ---------------------------------------------------------------------------

export default function DevicesPage() {
  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Dispositivos</h1>
          <p className="text-sm text-muted-foreground">Pantallas cliente y cajas registradoras del POS.</p>
        </div>
      </header>

      <Tabs defaultValue="screens">
        <TabsList>
          <TabsTrigger value="screens">Pantallas cliente</TabsTrigger>
          <TabsTrigger value="registers">Cajas</TabsTrigger>
        </TabsList>
        <TabsContent value="screens" className="mt-4">
          <ScreensTab />
        </TabsContent>
        <TabsContent value="registers" className="mt-4">
          <RegistersTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}
