"use client"

/**
 * Resumen de la ficha: lo que se mira sin buscar nada.
 *
 * Las horas del mes salen del MISMO `/v1/attendance` que el reporte, acotado a
 * esta persona y al mes en curso. El rango es fijo a propósito y no el rango
 * compartido del panel: este bloque responde "cómo viene este mes", y si
 * siguiera al rango que quedó puesto en un reporte diría otra cosa cada vez sin
 * que el título cambie. El rango que se elige está en la pestaña Asistencia.
 *
 * El rostro y el código se muestran como ESTADO, no como formulario: acá se
 * mira, y se cambia en la pestaña que corresponde.
 */

import * as React from "react"
import { ScanFace, KeyRound, IdCard } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { KpiCard } from "@/components/domain/contacts/kpi-card"
import { EmptyState } from "@/components/empty-state"
import { useAttendanceReport } from "@/hooks/use-attendance"
import { formatMinutes } from "@/components/domain/reports/attendance/attendance-format"
import type { Employee } from "@/hooks/use-employees"
import type { TeamMember } from "@/hooks/use-team"
import { formatDate, formatDateTime } from "@/lib/format-date"

/** Primer día del mes en curso y hoy, en el formato que espera el backend. */
function monthRange(): { from: string; to: string } {
  const pad = (n: number) => String(n).padStart(2, "0")
  const now = new Date()
  const first = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01 00:00:00`
  const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} 23:59:59`
  return { from: first, to: today }
}

export function EmployeeSummaryTab({
  employeeId,
  employee,
  user,
  isLoading,
  canViewAttendance,
  canManage,
  onCreateLegajo,
}: {
  employeeId: string
  employee: Employee | null
  user: TeamMember | null
  isLoading: boolean
  canViewAttendance: boolean
  canManage: boolean
  onCreateLegajo: () => void
}) {
  const range = React.useMemo(monthRange, [])
  const attendance = useAttendanceReport(
    { ...range, employeeId },
    canViewAttendance && employee !== null,
  )

  const summary = attendance.data?.employees?.[0]
  // La más reciente del mes. El backend no garantiza orden, así que se elige
  // por fecha en vez de confiar en la primera fila.
  const lastMark = React.useMemo(() => {
    const marks = attendance.data?.marks ?? []
    if (marks.length === 0) return null
    return marks.reduce((a, b) => (a.markedAt >= b.markedAt ? a : b))
  }, [attendance.data?.marks])

  if (isLoading) {
    return (
      <div className="flex flex-col gap-3">
        <Skeleton className="h-16 w-full" />
        <Skeleton className="h-20 w-full" />
      </div>
    )
  }

  if (!employee) {
    return (
      <EmptyState
        icon={IdCard}
        title="Sin legajo"
        description="Esta persona usa el sistema pero todavía no tiene legajo cargado."
        actions={canManage ? <Button onClick={onCreateLegajo}>Cargar legajo</Button> : undefined}
      />
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 rounded-lg border bg-card px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[11px] uppercase tracking-wide text-muted-foreground">
            Puesto
          </span>
          <span className="text-sm font-medium">{employee.jobTitle ?? "Sin asignar"}</span>
          {employee.outletName && (
            <span className="text-xs text-muted-foreground">· {employee.outletName}</span>
          )}
        </div>
        {employee.hireDate && (
          <span className="text-xs text-muted-foreground">
            En el equipo desde {formatDate(employee.hireDate)}
          </span>
        )}
      </div>

      {canViewAttendance && (
        <>
          <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Este mes
          </p>
          <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <KpiCard
              label="Horas trabajadas"
              value={attendance.isLoading ? null : formatMinutes(summary?.workedMinutes ?? 0)}
            />
            <KpiCard
              label="Días con marcación"
              value={attendance.isLoading ? null : (summary?.days ?? 0)}
            />
            <KpiCard
              label="Llegadas tarde"
              value={attendance.isLoading ? null : (summary?.lateCount ?? 0)}
            />
            <KpiCard
              label="Última marcación"
              value={
                attendance.isLoading
                  ? null
                  : lastMark
                    ? formatDateTime(lastMark.markedAt)
                    : "Sin marcaciones"
              }
            />
          </div>
        </>
      )}

      <p className="mt-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
        Cómo marca
      </p>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div className="flex items-center gap-2 rounded-lg border bg-card px-3 py-2.5">
          <ScanFace className="size-4 text-muted-foreground" />
          <span className="text-sm">Rostro</span>
          <span className="ml-auto">
            {employee.face ? (
              <Badge variant="secondary">
                Registrado
                {employee.face.enrolledAt ? ` el ${formatDate(employee.face.enrolledAt)}` : ""}
              </Badge>
            ) : (
              <Badge variant="outline">Sin registrar</Badge>
            )}
          </span>
        </div>
        <div className="flex items-center gap-2 rounded-lg border bg-card px-3 py-2.5">
          <KeyRound className="size-4 text-muted-foreground" />
          <span className="text-sm">Código</span>
          <span className="ml-auto">
            {employee.hasPin || user?.lockPass ? (
              <Badge variant="secondary">Tiene código</Badge>
            ) : (
              <Badge variant="outline">Sin código</Badge>
            )}
          </span>
        </div>
      </div>
    </div>
  )
}
