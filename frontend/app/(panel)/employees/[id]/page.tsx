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
 * Armazón: `EntityShell` (context/84 §3) — Resumen → Datos → Asistencia.
 * Horario y Rostro eran pestañas con su propio Guardar; son partes del mismo
 * formulario y pasaron a secciones de Datos (`?tab=horario|rostro` → datos).
 */

import * as React from "react"
import { useParams } from "next/navigation"

import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
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
import { FormSection, FormSectionColumns } from "@/components/forms/form-section"
import { BackLink } from "@/components/page/back-link"
import { EntityShell, entityInitials } from "@/components/page/entity-shell"

import { EmployeeAttachments } from "@/components/employees/employee-attachments"
import { EmployeeFaceField } from "@/components/employees/employee-face-field"
import { EmployeeScheduleField } from "@/components/employees/employee-schedule-field"
import { EmployeeFacePhoto } from "@/components/employees/employee-face-photo"
import {
  EmployeeSummaryTab,
  useEmployeeMonthAttendance,
} from "@/components/employees/employee-summary-tab"
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
import { formatDate, formatDateTime } from "@/lib/format-date"

/** `?tab=` viejos: Horario y Rostro son secciones de Datos desde 2026-09-18. */
const EMPLOYEE_TAB_ALIASES: Record<string, string> = {
  horario: "datos",
  rostro: "datos",
}

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

  // Marcaciones del mes: el encabezado muestra la última (misma consulta que
  // el Resumen, react-query la pide una vez).
  const monthAttendance = useEmployeeMonthAttendance(id, canViewAttendance && employee !== null)

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

  // ── Encabezado: quién es y cómo marca ──────────────────────────────────────
  // El puesto, desde cuándo está y cómo marca son ATRIBUTOS: van acá como dato
  // y no en bloques propios del Resumen (owner 2026-09-18, anti-patrón de
  // context/84 §2.1).
  const role = [
    employee?.jobTitle,
    user.data?.roleName,
    employee?.outletName ?? user.data?.outletNames?.join(", "),
  ]
    .filter(Boolean)
    .join(" · ")
  const lastMark = (() => {
    const marks = monthAttendance.data?.marks ?? []
    if (marks.length === 0) return null
    // El backend no garantiza orden: la más reciente por fecha.
    return marks.reduce((a, b) => (a.markedAt >= b.markedAt ? a : b))
  })()
  const facts = [
    employee?.hireDate ? `En el equipo desde ${formatDate(employee.hireDate)}` : null,
    employee
      ? employee.face
        ? `Rostro registrado${employee.face.enrolledAt ? ` el ${formatDate(employee.face.enrolledAt)}` : ""}`
        : "Sin rostro registrado"
      : null,
    employee?.hasPin || user.data?.lockPass ? "Tiene código" : "Sin código",
    lastMark ? `Última marcación: ${formatDateTime(lastMark.markedAt)}` : null,
  ]
    .filter(Boolean)
    .join(" · ")

  // ── Datos: UN solo formulario, por secciones ──────────────────────────────
  // Identidad, acceso, trabajo y remuneración (el formulario compartido con el
  // alta), y además Horario y Rostro, que antes eran pestañas propias con su
  // propio Guardar. Datos es el único lugar de edición (context/84 §3).
  const canSeeData = canViewHr || canViewUsers
  const dataTab = !canSeeData ? (
    <p className="text-sm text-muted-foreground">No tenés permiso para ver los datos de esta persona.</p>
  ) : isLoading ? (
    // Recién cuando las DOS consultas resolvieron: el formulario se llena con
    // las dos filas y montarlo antes dejaría escribir sobre campos que todavía
    // no llegaron — y guardarlos vacíos.
    <FormSkeleton />
  ) : (
    <FormSectionColumns>
      <PersonFormSections controller={controller} isCreate={false} showRolesLink />
      {canViewHr && (
        <FormSection title="Horario">
          <FormField
            control={form.control}
            name="schedule"
            render={({ field }) => (
              <FormItem>
                <FormControl>
                  <fieldset disabled={!canManageHr} className="m-0 border-0 p-0">
                    <EmployeeScheduleField value={field.value} onChange={field.onChange} />
                  </fieldset>
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </FormSection>
      )}
      {canViewHr && (
        <FormSection title="Reconocimiento por rostro">
          <FormField
            control={form.control}
            name="biometricConsent"
            render={({ field }) => (
              <FormItem className="flex flex-row items-center justify-between gap-4 rounded-lg border p-4">
                <div className="flex flex-col gap-1">
                  <FormLabel className="font-normal">
                    La persona aceptó que se la identifique por su rostro
                  </FormLabel>
                  {/* Consecuencia de destildarlo, no una explicación: borra un
                      dato biométrico (context/83, biometría = dato sensible). */}
                  <FormDescription>Al desmarcarlo, el rostro se borra.</FormDescription>
                </div>
                <FormControl>
                  <Switch checked={field.value} onCheckedChange={field.onChange} disabled={!canManageHr} />
                </FormControl>
              </FormItem>
            )}
          />
          <EmployeeFaceField employee={employee ?? undefined} consentChecked={form.watch("biometricConsent")} />
        </FormSection>
      )}
      {canViewHr && (
        <FormSection title="Foto registrada">
          <EmployeeFacePhoto employee={employee} />
        </FormSection>
      )}
      {/* Los adjuntos cuelgan de una fila que ya existe. */}
      {employee !== null && canViewHr && (
        <FormSection title="Archivos">
          <EmployeeAttachments employeeId={id} />
        </FormSection>
      )}
    </FormSectionColumns>
  )

  return (
    <Form {...form}>
      <form
        onSubmit={form.handleSubmit(async (values) => {
          await submit(values)
        })}
      >
        <EntityShell
          back={{ href: "/employees", label: "Volver a Equipo" }}
          title={name || "Persona"}
          isLoading={isLoading}
          avatar={
            <Avatar className="size-10 shrink-0">
              <AvatarFallback className="text-sm font-medium">
                {isLoading ? "…" : entityInitials(name)}
              </AvatarFallback>
            </Avatar>
          }
          status={<StatusBadge employee={employee} userActive={user.data?.status === 1} />}
          subtitle={
            <div className="flex flex-col gap-0.5">
              <span>{role || "Sin puesto asignado"}</span>
              {facts && <span>{facts}</span>}
            </div>
          }
          actions={<EmployeeHeaderActions employee={employee} canManage={canManageHr} />}
          summary={
            <EmployeeSummaryTab
              employeeId={id}
              employee={employee}
              isLoading={isLoading}
              canViewAttendance={canViewAttendance}
            />
          }
          data={dataTab}
          extraTabs={[
            canViewAttendance && {
              key: "asistencia",
              label: "Asistencia",
              content: <EmployeeAttendanceTab employeeId={id} bootstrap={bootstrap} />,
            },
          ]}
          tabAliases={EMPLOYEE_TAB_ALIASES}
          save={canEdit && canSeeData ? { pending: isPending } : undefined}
        />
      </form>
    </Form>
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
  employee,
  userActive,
}: {
  employee: { active: boolean; status: number; endDate: string | null } | null
  userActive: boolean
}) {
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
