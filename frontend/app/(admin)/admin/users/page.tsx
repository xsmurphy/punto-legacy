"use client"

import * as React from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Plus, Loader2, Users } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { DataTable } from "@/components/data-table/data-table"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { Input } from "@/components/ui/input"
import { Switch } from "@/components/ui/switch"
import { Label } from "@/components/ui/label"

import {
  useAdminUsers,
  useAdminCreateUser,
  useAdminUpdateUser,
  useAdminSetUserStatus,
  type AdminUserRow,
} from "@/hooks/use-admin"

// ── Schemas ───────────────────────────────────────────────────────────────────

const createSchema = z.object({
  email: z.string().email("Email inválido"),
  name: z.string().min(1, "Nombre requerido"),
  password: z.string().min(8, "Mínimo 8 caracteres"),
})

const updateSchema = z.object({
  email: z.string().email("Email inválido"),
  name: z.string().min(1, "Nombre requerido"),
  password: z.string().min(8, "Mínimo 8 caracteres").optional().or(z.literal("")),
})

type CreateValues = z.infer<typeof createSchema>
type UpdateValues = z.infer<typeof updateSchema>

// ── Create dialog ─────────────────────────────────────────────────────────────

function CreateAdminDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (v: boolean) => void }) {
  const createUser = useAdminCreateUser()
  const form = useForm<CreateValues>({
    resolver: zodResolver(createSchema),
    defaultValues: { email: "", name: "", password: "" },
  })

  const onSubmit = (values: CreateValues) => {
    createUser.mutate(values, {
      onSuccess: () => {
        toast.success("Administrador creado")
        form.reset()
        onOpenChange(false)
      },
      onError: (err) => toast.error(err.message ?? "Error"),
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Nuevo administrador</DialogTitle>
        </DialogHeader>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Nombre</FormLabel>
                  <FormControl><Input {...field} /></FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="email"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Email</FormLabel>
                  <FormControl><Input type="email" {...field} /></FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="password"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Contraseña</FormLabel>
                  <FormControl><Input type="password" {...field} /></FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancelar
              </Button>
              <Button type="submit" disabled={createUser.isPending}>
                {createUser.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Crear
              </Button>
            </div>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}

// ── Edit dialog ───────────────────────────────────────────────────────────────

function EditAdminDialog({
  admin,
  open,
  onOpenChange,
}: {
  admin: AdminUserRow
  open: boolean
  onOpenChange: (v: boolean) => void
}) {
  const updateUser = useAdminUpdateUser()
  const form = useForm<UpdateValues>({
    resolver: zodResolver(updateSchema),
    values: { email: admin.email, name: admin.name, password: "" },
  })

  const onSubmit = (values: UpdateValues) => {
    updateUser.mutate(
      {
        id: admin.adminId,
        email: values.email,
        name: values.name,
        password: values.password || undefined,
      },
      {
        onSuccess: () => {
          toast.success("Administrador actualizado")
          onOpenChange(false)
        },
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Editar administrador</DialogTitle>
        </DialogHeader>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Nombre</FormLabel>
                  <FormControl><Input {...field} /></FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="email"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Email</FormLabel>
                  <FormControl><Input type="email" {...field} /></FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="password"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Nueva contraseña (opcional)</FormLabel>
                  <FormControl>
                    <Input type="password" placeholder="Dejar en blanco para no cambiar" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancelar
              </Button>
              <Button type="submit" disabled={updateUser.isPending}>
                {updateUser.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Guardar
              </Button>
            </div>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function AdminUsersPage() {
  const { data, isLoading } = useAdminUsers()
  const setStatus = useAdminSetUserStatus()
  const [createOpen, setCreateOpen] = React.useState(false)
  const [editAdmin, setEditAdmin] = React.useState<AdminUserRow | null>(null)

  const rows = data?.rows ?? []

  const columns: ColumnDef<AdminUserRow, unknown>[] = [
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => (
        <div className="flex flex-col">
          <span className="font-medium">{row.original.name}</span>
          <span className="text-xs text-muted-foreground">{row.original.email}</span>
        </div>
      ),
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ row }) => {
        const admin = row.original
        const active = admin.status === 1
        return (
          <div className="flex items-center gap-2">
            <Switch
              checked={active}
              onCheckedChange={(checked) => {
                setStatus.mutate(
                  { id: admin.adminId, status: checked ? 1 : 0 },
                  {
                    onSuccess: () => toast.success(checked ? "Activado" : "Desactivado"),
                    onError: (err) => toast.error(err.message ?? "Error"),
                  },
                )
              }}
              disabled={setStatus.isPending}
            />
            <Badge variant={active ? "default" : "secondary"}>
              {active ? "Activo" : "Inactivo"}
            </Badge>
          </div>
        )
      },
      meta: { label: "Estado" },
    },
    {
      accessorKey: "lastLoginAt",
      header: "Último login",
      cell: ({ getValue }) => {
        const v = getValue() as string | null
        return v ? (
          <span className="text-sm tabular-nums text-muted-foreground">
            {new Date(v).toLocaleDateString("es-PY")}
          </span>
        ) : (
          <span className="opacity-40 text-sm">—</span>
        )
      },
      meta: { label: "Último login" },
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <Button
          variant="ghost"
          size="sm"
          onClick={(e) => {
            e.stopPropagation()
            setEditAdmin(row.original)
          }}
        >
          Editar
        </Button>
      ),
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Users className="size-5 text-muted-foreground" />
          <h1 className="text-2xl font-bold">Administradores</h1>
        </div>
        <Button onClick={() => setCreateOpen(true)} className="gap-2">
          <Plus className="size-4" />
          Nuevo admin
        </Button>
      </div>

      <DataTable
        tableId="admin-users"
        data={rows}
        columns={columns}
        isLoading={isLoading}
        getRowId={(r) => r.adminId}
        onRowClick={(r) => setEditAdmin(r)}
        searchPlaceholder="Buscar por nombre o email…"
        emptyMessage="Sin administradores"
      />

      <CreateAdminDialog open={createOpen} onOpenChange={setCreateOpen} />
      {editAdmin && (
        <EditAdminDialog
          admin={editAdmin}
          open={!!editAdmin}
          onOpenChange={(v) => { if (!v) setEditAdmin(null) }}
        />
      )}
    </div>
  )
}
