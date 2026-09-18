"use client"

/**
 * Reporte de Asistencia (context/83 F1).
 *
 * Pestañas:
 *   Resumen      → una fila por persona: horas del período y tardanzas.
 *   Marcaciones  → el detalle crudo, con la foto y la acción de revisar.
 *
 * Las dos leen la MISMA respuesta. El backend devuelve el detalle y el resumen
 * juntos porque el segundo no se puede calcular sin el primero (hay que aparear
 * entradas con salidas, en orden), así que pedirlos por separado sería leer dos
 * veces el mismo rango para responder la misma pregunta.
 *
 * El rango vive acá, en el header, y baja a las dos: dos `useDateRange()` en la
 * misma pantalla pelean por el mismo estado compartido.
 *
 * El filtro "solo para revisar" es un TOGGLE del header y no una tercera
 * pestaña: no es otra vista de los datos, es la misma recortada, y como pedido
 * ("mostrame lo que tengo que mirar") se hace sobre la pestaña en la que ya
 * estás.
 */

import * as React from "react"

import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { DateRangePicker, rangeToBackend } from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useAttendanceReport } from "@/hooks/use-attendance"
import { AttendanceSummaryTab } from "@/components/domain/reports/attendance/attendance-summary-tab"
import { BackLink } from "@/components/page/back-link"
import { AttendanceMarksTab } from "@/components/domain/reports/attendance/attendance-marks-tab"

export default function AttendanceReportPage() {
  const { range, setRange } = useDateRange()
  const [onlyReview, setOnlyReview] = React.useState(false)

  const filters = React.useMemo(() => {
    const { from, to } = rangeToBackend(range)
    return { from, to, needsReview: onlyReview }
  }, [range, onlyReview])

  const { data, isLoading } = useAttendanceReport(filters)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink href="/reports" label="Volver a reportes" />
          <h1 className="text-2xl font-semibold">Asistencia</h1>
          <p className="text-sm text-muted-foreground">
            Horas trabajadas y llegadas tarde del período, con la foto de cada marcación.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <Switch id="only-review" checked={onlyReview} onCheckedChange={setOnlyReview} />
            <Label htmlFor="only-review" className="text-sm font-normal">
              Solo para revisar
            </Label>
          </div>
          <DateRangePicker value={range} onChange={setRange} />
        </div>
      </header>

      <Tabs defaultValue="resumen" className="flex flex-col gap-4">
        <TabsList>
          <TabsTrigger value="resumen">Resumen</TabsTrigger>
          <TabsTrigger value="marcaciones">Marcaciones</TabsTrigger>
        </TabsList>
        <TabsContent value="resumen" className="m-0">
          <AttendanceSummaryTab
            rows={data?.employees ?? []}
            isLoading={isLoading}
            totals={data?.totals}
          />
        </TabsContent>
        <TabsContent value="marcaciones" className="m-0">
          <AttendanceMarksTab rows={data?.marks ?? []} isLoading={isLoading} />
        </TabsContent>
      </Tabs>
    </div>
  )
}
