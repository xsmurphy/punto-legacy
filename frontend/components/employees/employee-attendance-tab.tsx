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
import { StatsRow, StatTile } from "@/components/stat-tile"
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
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <DateRangePicker value={range} onChange={setRange} />
        <Button asChild variant="outline" size="sm">
          <Link href="/reports/attendance">
            Ver el reporte completo
            <ArrowUpRight className="size-4" />
          </Link>
        </Button>
      </div>

      <StatsRow>
        <StatTile label="Horas trabajadas" value={formatMinutes(summary?.workedMinutes ?? 0)} emphasis isLoading={isLoading} />
        <StatTile label="Días con marcación" value={summary?.days ?? 0} isLoading={isLoading} />
        <StatTile label="Llegadas tarde" value={summary?.lateCount ?? 0} isLoading={isLoading} />
        <StatTile label="Para revisar" value={summary?.needsReview ?? 0} isLoading={isLoading} />
      </StatsRow>

      <AttendanceMarksTab rows={data?.marks ?? []} isLoading={isLoading} />
    </div>
  )
}
