"use client"

import * as React from "react"
import { Plus, Loader2, Users, Shield, CircleOff } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"
import type { CountryCode } from "libphonenumber-js"
import { toast } from "sonner"
import { z } from "zod"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"

import { cn } from "@/lib/utils"
import { HOTKEY_COLORS } from "@/lib/hotkeys/store"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import { PhoneInput } from "@/components/forms/phone-input"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { FormSection } from "@/components/forms/form-section"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { Input } from "@/components/ui/input"
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp"
import { Switch } from "@/components/ui/switch"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

import {
  useTeamMembers,
  useTeamRoles,
  useCreateTeamMember,
  useUpdateTeamMember,
  type TeamMember,
  type TeamMemberFormValues,
} from "@/hooks/use-team"
import { useOutlets } from "@/hooks/use-outlets"

// ── schema ─────────────────────────────────────────────────────────────────

const teamSchema = z
  .object({
    name:             z.string().min(1, "El nombre es obligatorio"),
    email:            z.union([z.string().email("Email inválido"), z.literal("")]),
    phone:            z.string(),
    password:         z.string(),
    roleId:           z.string(),
    outletId:         z.string(),
    lockPass:         z.string().refine((v) => v === "" || /^\d{4}$/.test(v), {
      message: "El código POS debe tener 4 dígitos",
    }),
    inCalendar:       z.boolean(),
    color:            z.string(),
    status:           z.enum(["1", "0"]),
  })
  .superRefine((data, ctx) => {
    // password requerido solo en create (detectado en el form por isEdit)
    // La validación de "required en create" se hace en el submit handler
    // porque zod no tiene acceso al contexto isEdit.
    void data; void ctx
  })

type TeamFormValues = z.infer<typeof teamSchema>

const NONE = "__none__"

function emptyValues(): TeamFormValues {
  return {
    name:       "",
    email:      "",
    phone:      "",
    password:   "",
    roleId:     NONE,
    outletId:   NONE,
    lockPass:   "",
    inCalendar: false,
    color:      "",
    status:     "1",
  }
}

function memberToForm(m: TeamMember): TeamFormValues {
  return {
    name:       m.name ?? "",
    email:      m.email ?? "",
    phone:      m.phone ?? "",
    password:   "",
    roleId:     m.roleId ?? NONE,
    outletId:   m.outletId ?? NONE,
    lockPass:   m.lockPass ?? "",
    inCalendar: m.inCalendar,
    color:      m.color ?? "",
    status:     m.status === 1 ? "1" : "0",
  }
}

// ── helpers visuales ───────────────────────────────────────────────────────

function initials(name: string) {
  return name
    .split(" ")
    .map((w) => w[0])
    .slice(0, 2)
    .join("")
    .toUpperCase()
}

function avatarStyle(color: string | null) {
  if (!color) return {}
  const hex = color.startsWith("#") ? color : `#${color}`
  return { backgroundColor: hex + "33", color: hex }
}

// ── columnas ───────────────────────────────────────────────────────────────

function buildColumns(
  onEdit: (m: TeamMember) => void,
): ColumnDef<TeamMember, unknown>[] {
  return [
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => {
        const m = row.original
        return (
          <div className="flex items-center gap-3">
            <Avatar className="size-8 shrink-0">
              <AvatarFallback className="text-xs" style={avatarStyle(m.color)}>
                {initials(m.name ?? "?")}
              </AvatarFallback>
            </Avatar>
            <div className="flex flex-col min-w-0">
              <span className="font-medium truncate">{m.name}</span>
              {m.email && (
                <span className="text-xs text-muted-foreground truncate">{m.email}</span>
              )}
            </div>
          </div>
        )
      },
    },
    {
      accessorKey: "roleName",
      header: "Rol",
      cell: ({ row }) => {
        const name = row.original.roleName
        return name ? (
          <Badge variant="secondary" className="gap-1">
            <Shield className="size-3" />
            {name}
          </Badge>
        ) : (
          <span className="text-muted-foreground text-xs">Sin rol</span>
        )
      },
    },
    {
      accessorKey: "lockPass",
      header: "Código POS",
      cell: ({ row }) =>
        row.original.lockPass ? (
          <span className="font-mono tabular-nums">{row.original.lockPass}</span>
        ) : (
          <span className="text-muted-foreground text-xs">—</span>
        ),
      meta: { className: "tabular-nums" },
    },
    {
      accessorKey: "outletName",
      header: "Sucursal",
      cell: ({ row }) =>
        row.original.outletName ?? (
          <span className="text-muted-foreground text-xs">Todas</span>
        ),
    },
    {
      accessorKey: "phone",
      header: "Teléfono",
      cell: ({ row }) =>
        row.original.phone ?? <span className="text-muted-foreground text-xs">—</span>,
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ row }) =>
        row.original.status === 1 ? (
          <Badge variant="outline" className="gap-1.5">
            <span className="size-1.5 rounded-full bg-[var(--chart-1)]" />
            Activo
          </Badge>
        ) : (
          <Badge variant="outline" className="gap-1 text-muted-foreground">
            <CircleOff className="size-3" />
            Inactivo
          </Badge>
        ),
    },
  ]
}

// ── formulario ─────────────────────────────────────────────────────────────

function TeamForm({
  isEdit,
  form,
  roles,
  outlets,
  isPending,
  onSubmit,
}: {
  isEdit: boolean
  form: ReturnType<typeof useForm<TeamFormValues>>
  roles: { id: string; name: string }[]
  outlets: { id: string; name: string }[]
  isPending: boolean
  onSubmit: (v: TeamFormValues) => void
}) {
  return (
    <Form {...form}>
      <form
        id="team-form"
        onSubmit={form.handleSubmit(onSubmit)}
        className="flex flex-col gap-6"
      >
        <FormSection title="Datos personales">
          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Nombre completo <span className="text-destructive">*</span></FormLabel>
                <FormControl>
                  <Input placeholder="Ana García" {...field} />
                </FormControl>
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
                <FormControl>
                  <Input type="email" placeholder="ana@empresa.com" {...field} />
                </FormControl>
                <FormDescription className="text-xs">
                  Necesario para recuperación de contraseña.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="phone"
            render={({ field, fieldState }) => (
              <FormItem>
                <FormLabel>Teléfono</FormLabel>
                <FormControl>
                  <PhoneInput
                    value={field.value}
                    country={DEFAULT_COUNTRY as CountryCode}
                    onChange={(v) => field.onChange(v.e164 ?? v.value)}
                    onBlur={field.onBlur}
                    aria-invalid={!!fieldState.error}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="color"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Color</FormLabel>
                <div className="flex items-center gap-3">
                  <FormControl>
                    <div className="flex items-center gap-2">
                      {HOTKEY_COLORS.map((c) => (
                        <button
                          key={c.key}
                          type="button"
                          onClick={() => field.onChange(c.bg)}
                          className={cn(
                            "size-7 rounded-full transition-all",
                            field.value === c.bg
                              ? "ring-2 ring-foreground ring-offset-2 ring-offset-background"
                              : "hover:scale-110",
                          )}
                          style={{ backgroundColor: c.bg }}
                          aria-label={`Color ${c.key}`}
                        />
                      ))}
                    </div>
                  </FormControl>
                  <span className="text-xs text-muted-foreground">
                    Aparece en la agenda y en el avatar.
                  </span>
                </div>
                <FormMessage />
              </FormItem>
            )}
          />
        </FormSection>

        <FormSection title="Acceso">
          <FormField
            control={form.control}
            name="password"
            render={({ field }) => (
              <FormItem>
                <FormLabel>
                  Contraseña {!isEdit && <span className="text-destructive">*</span>}
                </FormLabel>
                <FormControl>
                  <Input
                    type="password"
                    placeholder={isEdit ? "Dejar vacío para no cambiar" : "Mínimo 6 caracteres"}
                    autoComplete="new-password"
                    {...field}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="lockPass"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Código POS</FormLabel>
                <FormControl>
                  <InputOTP
                    maxLength={4}
                    value={field.value}
                    onChange={field.onChange}
                    inputMode="numeric"
                    pattern="^[0-9]*$"
                  >
                    <InputOTPGroup>
                      <InputOTPSlot index={0} />
                      <InputOTPSlot index={1} />
                      <InputOTPSlot index={2} />
                      <InputOTPSlot index={3} />
                    </InputOTPGroup>
                  </InputOTP>
                </FormControl>
                <FormDescription className="text-xs">
                  Código de 4 dígitos para desbloquear la pantalla de la caja.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
        </FormSection>

        <FormSection title="Rol y acceso">
          <FormField
            control={form.control}
            name="roleId"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Rol</FormLabel>
                <Select onValueChange={field.onChange} value={field.value}>
                  <FormControl>
                    <SelectTrigger className="w-full">
                      <SelectValue placeholder="Seleccionar rol…" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectItem value="__none__">Sin rol asignado</SelectItem>
                    {roles.map((r) => (
                      <SelectItem key={r.id} value={r.id}>
                        {r.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="outletId"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Sucursal asignada</FormLabel>
                <Select onValueChange={field.onChange} value={field.value}>
                  <FormControl>
                    <SelectTrigger className="w-full">
                      <SelectValue placeholder="Todas las sucursales" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectItem value="__none__">Todas las sucursales</SelectItem>
                    {outlets.map((o) => (
                      <SelectItem key={o.id} value={o.id}>
                        {o.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormDescription className="text-xs">
                  Vacío = acceso a todas las sucursales.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="status"
            render={({ field }) => (
              <FormItem className="flex items-center justify-between rounded-md border p-3">
                <div>
                  <FormLabel className="text-sm">Usuario activo</FormLabel>
                  <FormDescription className="text-xs">
                    Los usuarios inactivos no pueden ingresar al sistema.
                  </FormDescription>
                </div>
                <FormControl>
                  <Switch
                    checked={field.value === "1"}
                    onCheckedChange={(v) => field.onChange(v ? "1" : "0")}
                  />
                </FormControl>
              </FormItem>
            )}
          />
        </FormSection>

        <FormSection title="Funciones">
          <FormField
            control={form.control}
            name="inCalendar"
            render={({ field }) => (
              <FormItem className="flex items-center justify-between rounded-md border p-3">
                <div>
                  <FormLabel className="text-sm">Visible en agenda</FormLabel>
                  <FormDescription className="text-xs">
                    Aparece en la agenda de citas y puede recibir agendamientos.
                  </FormDescription>
                </div>
                <FormControl>
                  <Switch checked={field.value} onCheckedChange={field.onChange} />
                </FormControl>
              </FormItem>
            )}
          />
        </FormSection>

        <div className="pt-2">
          <Button type="submit" className="w-full" disabled={isPending}>
            {isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
            {isEdit ? "Guardar cambios" : "Crear usuario"}
          </Button>
        </div>
      </form>
    </Form>
  )
}

// ── sección ─────────────────────────────────────────────────────────────────

export function TeamSection() {
  const { data, isLoading } = useTeamMembers()
  const { data: rolesData } = useTeamRoles()
  const { data: outletsData } = useOutlets()
  const create = useCreateTeamMember()
  const update = useUpdateTeamMember()
  const [sheetOpen, setSheetOpen] = React.useState(false)
  const [editing, setEditing] = React.useState<TeamMember | null>(null)

  const form = useForm<TeamFormValues>({
    resolver: zodResolver(teamSchema),
    defaultValues: emptyValues(),
  })

  const roles   = rolesData?.roles   ?? []
  const outlets = outletsData?.rows ?? []

  function openCreate() {
    setEditing(null)
    form.reset(emptyValues())
    setSheetOpen(true)
  }

  function openEdit(m: TeamMember) {
    setEditing(m)
    form.reset(memberToForm(m))
    setSheetOpen(true)
  }

  async function onSubmit(values: TeamFormValues) {
    const isEdit = !!editing

    if (!isEdit && !values.password) {
      form.setError("password", { message: "La contraseña es obligatoria" })
      return
    }

    try {
      const payload: TeamMemberFormValues = { ...values }
      if (isEdit) {
        await update.mutateAsync({ id: editing!.id, values: payload })
        toast.success("Usuario actualizado")
      } else {
        await create.mutateAsync(payload)
        toast.success("Usuario creado")
      }
      setSheetOpen(false)
    } catch (e) {
      toast.error(isEdit ? "No se pudo actualizar el usuario" : "No se pudo crear el usuario", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const members = data?.users ?? []
  const isPending = create.isPending || update.isPending

  const columns = buildColumns(openEdit)

  return (
    <div className="flex flex-col gap-6">
      {/* Header */}
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-lg font-semibold">Equipo</h1>
          <p className="text-sm text-muted-foreground">
            Usuarios con acceso al panel y la caja.
          </p>
        </div>
        <Button size="sm" onClick={openCreate}>
          <Plus className="mr-1.5 size-4" />
          Nuevo usuario
        </Button>
      </div>

      {/* Tabla */}
      {isLoading ? (
        <div className="flex h-48 items-center justify-center">
          <Loader2 className="size-5 animate-spin text-muted-foreground" />
        </div>
      ) : members.length === 0 ? (
        <EmptyState
          icon={Users}
          title="Sin usuarios en el equipo"
          description="Creá el primer usuario para que pueda acceder al panel o a la caja."
          actions={
            <Button size="sm" onClick={openCreate}>
              <Plus className="mr-1.5 size-4" />
              Nuevo usuario
            </Button>
          }
        />
      ) : (
        <DataTable
          tableId="team"
          columns={columns}
          data={members}
          getRowId={(m) => m.id}
          searchPlaceholder="Buscar por nombre…"
          exportFileName="equipo"
          onRowClick={(m) => openEdit(m)}
        />
      )}

      {/* Dialog crear/editar — modal centrado, NO drawer lateral */}
      <Dialog open={sheetOpen} onOpenChange={setSheetOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? "Editar usuario" : "Nuevo usuario"}</DialogTitle>
            <DialogDescription>
              {editing
                ? `Modificando datos de ${editing.name}.`
                : "Completá los datos del nuevo integrante del equipo."}
            </DialogDescription>
          </DialogHeader>
          <TeamForm
            isEdit={!!editing}
            form={form}
            roles={roles}
            outlets={outlets}
            isPending={isPending}
            onSubmit={onSubmit}
          />
        </DialogContent>
      </Dialog>

    </div>
  )
}
