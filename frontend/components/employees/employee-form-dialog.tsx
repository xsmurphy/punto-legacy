"use client"

import * as React from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { toast } from "sonner"
import type { CountryCode } from "libphonenumber-js"

import { Button } from "@/components/ui/button"
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
import { Input } from "@/components/ui/input"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import { DatePicker } from "@/components/date-picker"
import { FormSection } from "@/components/forms/form-section"
import { PhoneInput } from "@/components/forms/phone-input"
import { Checkbox } from "@/components/ui/checkbox"
import { EmployeeAttachments } from "@/components/employees/employee-attachments"
import { EmployeeFaceField } from "@/components/employees/employee-face-field"
import { EmployeeScheduleField } from "@/components/employees/employee-schedule-field"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useOutlets } from "@/hooks/use-outlets"
import { useTeamMembers } from "@/hooks/use-team"
import {
  useCreateEmployee,
  useEmployees,
  useUpdateEmployee,
  type Employee,
  type EmployeeFormValues,
  type EmployeeSchedule,
  type FixedPeriod,
} from "@/hooks/use-employees"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import { formatPhoneForTenant } from "@/lib/phone"

/**
 * Alta y edición del legajo.
 *
 * El valor centinela `NONE` existe porque `<SelectItem>` de shadcn no admite
 * value="" (Radix lo usa para "sin selección" y borra el item). Se traduce a
 * `null` al enviar, que es lo que el backend entiende por "sin sucursal" /
 * "sin usuario vinculado".
 */
const NONE = "__none__"

/**
 * "Crear un usuario nuevo" en el selector de persona.
 *
 * Mismo motivo que `NONE` para existir como centinela: `<SelectItem>` no admite
 * value="". Se traduce a "no mandes `contactId`" al enviar, que es como el
 * backend entiende "creá la persona con estos datos".
 */
const NEW = "__new__"

const PERIOD_LABEL: Record<FixedPeriod, string> = {
  monthly: "Por mes",
  biweekly: "Por quincena",
  weekly: "Por semana",
}

/**
 * El horario es un objeto que arma `<EmployeeScheduleField>`, no campos del
 * form. `z.custom` y no un `z.object` detallado a propósito: la forma ya la
 * garantiza el componente que lo produce, el backend la NORMALIZA igual al
 * guardar (descarta días sin hora de entrada, recorta la tolerancia), y
 * duplicar acá esa validación sería dos definiciones de lo mismo que se
 * separan con el primer cambio.
 */
const scheduleSchema = z.custom<EmployeeSchedule | null>(() => true)

const schema = z
  .object({
    /**
     * A quién le corresponde este legajo (context/83 §9.1).
     *
     * `NEW` = se crea el usuario en el mismo alta, con el nombre/teléfono/email
     * de abajo. Un id = se le cuelga el legajo a alguien que ya existe.
     */
    contactId: z.string(),
    fullName: z.string(),
    documentNumber: z.string(),
    phone: z.string(),
    email: z.string(),
    address: z.string(),
    birthDate: z.string(),
    jobTitle: z.string(),
    hireDate: z.string().min(1, "La fecha de ingreso es requerida"),
    outletId: z.string(),
    fixedAmount: z.number().nullable(),
    fixedPeriod: z.string(),
    hourlyRate: z.number().nullable(),
    commissions: z.boolean(),
    notes: z.string(),
    schedule: scheduleSchema,
    /**
     * La persona aceptó identificarse con su rostro (F2).
     *
     * Es un dato del LEGAJO y se guarda con él, con fecha y autor. Sin esto
     * guardado, el servidor no deja habilitar la captura ni recibirla.
     */
    biometricConsent: z.boolean(),
  })
  // El monto fijo y su periodicidad son un solo dato. Se valida acá y no solo
  // en el backend para que el error salga en el campo, no en un toast.
  .refine((v) => v.fixedAmount === null || v.fixedPeriod !== "", {
    message: "Elegí cada cuánto se paga",
    path: ["fixedPeriod"],
  })
  // El nombre se pide solo cuando hay que CREAR la persona. Si se eligió a
  // alguien del equipo, su nombre es el que ya tiene.
  .refine((v) => v.contactId !== NEW || v.fullName.trim() !== "", {
    message: "El nombre es requerido",
    path: ["fullName"],
  })

type FormValues = z.infer<typeof schema>

const EMPTY: FormValues = {
  contactId: NEW,
  fullName: "",
  documentNumber: "",
  phone: "",
  email: "",
  address: "",
  birthDate: "",
  jobTitle: "",
  hireDate: "",
  outletId: NONE,
  fixedAmount: null,
  fixedPeriod: "",
  hourlyRate: null,
  commissions: false,
  notes: "",
  schedule: null,
  biometricConsent: false,
}

export function EmployeeFormDialog({
  open,
  employee,
  presetContactId,
  presetName,
  onOpenChange,
}: {
  open: boolean
  /** null = alta. */
  employee: Employee | null
  /**
   * La persona ya está decidida: se le carga el legajo a ESTE usuario y el
   * selector no se muestra. Es el camino desde su propia ficha, donde elegir a
   * otro sería cargarle el legajo a quien no se está mirando.
   */
  presetContactId?: string
  presetName?: string
  onOpenChange: (open: boolean) => void
}) {
  const { data: bootstrap } = useBootstrap()
  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []
  const { data: teamData } = useTeamMembers()
  // Los que YA tienen legajo no se ofrecen: el backend los rechaza igual ("esa
  // persona ya tiene un legajo cargado"), pero ofrecerlos y después explicar el
  // error es hacerle recorrer el formulario entero para nada.
  //
  // `includeArchived` porque el índice único no distingue: un legajo archivado
  // sigue ocupando a esa persona hasta que alguien lo borre.
  const { data: existing } = useEmployees({ state: "all", includeArchived: true })
  const taken = React.useMemo(
    () => new Set((existing ?? []).map((e) => e.id)),
    [existing],
  )
  const team = (teamData?.users ?? []).filter((u) => !taken.has(u.id))

  const createEmployee = useCreateEmployee()
  const updateEmployee = useUpdateEmployee()

  const tenantCountry = ((bootstrap?.country || "").toUpperCase() ||
    DEFAULT_COUNTRY) as CountryCode
  const [phoneCountry, setPhoneCountry] = React.useState<CountryCode>(tenantCountry)

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY,
  })

  // El form se rellena al ABRIR, no en cada render: resetear en render pisa lo
  // que el usuario está tipeando.
  React.useEffect(() => {
    if (!open) return
    setPhoneCountry(tenantCountry)
    form.reset(
      employee
        ? {
            // En la edición el legajo ya tiene dueño y no cambia (§9.1).
            contactId: employee.id,
            fullName: employee.fullName,
            documentNumber: employee.documentNumber ?? "",
            // Guardado en E.164 sin '+': se muestra en formato nacional.
            phone: formatPhoneForTenant(employee.phone, bootstrap) || (employee.phone ?? ""),
            email: employee.email ?? "",
            address: employee.address ?? "",
            birthDate: employee.birthDate ?? "",
            jobTitle: employee.jobTitle ?? "",
            hireDate: employee.hireDate ?? "",
            outletId: employee.outletId ?? NONE,
            fixedAmount: employee.fixedAmount,
            fixedPeriod: employee.fixedPeriod ?? "",
            hourlyRate: employee.hourlyRate,
            commissions: employee.commissions,
            notes: employee.notes ?? "",
            schedule: employee.schedule,
            biometricConsent: employee.biometricConsentAt !== null,
          }
        : { ...EMPTY, contactId: presetContactId ?? NEW },
    )
    // `bootstrap` fuera de las deps a propósito: solo interesa su valor en el
    // momento de abrir, y refrescarlo re-montaría el form mientras se edita.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, employee, form, tenantCountry, presetContactId])

  const isEdit = employee !== null
  const saving = createEmployee.isPending || updateEmployee.isPending
  /** En el alta, sin elegir a nadie del equipo: la persona se crea con este form. */
  const creatingUser = !isEdit && form.watch("contactId") === NEW

  const onSubmit = async (values: FormValues) => {
    const creatingUser = !isEdit && values.contactId === NEW

    const payload: EmployeeFormValues = {
      // El nombre, el teléfono y el email son de la PERSONA y solo viajan
      // cuando hay que crearla: editarlos desde acá sería un segundo lugar
      // donde se cambia el nombre de un usuario (§9.1).
      ...(creatingUser
        ? {
            fullName: values.fullName,
            phone: values.phone || null,
            country: phoneCountry,
            email: values.email || null,
          }
        : {}),
      ...(!isEdit && !creatingUser ? { contactId: values.contactId } : {}),
      documentNumber: values.documentNumber || null,
      address: values.address || null,
      birthDate: values.birthDate || null,
      jobTitle: values.jobTitle || null,
      hireDate: values.hireDate,
      outletId: values.outletId === NONE ? null : values.outletId,
      fixedAmount: values.fixedAmount,
      fixedPeriod: values.fixedAmount === null ? null : (values.fixedPeriod as FixedPeriod),
      hourlyRate: values.hourlyRate,
      commissions: values.commissions,
      notes: values.notes || null,
      schedule: values.schedule,
      biometricConsent: values.biometricConsent,
    }

    try {
      if (isEdit) {
        await updateEmployee.mutateAsync({ id: employee.id, values: payload })
        toast.success("Legajo actualizado")
      } else {
        await createEmployee.mutateAsync(payload)
        toast.success("Empleado agregado")
      }
      onOpenChange(false)
    } catch (e) {
      toast.error(isEdit ? "No se pudo actualizar el legajo" : "No se pudo agregar el empleado", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* `l` de la escala: el form es largo y va en dos columnas. */}
      <DialogContent className="sm:max-w-4xl" sectioned>
        <DialogHeader>
          <DialogTitle>
            {isEdit ? employee.fullName : presetName ? `Legajo de ${presetName}` : "Nuevo empleado"}
          </DialogTitle>
          <DialogDescription>
            {isEdit || presetContactId
              ? "Datos del legajo"
              : "Datos de la persona y de su relación laboral"}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="contents">
            <DialogBody className="flex flex-col gap-6">
              {/* La persona. En el alta se elige del equipo o se crea acá
                  mismo; en la edición ya está definida y su nombre, teléfono y
                  email se gestionan en Equipo, que es donde se gestiona
                  cualquier usuario (context/83 §9.1). */}
              {!isEdit && !presetContactId && (
                <FormSection title="Persona">
                  <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                      control={form.control}
                      name="contactId"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Quién</FormLabel>
                          <Select value={field.value} onValueChange={field.onChange}>
                            <FormControl>
                              <SelectTrigger>
                                <SelectValue />
                              </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              <SelectItem value={NEW}>Agregar a alguien nuevo</SelectItem>
                              {team.map((u) => (
                                <SelectItem key={u.id} value={u.id}>
                                  {u.name}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <FormMessage />
                        </FormItem>
                      )}
                    />

                    {creatingUser && (
                      <>
                        <FormField
                          control={form.control}
                          name="fullName"
                          render={({ field }) => (
                            <FormItem>
                              <FormLabel>Nombre y apellido</FormLabel>
                              <FormControl>
                                <Input autoFocus {...field} />
                              </FormControl>
                              <FormMessage />
                            </FormItem>
                          )}
                        />
                        <FormField
                          control={form.control}
                          name="phone"
                          render={({ field }) => (
                            <FormItem>
                              <FormLabel>Teléfono</FormLabel>
                              <FormControl>
                                <PhoneInput
                                  value={field.value}
                                  country={phoneCountry}
                                  onChange={(v) => {
                                    field.onChange(v.value)
                                    setPhoneCountry(v.country)
                                  }}
                                  onBlur={field.onBlur}
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
                                <Input type="email" {...field} />
                              </FormControl>
                              <FormMessage />
                            </FormItem>
                          )}
                        />
                      </>
                    )}
                  </div>
                </FormSection>
              )}

              <FormSection title="Datos del legajo">
                <div className="grid gap-4 sm:grid-cols-2">
                  <FormField
                    control={form.control}
                    name="documentNumber"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Documento</FormLabel>
                        <FormControl>
                          <Input {...field} />
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
                          <Input {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>
              </FormSection>

              <FormSection title="Relación laboral">
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
                  <FormField
                    control={form.control}
                    name="outletId"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Sucursal</FormLabel>
                        <Select value={field.value} onValueChange={field.onChange}>
                          <FormControl>
                            <SelectTrigger>
                              <SelectValue placeholder="Sin asignar" />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent>
                            <SelectItem value={NONE}>Sin asignar</SelectItem>
                            {outlets.map((o) => (
                              <SelectItem key={o.id} value={o.id}>
                                {o.name}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>
              </FormSection>

              <FormSection title="Remuneración">
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
              </FormSection>

              {/* El horario declarado (context/83 F1). Va DESPUÉS de la
                  remuneración porque es lo que hace medible la tardanza, y el
                  sueldo por hora se liquida contra las horas que salen de acá.

                  El código de marcación ya no está en esta pantalla: desde el
                  §9.3 hay UN PIN por persona —el del usuario— y se carga donde
                  se cargan los usuarios. */}
              <FormSection title="Horario">
                <FormField
                  control={form.control}
                  name="schedule"
                  render={({ field }) => (
                    <FormItem>
                      <FormDescription>
                        Sin horario cargado, el reporte muestra las horas trabajadas pero no
                        las llegadas tarde.
                      </FormDescription>
                      <FormControl>
                        <EmployeeScheduleField
                          value={field.value}
                          onChange={field.onChange}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </FormSection>

              {/* Reconocimiento por rostro (F2). Va pegado a la marcación
                  porque es la misma operación vista de otra forma: la cara
                  reemplaza al código cuando funciona, y el código sigue estando
                  cuando no. */}
              <FormSection title="Reconocimiento por rostro">
                <div className="flex flex-col gap-5">
                  <FormField
                    control={form.control}
                    name="biometricConsent"
                    render={({ field }) => (
                      <FormItem className="flex flex-row items-start gap-3">
                        <FormControl>
                          <Checkbox
                            checked={field.value}
                            onCheckedChange={(v) => field.onChange(v === true)}
                          />
                        </FormControl>
                        <div className="flex flex-col gap-1">
                          {/* Llano, sin jerga legal ni técnica (§8 de
                              context/14): la persona acepta algo concreto, no
                              firma un tratado. */}
                          <FormLabel className="font-normal">
                            La persona aceptó que se la identifique por su rostro
                          </FormLabel>
                          <FormDescription>
                            Se puede desmarcar cuando quiera. Al desmarcarlo, el rostro se borra.
                          </FormDescription>
                        </div>
                        <FormMessage />
                      </FormItem>
                    )}
                  />

                  <EmployeeFaceField
                    employee={employee ?? undefined}
                    consentChecked={form.watch("biometricConsent")}
                  />
                </div>
              </FormSection>

              <FormSection title="Notas">
                <FormField
                  control={form.control}
                  name="notes"
                  render={({ field }) => (
                    <FormItem>
                      <FormControl>
                        <Textarea rows={3} {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </FormSection>

              {/* Los adjuntos cuelgan de un legajo que ya existe: en el alta
                  todavía no hay id al que subirlos. */}
              {isEdit && (
                <FormSection title="Archivos">
                  <EmployeeAttachments employeeId={employee.id} />
                </FormSection>
              )}
            </DialogBody>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={saving}
              >
                Cancelar
              </Button>
              <Button type="submit" disabled={saving}>
                {isEdit ? "Guardar" : "Agregar"}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
