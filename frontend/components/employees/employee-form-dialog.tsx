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
import { EmployeeAttachments } from "@/components/employees/employee-attachments"
import { EmployeeScheduleField } from "@/components/employees/employee-schedule-field"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useOutlets } from "@/hooks/use-outlets"
import { useTeamMembers } from "@/hooks/use-team"
import {
  useCreateEmployee,
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
    fullName: z.string().min(1, "El nombre es requerido"),
    documentNumber: z.string(),
    phone: z.string(),
    email: z.string(),
    address: z.string(),
    birthDate: z.string(),
    jobTitle: z.string(),
    hireDate: z.string().min(1, "La fecha de ingreso es requerida"),
    outletId: z.string(),
    userId: z.string(),
    fixedAmount: z.number().nullable(),
    fixedPeriod: z.string(),
    hourlyRate: z.number().nullable(),
    commissions: z.boolean(),
    notes: z.string(),
    // PIN de marcación en claro. Vacío = no se toca (ver `onSubmit`).
    markPin: z.string().refine((v) => v === "" || /^\d{4}$/.test(v), {
      message: "Tiene que ser de 4 dígitos",
    }),
    /** El usuario pidió BORRAR el PIN. Distinto de dejar el campo vacío. */
    markPinCleared: z.boolean(),
    schedule: scheduleSchema,
  })
  // El monto fijo y su periodicidad son un solo dato. Se valida acá y no solo
  // en el backend para que el error salga en el campo, no en un toast.
  .refine((v) => v.fixedAmount === null || v.fixedPeriod !== "", {
    message: "Elegí cada cuánto se paga",
    path: ["fixedPeriod"],
  })

type FormValues = z.infer<typeof schema>

const EMPTY: FormValues = {
  fullName: "",
  documentNumber: "",
  phone: "",
  email: "",
  address: "",
  birthDate: "",
  jobTitle: "",
  hireDate: "",
  outletId: NONE,
  userId: NONE,
  fixedAmount: null,
  fixedPeriod: "",
  hourlyRate: null,
  commissions: false,
  notes: "",
  markPin: "",
  markPinCleared: false,
  schedule: null,
}

export function EmployeeFormDialog({
  open,
  employee,
  onOpenChange,
}: {
  open: boolean
  /** null = alta. */
  employee: Employee | null
  onOpenChange: (open: boolean) => void
}) {
  const { data: bootstrap } = useBootstrap()
  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []
  const { data: teamData } = useTeamMembers()
  const team = teamData?.users ?? []

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
            userId: employee.userId ?? NONE,
            fixedAmount: employee.fixedAmount,
            fixedPeriod: employee.fixedPeriod ?? "",
            hourlyRate: employee.hourlyRate,
            commissions: employee.commissions,
            notes: employee.notes ?? "",
            // Vacío SIEMPRE al abrir, tenga PIN o no: el campo es "poné uno
            // nuevo", no "acá está el que tiene". El que tiene no se muestra —
            // ni el backend lo manda.
            markPin: "",
            markPinCleared: false,
            schedule: employee.schedule,
          }
        : EMPTY,
    )
    // `bootstrap` fuera de las deps a propósito: solo interesa su valor en el
    // momento de abrir, y refrescarlo re-montaría el form mientras se edita.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, employee, form, tenantCountry])

  const isEdit = employee !== null
  const saving = createEmployee.isPending || updateEmployee.isPending

  const onSubmit = async (values: FormValues) => {
    const payload: EmployeeFormValues = {
      fullName: values.fullName,
      documentNumber: values.documentNumber || null,
      phone: values.phone || null,
      country: phoneCountry,
      email: values.email || null,
      address: values.address || null,
      birthDate: values.birthDate || null,
      jobTitle: values.jobTitle || null,
      hireDate: values.hireDate,
      outletId: values.outletId === NONE ? null : values.outletId,
      userId: values.userId === NONE ? null : values.userId,
      fixedAmount: values.fixedAmount,
      fixedPeriod: values.fixedAmount === null ? null : (values.fixedPeriod as FixedPeriod),
      hourlyRate: values.hourlyRate,
      commissions: values.commissions,
      notes: values.notes || null,
      schedule: values.schedule,
      // Los tres estados del PIN, y el orden importa. Pedir borrarlo gana sobre
      // haber tipeado uno; dejar el campo vacío NO manda la clave, así que
      // corregir un teléfono no le saca el PIN a nadie.
      ...(values.markPinCleared
        ? { markPin: null }
        : values.markPin !== ""
          ? { markPin: values.markPin }
          : {}),
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
          <DialogTitle>{isEdit ? employee.fullName : "Nuevo empleado"}</DialogTitle>
          <DialogDescription>
            {isEdit ? "Datos del legajo" : "Datos de la persona y de su relación laboral"}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="contents">
            <DialogBody className="flex flex-col gap-6">
              <FormSection title="Datos personales">
                <div className="grid gap-4 sm:grid-cols-2">
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
                  <FormField
                    control={form.control}
                    name="userId"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Usuario del sistema</FormLabel>
                        <Select value={field.value} onValueChange={field.onChange}>
                          <FormControl>
                            <SelectTrigger>
                              <SelectValue placeholder="Sin usuario" />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent>
                            <SelectItem value={NONE}>Sin usuario</SelectItem>
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

              {/* Marcación de asistencia (context/83 F1). Va DESPUÉS de la
                  remuneración y antes de las notas porque el horario es lo que
                  hace medible la tardanza, y el sueldo por hora se liquida
                  contra las horas que sale de acá. */}
              <FormSection title="Marcación de asistencia">
                <div className="flex flex-col gap-6">
                  <FormField
                    control={form.control}
                    name="markPin"
                    render={({ field }) => (
                      <FormItem className="max-w-xs">
                        <FormLabel>
                          {employee?.hasMarkPin ? "Cambiar el código" : "Código de marcación"}
                        </FormLabel>
                        <FormControl>
                          <Input
                            inputMode="numeric"
                            maxLength={4}
                            placeholder={employee?.hasMarkPin ? "••••" : "4 dígitos"}
                            {...field}
                            onChange={(e) => {
                              // Tipear cancela el borrado: son dos intenciones
                              // opuestas y la última gana.
                              form.setValue("markPinCleared", false)
                              field.onChange(e.target.value.replace(/\D/g, "").slice(0, 4))
                            }}
                          />
                        </FormControl>
                        <FormDescription>
                          {employee?.hasMarkPin
                            ? form.watch("markPinCleared")
                              ? "Se va a quitar al guardar: esta persona no va a poder marcar."
                              : "Dejalo vacío para no cambiarlo."
                            : "Con este código la persona marca su entrada y salida en la caja."}
                        </FormDescription>
                        <FormMessage />
                      </FormItem>
                    )}
                  />

                  {employee?.hasMarkPin && !form.watch("markPinCleared") && (
                    <Button
                      type="button"
                      variant="ghost"
                      className="w-fit -mt-3 text-muted-foreground"
                      onClick={() => {
                        form.setValue("markPinCleared", true)
                        form.setValue("markPin", "")
                      }}
                    >
                      Quitar el código
                    </Button>
                  )}

                  <FormField
                    control={form.control}
                    name="schedule"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Horario</FormLabel>
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
