"use client"

import * as React from "react"
import { Plus, Loader2, Users, Shield, CircleOff, ChevronsUpDown, Check } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"
import type { CountryCode } from "libphonenumber-js"
import { toast } from "sonner"
import { z } from "zod"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"

import { formatPhone } from "@/lib/phone"
import { resolveColorBg } from "@/lib/ui/color-palette"
import { ColorPicker } from "@/components/ui/color-picker"
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
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Checkbox } from "@/components/ui/checkbox"

import {
  useTeamMembers,
  useCreateTeamMember,
  useUpdateTeamMember,
  type TeamMember,
  type TeamMemberFormValues,
} from "@/hooks/use-team"
import { useRoles } from "@/hooks/use-roles"
import { useOutlets } from "@/hooks/use-outlets"
import { useModules } from "@/hooks/use-modules"

// ── schema ─────────────────────────────────────────────────────────────────

const teamSchema = z
  .object({
    name:             z.string().min(1, "El nombre es obligatorio"),
    email:            z.union([z.string().email("Email inválido"), z.literal("")]),
    phone:            z.string(),
    password:         z.string(),
    roleId:           z.string(),
    outletIds:        z.array(z.string()),
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
    outletIds:  [],
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
    phone:      formatPhone(m.phone),
    password:   "",
    roleId:     m.roleId ?? NONE,
    outletIds:  m.outletIds ?? [],
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
  // resolveColorBg cubre tanto la convención nueva (key: "amber") como los
  // valores legacy que guardaban el hex directo.
  const hex = resolveColorBg(color)
  if (!hex) return {}
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
      accessorKey: "outletNames",
      header: "Sucursal",
      cell: ({ row }) => {
        const names = row.original.outletNames ?? []
        if (names.length === 0) {
          return <span className="text-muted-foreground text-xs">Todas</span>
        }
        if (names.length === 1) {
          return <span>{names[0]}</span>
        }
        return (
          <Badge
            variant="secondary"
            title={names.join(", ")}
          >
            {names.length} sucursales
          </Badge>
        )
      },
    },
    {
      accessorKey: "phone",
      header: "Teléfono",
      cell: ({ row }) => {
        const p = formatPhone(row.original.phone)
        return p ? p : <span className="text-muted-foreground text-xs">—</span>
      },
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
  // Gate SOLO de UI: mientras el módulo "calendar" está apagado (o cargando,
  // criterio conservador) se oculta la sección "Funciones" — pero el campo
  // sigue registrado en el form (shouldUnregister=false por default de RHF),
  // así que `inCalendar` viaja intacto en el submit aunque no se edite acá.
  // Evita perder el dato de tenants que ya tenían usuarios con inCalendar=true.
  const { data: modules, isLoading: modulesLoading } = useModules()
  const calendarEnabled = !modulesLoading && modules?.calendar?.enabled === true

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
                    <ColorPicker
                      value={field.value}
                      onChange={field.onChange}
                      allowNone
                    />
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
                    <SelectTrigger>
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
            name="outletIds"
            render={({ field }) => {
              const selected: string[] = field.value ?? []
              const [open, setOpen] = React.useState(false)

              function toggle(id: string) {
                const next = selected.includes(id)
                  ? selected.filter((v) => v !== id)
                  : [...selected, id]
                field.onChange(next)
              }

              const triggerLabel = selected.length === 0
                ? null
                : selected.length === 1
                  ? outlets.find((o) => o.id === selected[0])?.name ?? "1 sucursal"
                  : `${selected.length} sucursales`

              return (
                <FormItem>
                  <FormLabel>Sucursales asignadas</FormLabel>
                  <Popover open={open} onOpenChange={setOpen}>
                    <PopoverTrigger asChild>
                      <FormControl>
                        <Button
                          variant="outline"
                          role="combobox"
                          className="w-full justify-between font-normal"
                        >
                          <span className={triggerLabel ? undefined : "text-muted-foreground"}>
                            {triggerLabel ?? "Todas las sucursales"}
                          </span>
                          <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
                        </Button>
                      </FormControl>
                    </PopoverTrigger>
                    <PopoverContent className="w-full p-0" align="start">
                      <Command>
                        <CommandInput placeholder="Buscar sucursal…" />
                        <CommandList>
                          <CommandEmpty>Sin resultados.</CommandEmpty>
                          <CommandGroup>
                            {outlets.map((o) => {
                              const checked = selected.includes(o.id)
                              return (
                                <CommandItem
                                  key={o.id}
                                  value={o.name}
                                  onSelect={() => toggle(o.id)}
                                  data-checked={checked}
                                >
                                  <Checkbox
                                    checked={checked}
                                    className="mr-2"
                                    aria-hidden
                                    tabIndex={-1}
                                  />
                                  {o.name}
                                  {checked && <Check className="ml-auto size-4" />}
                                </CommandItem>
                              )
                            })}
                          </CommandGroup>
                        </CommandList>
                      </Command>
                    </PopoverContent>
                  </Popover>
                  {selected.length > 1 && (
                    <div className="flex flex-wrap gap-1 pt-1">
                      {selected.map((id) => {
                        const name = outlets.find((o) => o.id === id)?.name
                        return name ? (
                          <Badge key={id} variant="secondary" className="text-xs">
                            {name}
                          </Badge>
                        ) : null
                      })}
                    </div>
                  )}
                  <FormDescription className="text-xs">
                    Sin selección = acceso a todas las sucursales.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )
            }}
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

        {calendarEnabled && (
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
        )}

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

export function TeamSection({ openCreateRef }: { openCreateRef?: React.RefObject<(() => void) | null> }) {
  const { data, isLoading } = useTeamMembers()
  const { data: rolesData } = useRoles()
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

  const openCreate = React.useCallback(() => {
    setEditing(null)
    form.reset(emptyValues())
    setSheetOpen(true)
  }, [form])

  React.useEffect(() => {
    if (openCreateRef) openCreateRef.current = openCreate
  }, [openCreateRef, openCreate])

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
