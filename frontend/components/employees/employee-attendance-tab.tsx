"use client"

/**
 * Las marcaciones de UNA persona, dentro de su ficha.
 *
 * No es un reporte nuevo: es el mismo `/v1/attendance` del reporte de
 * Asistencia, acotado con el filtro `employeeId` que ya existía, y pintado con
 * la MISMA tabla. Duplicar la tabla acá haría que la foto, el motivo de
 * revisión y la acción de revisar se mantengan en dos lugares.
 *
 * El rango es el compartido del panel (`useDateRange`), igual que en el
 * reporte: quien viene mirando un mes no quiere que la ficha lo devuelva a hoy.
 */

import * as React from "react"
import Link from "next/link"
import { ArrowUpRight } from "lucide-react"

import { Button } from "@/components/ui/button"
import { DateRangePicker, rangeToBackend } from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useAttendanceReport } from "@/hooks/use-attendance"
import { AttendanceMarksTab } from "@/components/domain/reports/attendance/attendance-marks-tab"
import { KpiCard } from "@/components/domain/contacts/kpi-card"
import { formatMinutes } from "@/components/domain/reports/attendance/attendance-format"
import type { TenantLocaleConfig } from "@/lib/tenant-locale"

export function EmployeeAttendanceTab({
  employeeId,
}: {
  employeeId: string
  /** Reservado para formatos del tenant; hoy los minutos no dependen de él. */
  bootstrap?: TenantLocaleConfig | null
}) {
  const { range, setRange } = useDateRange()

  const filters = React.useMemo(() => {
    const { from, to } = rangeToBackend(range)
    return { from, to, employeeId }
  }, [range, employeeId])

  const { data, isLoading } = useAttendanceReport(filters)

  // El backend devuelve el resumen por persona; filtrada a una, es su fila.
  const summary = data?.employees?.[0]

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <DateRangePicker value={range} onChange={setRange} />
        <Button asChild variant="outline" size="sm">
          <Link href="/reports/attendance">
            Ver el reporte completo
            <ArrowUpRight className="size-4" />
          </Link>
        </Button>
      </div>

      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <KpiCard
          label="Horas trabajadas"
          value={isLoading ? null : formatMinutes(summary?.workedMinutes ?? 0)}
        />
        <KpiCard label="Días con marcación" value={isLoading ? null : (summary?.days ?? 0)} />
        <KpiCard label="Llegadas tarde" value={isLoading ? null : (summary?.lateCount ?? 0)} />
        <KpiCard label="Para revisar" value={isLoading ? null : (summary?.needsReview ?? 0)} />
      </div>

      <AttendanceMarksTab rows={data?.marks ?? []} isLoading={isLoading} />
    </div>
  )
}
