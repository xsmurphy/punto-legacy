"use client"

import * as React from "react"
import { Plus, Pencil, Trash2, ShieldCheck } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
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

import {
  useRoles,
  useCreateRole,
  useUpdateRole,
  useDeleteRole,
  usePermissionCatalog,
  useRoleUsers,
  type Role,
} from "@/hooks/use-roles"

interface RoleFormState {
  name: string
  permissions: string[]
}

function PermissionMatrix({
  groups,
  selected,
  onChange,
  disabled,
}: {
  groups: Record<string, Array<{ id: string; label: string }>>
  selected: string[]
  onChange: (perms: string[]) => void
  disabled?: boolean
}) {
  return (
    <div className="space-y-4">
      {Object.entries(groups).map(([group, perms]) => (
        <div key={group} className="space-y-2">
          <h3 className="text-sm font-medium text-muted-foreground capitalize">{group}</h3>
          <div className="grid grid-cols-1 gap-1 sm:grid-cols-2">
            {perms.map((p) => (
              <div key={p.id} className="flex items-center gap-2">
                <Checkbox
                  id={p.id}
                  checked={selected.includes(p.id)}
                  disabled={disabled}
                  onCheckedChange={(checked) => {
                    onChange(
                      checked
                        ? [...selected, p.id]
                        : selected.filter((x) => x !== p.id),
                    )
                  }}
                />
                <Label
                  htmlFor={p.id}
                  className={`text-sm cursor-pointer ${disabled ? "opacity-50 cursor-not-allowed" : ""}`}
                >
                  {p.label}
                </Label>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}

function RoleDialog({
  open,
  onOpenChange,
  initial,
  groups,
  onSave,
  isPending,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  initial: RoleFormState & { isSeed?: boolean }
  groups: Record<string, Array<{ id: string; label: string }>>
  onSave: (state: RoleFormState) => void
  isPending: boolean
}) {
  const [name, setName] = React.useState(initial.name)
  const [selectedPerms, setSelectedPerms] = React.useState<string[]>(initial.permissions)

  React.useEffect(() => {
    if (open) {
      setName(initial.name)
      setSelectedPerms(initial.permissions)
    }
  }, [open, initial.name, initial.permissions])

  const dialogTitle = initial.isSeed
    ? "Ver permisos"
    : initial.name
      ? "Editar role"
      : "Nuevo role"

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{dialogTitle}</DialogTitle>
        </DialogHeader>
        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label htmlFor="role-name" className="text-sm font-medium">Nombre</Label>
            <Input
              id="role-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Nombre del role"
              disabled={initial.isSeed}
            />
          </div>
          {Object.keys(groups).length > 0 && (
            <div className="space-y-1.5">
              <p className="text-sm font-medium">Permisos</p>
              <div className="rounded-md border p-4">
                <PermissionMatrix
                  groups={groups}
                  selected={selectedPerms}
                  onChange={setSelectedPerms}
                  disabled={initial.isSeed}
                />
              </div>
            </div>
          )}
        </div>
        {!initial.isSeed && (
          <DialogFooter>
            <Button variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button
              disabled={!name.trim() || isPending}
              onClick={() => onSave({ name: name.trim(), permissions: selectedPerms })}
            >
              Guardar
            </Button>
          </DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  )
}

function DeleteConfirm({
  role,
  onClose,
}: {
  role: Role | null
  onClose: () => void
}) {
  const { data: usersData, isLoading } = useRoleUsers(role?.id ?? null)
  const deleteRole = useDeleteRole()

  const userCount = usersData?.users.length ?? 0
  const canDelete = !isLoading && userCount === 0 && !role?.isSeed

  const description = role?.isSeed
    ? "Los roles del sistema no se pueden eliminar."
    : isLoading
      ? "Verificando usuarios asignados…"
      : userCount > 0
        ? `No se puede eliminar — ${userCount} ${userCount === 1 ? "usuario tiene" : "usuarios tienen"} este role asignado.`
        : `¿Eliminar el role "${role?.name}"? Esta acción no se puede deshacer.`

  return (
    <AlertDialog open={role !== null} onOpenChange={(o) => { if (!o) onClose() }}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Eliminar role</AlertDialogTitle>
          <AlertDialogDescription>
            {description}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            disabled={!canDelete}
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90 disabled:opacity-50"
            onClick={() => {
              if (!role) return
              deleteRole.mutate(role.id, {
                onSuccess: () => {
                  toast.success("Role eliminado")
                  onClose()
                },
                onError: (err) => {
                  toast.error(err.message)
                  onClose()
                },
              })
            }}
          >
            Eliminar
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}

export default function RolesPage() {
  const { data: rolesData, isLoading } = useRoles()
  const { data: catalogData } = usePermissionCatalog()
  const createRole = useCreateRole()
  const updateRole = useUpdateRole()

  const [dialogState, setDialogState] = React.useState<{
    open: boolean
    role: Role | null
  }>({ open: false, role: null })

  const [deleteTarget, setDeleteTarget] = React.useState<Role | null>(null)

  const roles = rolesData?.roles ?? []
  const groups = catalogData?.groups ?? {}

  function openCreate() {
    setDialogState({ open: true, role: null })
  }

  function openEdit(role: Role) {
    setDialogState({ open: true, role })
  }

  function handleSave(state: RoleFormState) {
    if (dialogState.role) {
      updateRole.mutate(
        { id: dialogState.role.id, name: state.name, permissions: state.permissions },
        {
          onSuccess: () => {
            toast.success("Role actualizado")
            setDialogState({ open: false, role: null })
          },
          onError: (err) => toast.error(err.message),
        },
      )
    } else {
      createRole.mutate(
        { name: state.name, permissions: state.permissions },
        {
          onSuccess: () => {
            toast.success("Role creado")
            setDialogState({ open: false, role: null })
          },
          onError: (err) => toast.error(err.message),
        },
      )
    }
  }

  const columns = React.useMemo<ColumnDef<Role, unknown>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => (
          <span className="font-medium">{row.original.name}</span>
        ),
      },
      {
        id: "permsCount",
        header: "Permisos",
        cell: ({ row }) => (
          <Badge variant="secondary">
            {row.original.permissions.length}{" "}
            {row.original.permissions.length === 1 ? "permiso" : "permisos"}
          </Badge>
        ),
      },
      {
        id: "type",
        header: "Tipo",
        cell: ({ row }) =>
          row.original.isSeed ? (
            <Badge variant="outline">Sistema</Badge>
          ) : (
            <Badge variant="secondary">Custom</Badge>
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
              {row.original.isSeed ? "Ver" : "Editar"}
            </Button>
            {!row.original.isSeed && (
              <Button
                variant="ghost"
                size="sm"
                className="text-destructive hover:text-destructive"
                onClick={() => setDeleteTarget(row.original)}
              >
                <Trash2 className="size-4 mr-1.5" />
                Eliminar
              </Button>
            )}
          </div>
        ),
      },
    ],
    [],
  )

  const initialForm: RoleFormState & { isSeed?: boolean } = dialogState.role
    ? {
        name: dialogState.role.name,
        permissions: dialogState.role.permissions,
        isSeed: dialogState.role.isSeed,
      }
    : { name: "", permissions: [], isSeed: false }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Roles y permisos</h1>
          <p className="text-sm text-muted-foreground">
            Gestioná los roles del equipo y los permisos que cada uno otorga.
          </p>
        </div>
        <Button onClick={openCreate}>
          <Plus className="size-4 mr-1.5" />
          Nuevo role
        </Button>
      </header>

      {roles.length === 0 && !isLoading ? (
        <EmptyState
          icon={ShieldCheck}
          title="Sin roles configurados"
          description="Creá roles para controlar qué puede hacer cada integrante del equipo."
          actions={
            <Button size="sm" onClick={openCreate}>
              <Plus className="size-4 mr-1.5" />
              Nuevo role
            </Button>
          }
        />
      ) : (
        <DataTable
          tableId="roles"
          columns={columns}
          data={roles}
          isLoading={isLoading}
          searchPlaceholder="Buscar role..."
          exportFileName={null}
        />
      )}

      <RoleDialog
        open={dialogState.open}
        onOpenChange={(o) => setDialogState((s) => ({ ...s, open: o }))}
        initial={initialForm}
        groups={groups}
        onSave={handleSave}
        isPending={createRole.isPending || updateRole.isPending}
      />

      <DeleteConfirm
        role={deleteTarget}
        onClose={() => setDeleteTarget(null)}
      />
    </div>
  )
}
