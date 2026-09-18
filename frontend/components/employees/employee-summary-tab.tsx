"use client"

/**
 * Resumen de la ficha: el tablero de la persona.
 *
 * Es lo que se mira sin buscar nada — cómo viene este mes: cuánto vendió,
 * cuánto trabajó, cuánto cobra y cómo marca. No pide cargar nada ni explica qué
 * falta: lo que no tiene dato muestra su cero y listo (decisión del owner
 * 2026-09-18 — acá no se administra nada, se mira).
 *
 * El mes es FIJO y no el rango compartido del panel: este bloque responde "cómo
 * viene este mes", y si siguiera al rango que quedó puesto en un reporte diría
 * otra cosa cada vez sin que el título cambie. El rango elegible está en la
 * pestaña Asistencia.
 *
 * ── De dónde salen las ventas ──────────────────────────────────────────────
 *
 * Del MISMO `/v1/reports/users` que Reportes › Equipo, vista `summary`. No hay
 * un cálculo propio acá: la atribución de una venta a una persona es
 * `COALESCE(itemSold.userId, transaction.userId)` y vive en `UsersService`.
 * Duplicarla sería tener dos números distintos para la misma pregunta.
 *
 * Ese endpoint no filtra por usuario —devuelve el ranking del equipo— así que
 * la fila se busca acá. Y va detrás de `reports.sales.view`: quien no puede ver
 * los montos del equipo tampoco los ve por esta puerta.
 *
 * Las COMISIONES todavía no existen como dato (son el tarifario de la F4): no
 * se muestra una card con un número inventado.
 */

import * as React from "react"
import { ScanFace, KeyRound } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { KpiCard } from "@/components/domain/contacts/kpi-card"
import { useAttendanceReport } from "@/hooks/use-attendance"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type UsersSummaryResponse } from "@/hooks/use-reports"
import { usePermission } from "@/hooks/use-permissions"
import { formatMinutes } from "@/components/domain/reports/attendance/attendance-format"
import { PERIOD_LABEL } from "@/lib/employees/person-form"
import type { Employee } from "@/hooks/use-employees"
import type { TeamMember } from "@/hooks/use-team"
import { formatMoney } from "@/lib/format-money"
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
}: {
  employeeId: string
  employee: Employee | null
  user: TeamMember | null
  isLoading: boolean
  canViewAttendance: boolean
}) {
  const canViewSales = usePermission("reports.sales.view")
  const { data: bootstrap } = useBootstrap()
  const money = React.useCallback(
    (v: number) => formatMoney(v, bootstrap ?? null),
    [bootstrap],
  )
  const range = React.useMemo(monthRange, [])

  const attendance = useAttendanceReport(
    { ...range, employeeId },
    canViewAttendance && employee !== null,
  )
  const sales = useReport<UsersSummaryResponse>("users", {
    ...range,
    params: { view: "summary" },
    enabled: canViewSales,
  })

  const summary = attendance.data?.employees?.[0]
  // La más reciente del mes. El backend no garantiza orden, así que se elige
  // por fecha en vez de confiar en la primera fila.
  const lastMark = React.useMemo(() => {
    const marks = attendance.data?.marks ?? []
    if (marks.length === 0) return null
    return marks.reduce((a, b) => (a.markedAt >= b.markedAt ? a : b))
  }, [attendance.data?.marks])

  // El ranking solo trae a quien vendió: la ausencia de la fila ES el cero.
  const mine = React.useMemo(
    () => sales.data?.ranking?.find((r) => r.userId === employeeId) ?? null,
    [sales.data?.ranking, employeeId],
  )

  if (isLoading) {
    return (
      <div className="flex flex-col gap-3">
        <Skeleton className="h-16 w-full" />
        <Skeleton className="h-20 w-full" />
      </div>
    )
  }

  const pay = payLine(employee, money)

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 rounded-lg border bg-card px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[11px] uppercase tracking-wide text-muted-foreground">
            Puesto
          </span>
          <span className="text-sm font-medium">{employee?.jobTitle ?? "Sin asignar"}</span>
          {employee?.outletName && (
            <span className="text-xs text-muted-foreground">· {employee.outletName}</span>
          )}
        </div>
        {employee?.hireDate && (
          <span className="text-xs text-muted-foreground">
            En el equipo desde {formatDate(employee.hireDate)}
          </span>
        )}
      </div>

      <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
        Este mes
      </p>
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
        {canViewSales && (
          <>
            <KpiCard
              label="Ventas"
              value={sales.isLoading ? null : (mine?.tickets ?? 0)}
            />
            <KpiCard
              label="Vendido"
              value={sales.isLoading ? null : money(mine?.total ?? 0)}
            />
          </>
        )}
        {canViewAttendance && (
          <>
            <KpiCard
              label="Horas trabajadas"
              value={attendance.isLoading ? null : formatMinutes(summary?.workedMinutes ?? 0)}
            />
            <KpiCard
              label="Llegadas tarde"
              value={attendance.isLoading ? null : (summary?.lateCount ?? 0)}
            />
          </>
        )}
        <KpiCard label="Remuneración" value={pay} />
        <KpiCard
          label="Última marcación"
          value={
            !canViewAttendance
              ? "—"
              : attendance.isLoading
                ? null
                : lastMark
                  ? formatDateTime(lastMark.markedAt)
                  : "—"
          }
        />
      </div>

      <p className="mt-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
        Cómo marca
      </p>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div className="flex items-center gap-2 rounded-lg border bg-card px-3 py-2.5">
          <ScanFace className="size-4 text-muted-foreground" />
          <span className="text-sm">Rostro</span>
          <span className="ml-auto">
            {employee?.face ? (
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
            {employee?.hasPin || user?.lockPass ? (
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

/**
 * El esquema de remuneración en una línea.
 *
 * Los tres componentes CONVIVEN (D1 de context/83): no es un enum, así que se
 * listan los que estén cargados y se separan con `·`.
 */
function payLine(employee: Employee | null, money: (v: number) => string): string {
  if (!employee) return "—"
  const parts: string[] = []
  if (employee.fixedAmount !== null) {
    const period = employee.fixedPeriod ? PERIOD_LABEL[employee.fixedPeriod] : null
    parts.push(
      period
        ? `${money(employee.fixedAmount)} ${period.toLowerCase()}`
        : money(employee.fixedAmount),
    )
  }
  if (employee.hourlyRate !== null) parts.push(`${money(employee.hourlyRate)} por hora`)
  if (employee.commissions) parts.push("Comisiona")
  return parts.length > 0 ? parts.join(" · ") : "—"
}
