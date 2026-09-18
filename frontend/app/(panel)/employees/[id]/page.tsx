"use client"

/**
 * Ficha de una persona del comercio (context/83 §9).
 *
 * Es la ÚNICA ficha y, desde 2026-09-18, el ÚNICO formulario: todo lo que se
 * carga de una persona —su nombre, su acceso, su puesto, su sueldo— vive en la
 * pestaña Datos, en secciones, con un solo botón de guardar. Antes estaba
 * partido en dos diálogos que se pisaban: el nombre se pedía en los dos y la
 * sucursal también.
 *
 * El formulario en sí es `components/employees/person-form.tsx`, compartido con
 * el alta desde Equipo — el mismo componente, no una copia.
 *
 * Las otras pestañas no editan datos de identidad: Resumen mira, Horario y
 * Rostro son partes del mismo formulario que necesitan su propio espacio, y
 * Asistencia es historial.
 */

import * as React from "react"
import { useParams, usePathname, useRouter, useSearchParams } from "next/navigation"
import {
  BarChart3,
  CalendarClock,
  IdCard,
  Loader2,
  ScanFace,
  UserCheck,
} from "lucide-react"

import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Switch } from "@/components/ui/switch"
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { FormSection } from "@/components/forms/form-section"

import { EmployeeAttachments } from "@/components/employees/employee-attachments"
import { EmployeeFaceField } from "@/components/employees/employee-face-field"
import { EmployeeScheduleField } from "@/components/employees/employee-schedule-field"
import { EmployeeFacePhoto } from "@/components/employees/employee-face-photo"
import { EmployeeSummaryTab } from "@/components/employees/employee-summary-tab"
import { EmployeeAttendanceTab } from "@/components/employees/employee-attendance-tab"
import { EmployeeHeaderActions } from "@/components/employees/employee-header-actions"
import {
  PersonFormSections,
  usePersonForm,
} from "@/components/employees/person-form"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"
import { useTeamMember } from "@/hooks/use-team"
import { useEmployee } from "@/hooks/use-employees"
import { useAgentPageSnapshot } from "@/lib/agent/use-agent-page-snapshot"
import { ApiError } from "@/lib/api-client"
import { BackLink } from "@/components/page/back-link"
import { formatDate } from "@/lib/format-date"

const TAB_KEYS = ["resumen", "datos", "horario", "rostro", "asistencia"] as const
type TabKey = (typeof TAB_KEYS)[number]

/** Las pestañas que EDITAN — las que muestran el botón de guardar. */
const FORM_TABS: TabKey[] = ["datos", "horario", "rostro"]

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
  const canManageUsers = usePermission("contacts.user.manage")
  const canViewAttendance = usePermission("hr.attendance.view")

  const { data: bootstrap } = useBootstrap()

  const user = useTeamMember(canViewUsers ? id : undefined)
  const legajo = useEmployee(canViewHr ? id : undefined)

  const employee = legajo.data ?? null

  // El nombre sale del USUARIO, que es donde vive desde la unificación (§9.4).
  const name = user.data?.name ?? employee?.fullName ?? ""
  const isLoading = user.isLoading || legajo.isLoading

  const controller = usePersonForm({
    personId: id,
    user: user.data ?? null,
    employee,
  })
  const { form, submit, isPending } = controller
  const canEdit = canManageUsers || canManageHr

  const sections = React.useMemo(() => {
    const out: { key: TabKey; label: string; icon: React.ReactNode }[] = [
      { key: "resumen", label: "Resumen", icon: <BarChart3 className="size-3.5" /> },
    ]
    if (canViewHr || canViewUsers) {
      out.push({ key: "datos", label: "Datos", icon: <IdCard className="size-3.5" /> })
    }
    if (canViewHr) {
      out.push(
        { key: "horario", label: "Horario", icon: <CalendarClock className="size-3.5" /> },
        { key: "rostro", label: "Rostro", icon: <ScanFace className="size-3.5" /> },
      )
    }
    if (canViewAttendance) {
      out.push({ key: "asistencia", label: "Asistencia", icon: <UserCheck className="size-3.5" /> })
    }
    return out
  }, [canViewHr, canViewUsers, canViewAttendance])

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
          },
        }
      : null,
    [id, name, employee?.jobTitle, employee?.outletName],
  )

  const loadError = user.error ?? legajo.error
  if (loadError) {
    const notFound = loadError instanceof ApiError && loadError.status === 404
    return (
      <div className="flex flex-col gap-4">
        <BackLink href="/employees" label="Volver a Equipo" />
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

  return (
    <div className="flex flex-col gap-4">
      <BackLink href="/employees" label="Volver a Equipo" />

      <Form {...form}>
        <form
          onSubmit={form.handleSubmit(async (values) => {
            await submit(values)
          })}
          className="flex flex-col gap-6"
        >
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
              <EmployeeHeaderActions employee={employee} canManage={canManageHr} />
              {FORM_TABS.includes(tab) && canEdit && !isLoading && (
                <Button type="submit" size="sm" disabled={isPending}>
                  {isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
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
                />
              )}
            </TabsContent>

            {/* UN solo formulario, por secciones: identidad, acceso, trabajo y
                remuneración. Un nombre, un selector de sucursal, un código. */}
            <TabsContent value="datos" className="mt-6">
              {/* Recién cuando las DOS consultas resolvieron: el formulario se
                  llena con las dos filas y montarlo antes dejaría escribir
                  sobre campos que todavía no llegaron — y guardarlos vacíos. */}
              {tab === "datos" && isLoading && <FormSkeleton />}
              {tab === "datos" && !isLoading && (
                <div className="flex flex-col gap-6">
                  <PersonFormSections
                    controller={controller}
                    isCreate={false}
                    showRolesLink
                  />
                  {/* Los adjuntos cuelgan de una fila que ya existe. */}
                  {employee !== null && canViewHr && (
                    <FormSection title="Archivos">
                      <EmployeeAttachments employeeId={id} />
                    </FormSection>
                  )}
                </div>
              )}
            </TabsContent>

            <TabsContent value="horario" className="mt-6">
              {tab === "horario" && isLoading && <FormSkeleton />}
              {tab === "horario" && !isLoading && (
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
                          <fieldset disabled={!canManageHr} className="m-0 border-0 p-0">
                            <EmployeeScheduleField
                              value={field.value}
                              onChange={field.onChange}
                            />
                          </fieldset>
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </FormSection>
              )}
            </TabsContent>

            <TabsContent value="rostro" className="mt-6">
              {tab === "rostro" && isLoading && <FormSkeleton />}
              {tab === "rostro" && !isLoading && (
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
              )}
            </TabsContent>

            <TabsContent value="asistencia" className="mt-6">
              {tab === "asistencia" && (
                <EmployeeAttendanceTab employeeId={id} bootstrap={bootstrap} />
              )}
            </TabsContent>
          </Tabs>
        </form>
      </Form>
    </div>
  )
}

function FormSkeleton() {
  return (
    <div className="flex flex-col gap-4">
      <Skeleton className="h-6 w-40" />
      <Skeleton className="h-9 w-full" />
      <Skeleton className="h-9 w-full" />
      <Skeleton className="h-9 w-2/3" />
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
