"use client"

/**
 * Alta y edición del USUARIO de una persona: su credencial, su rol, sus
 * sucursales y su código de caja.
 *
 * Vivía dentro de `TeamSection`, la pestaña Equipo de Contactos. Cuando Equipo
 * y Empleados se unificaron en `/employees` (context/83 §9), esa pestaña
 * desapareció y el diálogo pasó a tener tres llamadores —el listado, la pestaña
 * Acceso de la ficha y el alta— así que vive acá, solo.
 *
 * Es la credencial, NO el legajo: el sueldo, el puesto y el horario son otra
 * cosa y se editan en la ficha. Los permisos tampoco se editan acá — cuelgan
 * del ROL, que se arma en Ajustes › Roles.
 */

import * as React from "react"
import { Loader2 } from "lucide-react"
import { toast } from "sonner"
import { z } from "zod"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"

import { formatPhone } from "@/lib/phone"
import { useTenantPhoneCountry } from "@/hooks/use-tenant-phone-country"
import { PhoneInput } from "@/components/forms/phone-input"
import { ColorPicker } from "@/components/ui/color-picker"
import { Button } from "@/components/ui/button"
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
import { PasswordInput } from "@/components/ui/password-input"
import { PinInput } from "@/components/ui/pin-input"
import { Switch } from "@/components/ui/switch"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { MultiSelect } from "@/components/ui/multi-select"

import {
  useCreateTeamMember,
  useUpdateTeamMember,
  type TeamMember,
  type TeamMemberFormValues,
} from "@/hooks/use-team"
import { useRoles } from "@/hooks/use-roles"
import { useOutlets } from "@/hooks/use-outlets"
import { useModules } from "@/hooks/use-modules"

// ── schema ─────────────────────────────────────────────────────────────────

const teamSchema = z.object({
  name: z.string().min(1, "El nombre es obligatorio"),
  email: z.union([z.string().email("Email inválido"), z.literal("")]),
  phone: z.string(),
  password: z.string(),
  roleId: z.string(),
  outletIds: z.array(z.string()),
  lockPass: z.string().refine((v) => v === "" || /^\d{4}$/.test(v), {
    message: "El código POS debe tener 4 dígitos",
  }),
  inCalendar: z.boolean(),
  color: z.string(),
  status: z.enum(["1", "0"]),
})

type TeamFormValues = z.infer<typeof teamSchema>

const NONE = "__none__"

function emptyValues(): TeamFormValues {
  return {
    name: "",
    email: "",
    phone: "",
    password: "",
    roleId: NONE,
    outletIds: [],
    lockPass: "",
    inCalendar: false,
    color: "",
    status: "1",
  }
}

function memberToForm(m: TeamMember): TeamFormValues {
  return {
    name: m.name ?? "",
    email: m.email ?? "",
    phone: formatPhone(m.phone),
    password: "",
    roleId: m.roleId ?? NONE,
    outletIds: m.outletIds ?? [],
    lockPass: m.lockPass ?? "",
    inCalendar: m.inCalendar,
    color: m.color ?? "",
    status: m.status === 1 ? "1" : "0",
  }
}

// ── diálogo ────────────────────────────────────────────────────────────────

export function UserFormDialog({
  open,
  member,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  /** `null` = alta. */
  member: TeamMember | null
  onOpenChange: (open: boolean) => void
  /** El usuario recién creado, para encadenar el alta del legajo. */
  onCreated?: (created: TeamMember) => void
}) {
  const { data: rolesData } = useRoles()
  const { data: outletsData } = useOutlets()
  const create = useCreateTeamMember()
  const update = useUpdateTeamMember()

  const roles = rolesData?.roles ?? []
  const outlets = outletsData?.rows ?? []

  const form = useForm<TeamFormValues>({
    resolver: zodResolver(teamSchema),
    defaultValues: emptyValues(),
  })

  // Se rellena al ABRIR: resetear en cada render pisa lo que se está tipeando.
  React.useEffect(() => {
    if (!open) return
    form.reset(member ? memberToForm(member) : emptyValues())
  }, [open, member, form])

  const isEdit = member !== null
  const isPending = create.isPending || update.isPending

  async function onSubmit(values: TeamFormValues) {
    if (!isEdit && !values.password) {
      form.setError("password", { message: "La contraseña es obligatoria" })
      return
    }

    try {
      const payload: TeamMemberFormValues = { ...values }
      if (isEdit) {
        // `originalRoleId`: el rol con el que se abrió la ficha. Sin esto el
        // PUT afirma un cambio de rol que nadie hizo (403 al editar tu propia
        // ficha, y borrado silencioso del rol ajeno) — ver `serialize`.
        await update.mutateAsync({
          id: member.id,
          values: payload,
          originalRoleId: member.roleId ?? NONE,
        })
        toast.success("Usuario actualizado")
      } else {
        const created = await create.mutateAsync(payload)
        toast.success("Usuario creado")
        onCreated?.(created)
      }
      onOpenChange(false)
    } catch (e) {
      toast.error(isEdit ? "No se pudo actualizar el usuario" : "No se pudo crear el usuario", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Editar usuario" : "Nuevo usuario"}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? `Modificando datos de ${member.name}.`
              : "Completá los datos del nuevo integrante del equipo."}
          </DialogDescription>
        </DialogHeader>
        <UserForm
          isEdit={isEdit}
          form={form}
          roles={roles}
          outlets={outlets}
          isPending={isPending}
          onSubmit={onSubmit}
        />
      </DialogContent>
    </Dialog>
  )
}

// ── formulario ─────────────────────────────────────────────────────────────

function UserForm({
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
  const tenantPhoneCountry = useTenantPhoneCountry()

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
                <FormLabel>
                  Nombre completo <span className="text-destructive">*</span>
                </FormLabel>
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
                    country={tenantPhoneCountry}
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
                    <ColorPicker value={field.value} onChange={field.onChange} allowNone />
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
                  <PasswordInput
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
                  <PinInput value={field.value} onChange={field.onChange} />
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
                    <SelectItem value={NONE}>Sin rol asignado</SelectItem>
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
            render={({ field }) => (
              <FormItem>
                <FormLabel>Sucursales asignadas</FormLabel>
                <FormControl>
                  <MultiSelect
                    value={field.value ?? []}
                    onChange={field.onChange}
                    options={outlets}
                    emptyMeansAll
                    emptyMeansAllLabel="Todas las sucursales"
                    searchPlaceholder="Buscar sucursal…"
                    unitLabels={["sucursal", "sucursales"]}
                  />
                </FormControl>
                <FormDescription className="text-xs">
                  Sin selección = acceso a todas las sucursales.
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
