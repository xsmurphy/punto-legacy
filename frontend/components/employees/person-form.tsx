"use client"

/**
 * El formulario ÚNICO de una persona del comercio.
 *
 * Antes eran dos: uno para la credencial (nombre, rol, código, sucursales) y
 * otro para los datos laborales (documento, puesto, sueldo, sucursal OTRA VEZ).
 * El nombre se pedía dos veces y la sucursal también. Ahora es uno solo, con
 * secciones, y se usa en los dos lugares donde se edita una persona: inline en
 * la pestaña Datos de su ficha y dentro del diálogo de alta desde Equipo.
 *
 * Es el MISMO componente en los dos lados, no dos copias: un campo que se suma
 * acá aparece en el alta y en la edición sin que nadie tenga que acordarse.
 *
 * ── Qué se guarda dónde ─────────────────────────────────────────────────────
 *
 * Ver el docblock de `lib/employees/person-form.ts`: el formulario es uno, los
 * dueños del dato son dos (`/v1/users` y `/v1/employees`) y el submit escribe en
 * los dos, en orden, cada uno con su permiso. Si uno falla y el otro no, el
 * formulario no se resetea y el aviso dice qué quedó sin guardar.
 *
 * ── Permisos ────────────────────────────────────────────────────────────────
 *
 * Cada sección se deshabilita entera con `<fieldset disabled>` —el mecanismo del
 * navegador, no una prop por campo— y dice en una línea que no se puede editar.
 * Deshabilitada y visible, nunca escondida: quien no puede cambiar el sueldo
 * igual necesita verlo.
 */

import * as React from "react"
import Link from "next/link"
import { useForm, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { ArrowUpRight, Loader2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import { MoneyInput } from "@/components/ui/money-input"
import { MultiSelect } from "@/components/ui/multi-select"
import { PasswordInput } from "@/components/ui/password-input"
import { PinInput } from "@/components/ui/pin-input"
import { ColorPicker } from "@/components/ui/color-picker"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
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
import { DatePicker } from "@/components/date-picker"
import { FormSection } from "@/components/forms/form-section"
import { PhoneInput } from "@/components/forms/phone-input"

import { useModules } from "@/hooks/use-modules"
import { useOutlets } from "@/hooks/use-outlets"
import { useRoles } from "@/hooks/use-roles"
import { usePermission } from "@/hooks/use-permissions"
import { useTenantPhoneCountry } from "@/hooks/use-tenant-phone-country"
import {
  useCreateTeamMember,
  useUpdateTeamMember,
  type TeamMember,
} from "@/hooks/use-team"
import {
  useCreateEmployee,
  useUpdateEmployee,
  type Employee,
} from "@/hooks/use-employees"
import { formatPhone } from "@/lib/phone"
import {
  EMPTY_PERSON,
  NONE,
  PERIOD_LABEL,
  hasLegajoData,
  personSchema,
  toEmployeePayload,
  toUserPayload,
  type PersonFormValues,
} from "@/lib/employees/person-form"
import type { FixedPeriod } from "@/hooks/use-employees"

// ── valores ────────────────────────────────────────────────────────────────

/** Las dos filas de la persona, volcadas en los campos del formulario. */
export function toPersonFormValues(
  user: TeamMember | null,
  employee: Employee | null,
): PersonFormValues {
  return {
    name: user?.name ?? employee?.fullName ?? "",
    documentNumber: employee?.documentNumber ?? "",
    // Guardado en E.164 sin '+': en pantalla va en formato nacional.
    phone: formatPhone(user?.phone ?? employee?.phone ?? null),
    email: user?.email ?? employee?.email ?? "",
    address: employee?.address ?? "",
    birthDate: employee?.birthDate ?? "",
    color: user?.color ?? "",
    roleId: user?.roleId ?? NONE,
    password: "",
    lockPass: user?.lockPass ?? "",
    outletIds: user?.outletIds ?? [],
    active: user ? user.status === 1 : true,
    inCalendar: user?.inCalendar ?? false,
    jobTitle: employee?.jobTitle ?? "",
    hireDate: employee?.hireDate ?? "",
    notes: employee?.notes ?? "",
    fixedAmount: employee?.fixedAmount ?? null,
    fixedPeriod: employee?.fixedPeriod ?? "",
    hourlyRate: employee?.hourlyRate ?? null,
    commissions: employee?.commissions ?? false,
    schedule: employee?.schedule ?? null,
    biometricConsent: employee?.biometricConsentAt != null,
  }
}

export type PersonForm = UseFormReturn<PersonFormValues>

export interface PersonFormController {
  form: PersonForm
  /** `true` si todo lo que había que escribir se escribió. */
  submit: (values: PersonFormValues) => Promise<boolean>
  isPending: boolean
  canManageUsers: boolean
  canManageHr: boolean
}

// ── controlador ────────────────────────────────────────────────────────────

/**
 * El formulario y su guardado.
 *
 * `personId === null` es el alta: se crea el usuario y, si hay algún dato
 * laboral cargado, su ficha de trabajo detrás — sin pasos ni pantallas de por
 * medio.
 */
export function usePersonForm({
  personId,
  user,
  employee,
  onCreated,
}: {
  personId: string | null
  user: TeamMember | null
  employee: Employee | null
  /** El alta terminó: quien llama decide a dónde va. */
  onCreated?: (created: TeamMember) => void
}): PersonFormController {
  const canManageUsers = usePermission("contacts.user.manage")
  const canManageHr = usePermission("hr.employees.manage")

  const isCreate = personId === null
  const hasLegajo = employee !== null

  const createUser = useCreateTeamMember()
  const updateUser = useUpdateTeamMember()
  const createEmployee = useCreateEmployee()
  const updateEmployee = useUpdateEmployee()

  const schema = React.useMemo(
    () => personSchema({ hasLegajo, isCreate }),
    [hasLegajo, isCreate],
  )

  const form = useForm<PersonFormValues>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY_PERSON,
  })

  /**
   * Volcar las filas en el formulario, una vez por carga.
   *
   * Nunca sobre algo que se esté tipeando: las dos consultas (usuario y ficha
   * de trabajo) llegan por separado, y la segunda pisaría lo escrito entre una
   * y otra. `isDirty` es la señal exacta de eso, y por eso es la condición.
   */
  const appliedRef = React.useRef<string | null>(null)
  const isDirty = form.formState.isDirty
  React.useEffect(() => {
    const signature = `${personId ?? "new"}|${user?.id ?? "-"}|${employee?.id ?? "-"}`
    if (appliedRef.current === signature) return
    if (appliedRef.current !== null && isDirty) return
    appliedRef.current = signature
    form.reset(isCreate ? EMPTY_PERSON : toPersonFormValues(user, employee))
  }, [personId, user, employee, form, isCreate, isDirty])

  const submit = React.useCallback(
    async (values: PersonFormValues): Promise<boolean> => {
      // El alta pasa por el alta canónica de usuarios: es la que valida el
      // teléfono, el email repetido y el tope del plan, y la que aplica la
      // guarda de escalación de roles.
      if (isCreate) {
        if (!canManageUsers) return false
        let created: TeamMember
        try {
          created = await createUser.mutateAsync(toUserPayload(values))
        } catch (e) {
          toast.error("No se pudo agregar a la persona", {
            description: e instanceof Error ? e.message : undefined,
          })
          return false
        }
        if (canManageHr && hasLegajoData(values)) {
          try {
            await createEmployee.mutateAsync({
              ...toEmployeePayload(values, null),
              contactId: created.id,
            })
          } catch (e) {
            // La persona ya existe: decirlo, y no volver a crearla si
            // reintentan. Por eso se avisa y se da por terminada el alta.
            toast.error("Se agregó la persona, pero no se guardaron sus datos de trabajo", {
              description: e instanceof Error ? e.message : undefined,
            })
            onCreated?.(created)
            return true
          }
        }
        toast.success("Persona agregada")
        onCreated?.(created)
        return true
      }

      const id = personId
      let nextUser = user
      let nextEmployee = employee
      let userFailed: string | null = null
      let workFailed: string | null = null

      if (canManageUsers && user !== null) {
        try {
          nextUser = await updateUser.mutateAsync({
            id,
            values: toUserPayload(values),
            // El rol con el que se abrió la ficha: sin esto el PUT afirma un
            // cambio de rol que nadie hizo. Ver `serialize` en use-team.ts.
            originalRoleId: user.roleId ?? NONE,
          })
        } catch (e) {
          userFailed = e instanceof Error ? e.message : ""
        }
      }

      if (canManageHr) {
        const payload = toEmployeePayload(values, employee?.outletId ?? null)
        try {
          if (employee !== null) {
            nextEmployee = await updateEmployee.mutateAsync({ id, values: payload })
          } else if (hasLegajoData(values)) {
            // La fila de trabajo nace sola, en silencio: para quien la usa esto
            // es el perfil de la persona, no un trámite aparte.
            nextEmployee = await createEmployee.mutateAsync({
              ...payload,
              contactId: id,
            })
          }
        } catch (e) {
          workFailed = e instanceof Error ? e.message : ""
        }
      }

      if (userFailed !== null && workFailed !== null) {
        toast.error("No se pudo guardar", { description: userFailed || workFailed })
        return false
      }
      if (userFailed !== null) {
        toast.error("No se guardaron el nombre ni el acceso", {
          description: userFailed || undefined,
        })
        return false
      }
      if (workFailed !== null) {
        toast.error("No se guardaron los datos de trabajo", {
          description: workFailed || undefined,
        })
        return false
      }

      // Todo entró: recién acá se vuelve a volcar el formulario, y desde lo que
      // contestó el servidor — no desde lo tipeado.
      form.reset(toPersonFormValues(nextUser, nextEmployee))
      toast.success("Datos guardados")
      return true
    },
    [
      isCreate,
      personId,
      user,
      employee,
      canManageUsers,
      canManageHr,
      createUser,
      updateUser,
      createEmployee,
      updateEmployee,
      form,
      onCreated,
    ],
  )

  return {
    form,
    submit,
    isPending:
      createUser.isPending ||
      updateUser.isPending ||
      createEmployee.isPending ||
      updateEmployee.isPending,
    canManageUsers,
    canManageHr,
  }
}

// ── secciones ──────────────────────────────────────────────────────────────

/**
 * Una sección que se puede bloquear entera.
 *
 * `<fieldset disabled>` y no una prop `disabled` campo por campo: el navegador
 * ya sabe hacerlo, alcanza con los controles nativos y los botones de shadcn, y
 * no hay forma de olvidarse de un campo nuevo.
 */
function Section({
  title,
  locked,
  note,
  children,
}: {
  title: string
  locked: boolean
  /** Una línea, llana, de por qué no se puede editar. */
  note?: string
  children: React.ReactNode
}) {
  return (
    <FormSection title={title} description={locked ? note : undefined}>
      <fieldset
        disabled={locked}
        className="m-0 flex min-w-0 flex-col gap-4 border-0 p-0 disabled:opacity-60"
      >
        {children}
      </fieldset>
    </FormSection>
  )
}

const LOCKED_NOTE = "No tenés permiso para editar estos datos."

export function PersonFormSections({
  controller,
  /** El alta pide contraseña; la edición la deja vacía para no cambiarla. */
  isCreate,
  /** Link a Roles: en la ficha sí, en el diálogo de alta no. */
  showRolesLink = false,
}: {
  controller: PersonFormController
  isCreate: boolean
  showRolesLink?: boolean
}) {
  const { form, canManageUsers, canManageHr } = controller
  const { data: rolesData } = useRoles()
  const { data: outletsData } = useOutlets()
  const { data: modules, isLoading: modulesLoading } = useModules()
  const phoneCountry = useTenantPhoneCountry()

  const roles = rolesData?.roles ?? []
  const outlets = outletsData?.rows ?? []
  // Gate SOLO de UI: con el módulo apagado (o cargando, criterio conservador) la
  // casilla no se muestra, pero el campo sigue registrado y su valor viaja
  // intacto — no se le apaga la agenda a nadie por no haberla visto.
  const calendarEnabled = !modulesLoading && modules?.calendar?.enabled === true

  return (
    <>
      <Section
        title="Identidad"
        locked={!canManageUsers && !canManageHr}
        note={LOCKED_NOTE}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Nombre y apellido</FormLabel>
                <FormControl>
                  <Input autoFocus={isCreate} disabled={!canManageUsers} {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="documentNumber"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Documento</FormLabel>
                <FormControl>
                  <Input disabled={!canManageHr} {...field} />
                </FormControl>
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
                    country={phoneCountry}
                    disabled={!canManageUsers}
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
            name="email"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Email</FormLabel>
                <FormControl>
                  <Input type="email" disabled={!canManageUsers} {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="birthDate"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Fecha de nacimiento</FormLabel>
                <FormControl>
                  <DatePicker
                    value={field.value}
                    onChange={field.onChange}
                    captionLayout="dropdown"
                    disabled={!canManageHr}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="address"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Dirección</FormLabel>
                <FormControl>
                  <Input disabled={!canManageHr} {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </div>
        <FormField
          control={form.control}
          name="color"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Color</FormLabel>
              <FormControl>
                <ColorPicker value={field.value} onChange={field.onChange} allowNone />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>

      <Section title="Acceso" locked={!canManageUsers} note={LOCKED_NOTE}>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            control={form.control}
            name="roleId"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Rol</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue />
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
                <FormLabel>Sucursales</FormLabel>
                <FormControl>
                  <MultiSelect
                    value={field.value ?? []}
                    onChange={field.onChange}
                    options={outlets}
                    disabled={!canManageUsers}
                    emptyMeansAll
                    emptyMeansAllLabel="Todas las sucursales"
                    searchPlaceholder="Buscar sucursal…"
                    unitLabels={["sucursal", "sucursales"]}
                  />
                </FormControl>
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
                <FormControl>
                  <PasswordInput
                    placeholder={isCreate ? "Mínimo 6 caracteres" : "Dejar vacío para no cambiarla"}
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
                <FormLabel>Código de caja</FormLabel>
                <FormControl>
                  <PinInput
                    value={field.value}
                    onChange={field.onChange}
                    disabled={!canManageUsers}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </div>

        <FormField
          control={form.control}
          name="active"
          render={({ field }) => (
            <FormItem className="flex flex-row items-center justify-between gap-4 rounded-lg border p-4">
              <div className="flex flex-col gap-1">
                <FormLabel className="font-normal">Puede entrar al sistema</FormLabel>
                <FormDescription>
                  Sin esto, sus datos quedan pero no puede iniciar sesión.
                </FormDescription>
              </div>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />

        {calendarEnabled && (
          <FormField
            control={form.control}
            name="inCalendar"
            render={({ field }) => (
              <FormItem className="flex flex-row items-center justify-between gap-4 rounded-lg border p-4">
                <FormLabel className="font-normal">Aparece en la agenda</FormLabel>
                <FormControl>
                  <Switch checked={field.value} onCheckedChange={field.onChange} />
                </FormControl>
              </FormItem>
            )}
          />
        )}

        {showRolesLink && (
          <Button asChild variant="ghost" className="w-fit">
            <Link href="/settings/roles">
              Roles y permisos
              <ArrowUpRight className="size-4" />
            </Link>
          </Button>
        )}
      </Section>

      <Section title="Trabajo" locked={!canManageHr} note={LOCKED_NOTE}>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            control={form.control}
            name="jobTitle"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Puesto</FormLabel>
                <FormControl>
                  <Input {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="hireDate"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Fecha de ingreso</FormLabel>
                <FormControl>
                  <DatePicker
                    value={field.value}
                    onChange={field.onChange}
                    captionLayout="dropdown"
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </div>
        <FormField
          control={form.control}
          name="notes"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Notas</FormLabel>
              <FormControl>
                <Textarea rows={3} {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>

      <Section title="Remuneración" locked={!canManageHr} note={LOCKED_NOTE}>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            control={form.control}
            name="fixedAmount"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Monto fijo</FormLabel>
                <FormControl>
                  <MoneyInput value={field.value} onChange={field.onChange} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="fixedPeriod"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Cada</FormLabel>
                <Select
                  value={field.value || NONE}
                  onValueChange={(v) => field.onChange(v === NONE ? "" : v)}
                >
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Elegí el período" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectItem value={NONE}>Sin definir</SelectItem>
                    {(Object.keys(PERIOD_LABEL) as FixedPeriod[]).map((p) => (
                      <SelectItem key={p} value={p}>
                        {PERIOD_LABEL[p]}
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
            name="hourlyRate"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Tarifa por hora</FormLabel>
                <FormControl>
                  <MoneyInput value={field.value} onChange={field.onChange} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="commissions"
            render={({ field }) => (
              <FormItem className="flex flex-row items-center justify-between gap-4 rounded-lg border p-4">
                <FormLabel className="font-normal">Cobra comisiones</FormLabel>
                <FormControl>
                  <Switch checked={field.value} onCheckedChange={field.onChange} />
                </FormControl>
              </FormItem>
            )}
          />
        </div>
      </Section>
    </>
  )
}

// ── alta ───────────────────────────────────────────────────────────────────

/**
 * Alta desde Equipo: el MISMO formulario, en un diálogo.
 *
 * Un paso: se crea la persona y, si alguien cargó su puesto o su sueldo, eso
 * también — sin encadenar un segundo diálogo como antes.
 */
export function NewPersonDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreated?: (created: TeamMember) => void
}) {
  const controller = usePersonForm({
    personId: null,
    user: null,
    employee: null,
    onCreated,
  })
  const { form, submit, isPending } = controller

  // Se limpia al ABRIR, no en cada render: resetear en render pisa lo tipeado.
  React.useEffect(() => {
    if (open) form.reset(toPersonFormValues(null, null))
  }, [open, form])

  const onSubmit = async (values: PersonFormValues) => {
    if (await submit(values)) onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* `l` de la escala (context/14 §2.1): el form es largo y va en dos columnas. */}
      <DialogContent className="sm:max-w-4xl" sectioned>
        <DialogHeader>
          <DialogTitle>Nueva persona</DialogTitle>
          <DialogDescription>Sus datos, su acceso y su trabajo.</DialogDescription>
        </DialogHeader>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="contents">
            <DialogBody className="flex flex-col gap-6">
              <PersonFormSections controller={controller} isCreate />
            </DialogBody>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={isPending}
              >
                Cancelar
              </Button>
              <Button type="submit" disabled={isPending}>
                {isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Agregar
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
