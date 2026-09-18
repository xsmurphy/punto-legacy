"use client"

/**
 * Ficha de una persona del comercio (context/83 §9).
 *
 * Es la ÚNICA ficha: desde que Equipo y Empleados se unificaron, acá entra
 * tanto quien solo usa el sistema como quien además tiene legajo cargado. Las
 * pestañas del legajo existen siempre; cuando la persona no tiene uno, ofrecen
 * cargarlo en vez de esconderse — que una pestaña aparezca y desaparezca según
 * el estado del dato hace que la pantalla parezca otra cada vez.
 *
 * El alta del legajo sigue siendo el diálogo (`EmployeeFormDialog`); acá se
 * EDITA. Mismo reparto que en la ficha de cliente.
 */

import * as React from "react"
import Link from "next/link"
import { useParams, usePathname, useRouter, useSearchParams } from "next/navigation"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { toast } from "sonner"
import {
  ArrowLeft,
  BarChart3,
  CalendarClock,
  IdCard,
  Loader2,
  ScanFace,
  ShieldCheck,
  UserCheck,
  Wallet,
} from "lucide-react"

import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
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
import { EmptyState } from "@/components/empty-state"

import { EmployeeAttachments } from "@/components/employees/employee-attachments"
import { EmployeeFaceField } from "@/components/employees/employee-face-field"
import { EmployeeScheduleField } from "@/components/employees/employee-schedule-field"
import { EmployeeFormDialog } from "@/components/employees/employee-form-dialog"
import { EmployeeFacePhoto } from "@/components/employees/employee-face-photo"
import { EmployeeSummaryTab } from "@/components/employees/employee-summary-tab"
import { EmployeeAttendanceTab } from "@/components/employees/employee-attendance-tab"
import { EmployeeAccessTab } from "@/components/employees/employee-access-tab"
import { EmployeeHeaderActions } from "@/components/employees/employee-header-actions"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useOutlets } from "@/hooks/use-outlets"
import { usePermission } from "@/hooks/use-permissions"
import { useTeamMember } from "@/hooks/use-team"
import {
  useEmployee,
  useUpdateEmployee,
  type EmployeeFormValues,
  type EmployeeSchedule,
  type FixedPeriod,
} from "@/hooks/use-employees"
import { useAgentPageSnapshot } from "@/lib/agent/use-agent-page-snapshot"
import { ApiError } from "@/lib/api-client"
import { formatDate } from "@/lib/format-date"

const NONE = "__none__"

const PERIOD_LABEL: Record<FixedPeriod, string> = {
  monthly: "Por mes",
  biweekly: "Por quincena",
  weekly: "Por semana",
}

const TAB_KEYS = [
  "resumen",
  "datos",
  "remuneracion",
  "horario",
  "rostro",
  "asistencia",
  "acceso",
] as const
type TabKey = (typeof TAB_KEYS)[number]

/** Las pestañas que editan el legajo — las que muestran el botón de guardar. */
const FORM_TABS: TabKey[] = ["datos", "remuneracion", "horario"]

const scheduleSchema = z.custom<EmployeeSchedule | null>(() => true)

const schema = z
  .object({
    documentNumber: z.string(),
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
    biometricConsent: z.boolean(),
  })
  .refine((v) => v.fixedAmount === null || v.fixedPeriod !== "", {
    message: "Elegí cada cuánto se paga",
    path: ["fixedPeriod"],
  })

type FormValues = z.infer<typeof schema>

export default function EmployeeDetailPage() {
  // `useSearchParams()` necesita un Suspense boundary en el App Router —
  // mismo patrón que /settings/catalog y la ficha de cliente.
  return (
    <React.Suspense fallback={null}>
      <EmployeeDetailPageInner />
    </React.Suspense>
  )
}

function EmployeeDetailPageInner() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()

  const canViewHr = usePermission("hr.employees.view")
  const canManageHr = usePermission("hr.employees.manage")
  const canViewUsers = usePermission("contacts.user.view")
  const canViewAttendance = usePermission("hr.attendance.view")

  const { data: bootstrap } = useBootstrap()
  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []

  const user = useTeamMember(canViewUsers ? id : undefined)
  const legajo = useEmployee(canViewHr ? id : undefined)

  const employee = legajo.data ?? null
  const hasLegajo = employee !== null

  const update = useUpdateEmployee()
  const [creatingLegajo, setCreatingLegajo] = React.useState(false)

  // El nombre sale del USUARIO, que es donde vive desde la unificación; el
  // legajo ya no guarda uno propio (§9.4).
  const name = user.data?.name ?? employee?.fullName ?? ""
  const isLoading = user.isLoading || legajo.isLoading

  const sections = React.useMemo(() => {
    const out: { key: TabKey; label: string; icon: React.ReactNode }[] = [
      { key: "resumen", label: "Resumen", icon: <BarChart3 className="size-3.5" /> },
    ]
    if (canViewHr) {
      out.push(
        { key: "datos", label: "Datos", icon: <IdCard className="size-3.5" /> },
        { key: "remuneracion", label: "Remuneración", icon: <Wallet className="size-3.5" /> },
        { key: "horario", label: "Horario", icon: <CalendarClock className="size-3.5" /> },
        { key: "rostro", label: "Rostro", icon: <ScanFace className="size-3.5" /> },
      )
    }
    if (canViewAttendance) {
      out.push({ key: "asistencia", label: "Asistencia", icon: <UserCheck className="size-3.5" /> })
    }
    if (canViewUsers) {
      out.push({ key: "acceso", label: "Acceso", icon: <ShieldCheck className="size-3.5" /> })
    }
    return out
  }, [canViewHr, canViewAttendance, canViewUsers])

  const requested = searchParams.get("tab")
  const available = sections.map((s) => s.key)
  const tab: TabKey =
    requested && (available as string[]).includes(requested)
      ? (requested as TabKey)
      : (available[0] ?? "resumen")

  const setTab = React.useCallback(
    (v: string) => {
      const params = new URLSearchParams(searchParams.toString())
      params.set("tab", v)
      router.replace(`${pathname}?${params.toString()}`, { scroll: false })
    },
    [pathname, router, searchParams],
  )

  useAgentPageSnapshot(
    name
      ? {
          route: `/employees/${id}`,
          routeLabel: `Ficha de ${name}`,
          summary: {
            contactId: id,
            nombre: name,
            puesto: employee?.jobTitle ?? null,
            sucursal: employee?.outletName ?? null,
            tieneLegajo: hasLegajo,
          },
        }
      : null,
    [id, name, employee?.jobTitle, employee?.outletName, hasLegajo],
  )

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      documentNumber: "",
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
    },
  })

  React.useEffect(() => {
    if (!employee) return
    form.reset({
      documentNumber: employee.documentNumber ?? "",
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
    })
  }, [employee, form])

  const onSubmit = async (values: FormValues) => {
    if (!employee) return
    const payload: Partial<EmployeeFormValues> = {
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
      await update.mutateAsync({ id: employee.id, values: payload })
      toast.success("Legajo actualizado")
    } catch (e) {
      toast.error("No se pudo guardar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const loadError = user.error ?? legajo.error
  if (loadError) {
    const notFound = loadError instanceof ApiError && loadError.status === 404
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <Card>
          <CardContent className="p-8 text-center text-sm text-muted-foreground">
            {notFound
              ? "No encontramos a esta persona."
              : `No se pudo cargar la ficha. ${loadError.message}`}
          </CardContent>
        </Card>
      </div>
    )
  }

  /** El bloque que ocupa una pestaña de legajo cuando la persona no tiene uno. */
  const sinLegajo = (
    <EmptyState
      icon={IdCard}
      title="Sin legajo"
      description="Cargá su puesto, fecha de ingreso y remuneración para llevar su legajo."
      actions={
        canManageHr ? (
          <Button onClick={() => setCreatingLegajo(true)}>Cargar legajo</Button>
        ) : undefined
      }
    />
  )

  return (
    <div className="flex flex-col gap-4">
      <BackLink />

      <Form {...form}>
        <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
          <header className="flex items-start justify-between gap-3">
            <div className="flex min-w-0 items-center gap-2.5">
              <Avatar className="size-9 shrink-0">
                <AvatarFallback className="text-xs font-medium">
                  {isLoading ? "…" : initials(name)}
                </AvatarFallback>
              </Avatar>
              <div className="flex min-w-0 flex-col">
                <h1 className="truncate text-2xl font-semibold leading-tight">
                  {isLoading ? <Skeleton className="h-7 w-48" /> : name || "Persona"}
                </h1>
                {isLoading ? (
                  <Skeleton className="mt-1 h-4 w-56" />
                ) : (
                  <p className="truncate text-sm text-muted-foreground">
                    {[
                      employee?.jobTitle,
                      user.data?.roleName,
                      employee?.outletName ?? user.data?.outletNames?.join(", "),
                    ]
                      .filter(Boolean)
                      .join(" · ") || "Sin puesto asignado"}
                  </p>
                )}
              </div>
            </div>

            <div className="flex shrink-0 items-center gap-2">
              <StatusBadge
                isLoading={isLoading}
                employee={employee}
                userActive={user.data?.status === 1}
              />
              <EmployeeHeaderActions
                employee={employee}
                canManage={canManageHr}
                onCreateLegajo={() => setCreatingLegajo(true)}
              />
              {FORM_TABS.includes(tab) && hasLegajo && canManageHr && (
                <Button type="submit" size="sm" disabled={update.isPending}>
                  {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                  Guardar
                </Button>
              )}
            </div>
          </header>

          <Tabs value={tab} onValueChange={setTab}>
            <div className="-mx-2 overflow-x-auto px-2">
              <TabsList className="w-fit min-w-full justify-start gap-1 sm:gap-0">
                {sections.map((s) => (
                  <TabsTrigger key={s.key} value={s.key} className="gap-1.5">
                    {s.icon}
                    {s.label}
                  </TabsTrigger>
                ))}
              </TabsList>
            </div>

            <TabsContent value="resumen" className="mt-6">
              {tab === "resumen" && (
                <EmployeeSummaryTab
                  employeeId={id}
                  employee={employee}
                  user={user.data ?? null}
                  isLoading={isLoading}
                  canViewAttendance={canViewAttendance}
                  canManage={canManageHr}
                  onCreateLegajo={() => setCreatingLegajo(true)}
                />
              )}
            </TabsContent>

            <TabsContent value="datos" className="mt-6">
              {tab === "datos" &&
                (hasLegajo ? (
                  <div className="flex flex-col gap-6">
                    <FormSection title="Datos personales">
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
                            <FormItem className="sm:col-span-2">
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
                    </FormSection>

                    <FormSection title="Archivos">
                      <EmployeeAttachments employeeId={id} />
                    </FormSection>
                  </div>
                ) : (
                  sinLegajo
                ))}
            </TabsContent>

            <TabsContent value="remuneracion" className="mt-6">
              {tab === "remuneracion" &&
                (hasLegajo ? (
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
                ) : (
                  sinLegajo
                ))}
            </TabsContent>

            <TabsContent value="horario" className="mt-6">
              {tab === "horario" &&
                (hasLegajo ? (
                  <FormSection title="Horario">
                    <FormField
                      control={form.control}
                      name="schedule"
                      render={({ field }) => (
                        <FormItem>
                          <FormDescription>
                            Sin horario cargado, el reporte muestra las horas trabajadas pero
                            no las llegadas tarde.
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
                ) : (
                  sinLegajo
                ))}
            </TabsContent>

            <TabsContent value="rostro" className="mt-6">
              {tab === "rostro" &&
                (hasLegajo ? (
                  <div className="flex flex-col gap-6">
                    <FormSection title="Reconocimiento por rostro">
                      <div className="flex flex-col gap-5">
                        <FormField
                          control={form.control}
                          name="biometricConsent"
                          render={({ field }) => (
                            <FormItem className="flex flex-row items-center justify-between gap-4 rounded-lg border p-4">
                              <div className="flex flex-col gap-1">
                                <FormLabel className="font-normal">
                                  La persona aceptó que se la identifique por su rostro
                                </FormLabel>
                                <FormDescription>
                                  Se puede desmarcar cuando quiera. Al desmarcarlo, el rostro
                                  se borra.
                                </FormDescription>
                              </div>
                              <FormControl>
                                <Switch
                                  checked={field.value}
                                  onCheckedChange={field.onChange}
                                  disabled={!canManageHr}
                                />
                              </FormControl>
                            </FormItem>
                          )}
                        />
                        {/* El switch de arriba es del formulario del legajo y se
                            guarda con él: el botón de guardar del header vale
                            también para esta pestaña. */}
                        {canManageHr && (
                          <Button type="submit" variant="outline" className="w-fit" disabled={update.isPending}>
                            {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                            Guardar la autorización
                          </Button>
                        )}
                        <EmployeeFaceField
                          employee={employee ?? undefined}
                          consentChecked={form.watch("biometricConsent")}
                        />
                      </div>
                    </FormSection>

                    <FormSection title="Foto registrada">
                      <EmployeeFacePhoto employee={employee} />
                    </FormSection>
                  </div>
                ) : (
                  sinLegajo
                ))}
            </TabsContent>

            <TabsContent value="asistencia" className="mt-6">
              {tab === "asistencia" && (
                <EmployeeAttendanceTab employeeId={id} bootstrap={bootstrap} />
              )}
            </TabsContent>

            <TabsContent value="acceso" className="mt-6">
              {tab === "acceso" && <EmployeeAccessTab user={user.data ?? null} isLoading={user.isLoading} />}
            </TabsContent>
          </Tabs>
        </form>
      </Form>

      {/* Alta del legajo de alguien que ya es usuario: la persona viene fijada,
          no se elige. */}
      <EmployeeFormDialog
        open={creatingLegajo}
        employee={null}
        presetContactId={id}
        presetName={name}
        onOpenChange={setCreatingLegajo}
      />
    </div>
  )
}

function StatusBadge({
  isLoading,
  employee,
  userActive,
}: {
  isLoading: boolean
  employee: { active: boolean; status: number; endDate: string | null } | null
  userActive: boolean
}) {
  if (isLoading) return <Skeleton className="h-5 w-16" />
  if (employee && employee.status === 1 && !employee.active) {
    return (
      <Badge variant="outline">
        Egresó {employee.endDate ? formatDate(employee.endDate) : ""}
      </Badge>
    )
  }
  return userActive ? (
    <Badge variant="secondary">Activo</Badge>
  ) : (
    <Badge variant="outline">Inactivo</Badge>
  )
}

function initials(name: string | null | undefined): string {
  if (!name) return "?"
  return name
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join("")
}

function BackLink() {
  return (
    <Link
      href="/employees"
      className="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
    >
      <ArrowLeft className="size-3.5" />
      Volver a Equipo
    </Link>
  )
}
