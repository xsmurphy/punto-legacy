"use client"

/**
 * Reporte de Sucursales — cómo rinde cada sucursal frente a las otras
 * (2026-09-19).
 *
 * Arquetipo Reporte (`context/84` §4): período a la derecha del encabezado y
 * pestañas —Dashboard (comparativo, participación, evolución) y Operación
 * (horas pico, medios de pago, artículos por sucursal)—. El rango es UNO,
 * leído acá y pasado a las dos pestañas.
 *
 * Alcance: las sucursales asignadas al usuario (todas si no tiene
 * restricción), NO la del selector del logo — el reporte compara sucursales
 * entre sí y parado en una sola no habría nada que comparar. Lo resuelve el
 * endpoint (`api/v1/reports/outlets.php`). Con menos de dos sucursales
 * activas en el alcance la entrada ni se ofrece (`requiresMultiOutlet` en
 * `lib/navigation/routes.ts` y en el índice); si se llega igual por URL, la
 * página lo dice en vez de mostrar una tabla de una fila.
 *
 * Mismo permiso que Ventas (`reports.sales.view`): son los mismos números
 * repartidos por sucursal.
 */

import * as React from "react"
import { useSearchParams } from "next/navigation"
import { Store } from "lucide-react"

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { DateRangePicker } from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"
import { BackLink } from "@/components/page/back-link"
import { OutletsDashboardTab } from "@/components/domain/reports/outlets/outlets-dashboard-tab"
import { OutletsOperationsTab } from "@/components/domain/reports/outlets/outlets-operations-tab"
import { useDateRange } from "@/hooks/use-date-range"
import { countActiveOutlets, useOutlets } from "@/hooks/use-outlets"

const TAB_IDS = ["dashboard", "operacion"] as const

export default function OutletsReportPage() {
  const { range, setRange } = useDateRange()
  const outlets = useOutlets()
  const searchParams = useSearchParams()
  const requested = searchParams.get("tab")
  const initialTab =
    requested && (TAB_IDS as readonly string[]).includes(requested) ? requested : "dashboard"

  const singleOutlet = outlets.isSuccess && countActiveOutlets(outlets.data.rows) < 2

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink href="/reports" label="Volver a reportes" />
          <h1 className="text-2xl font-semibold">Sucursales</h1>
        </div>
        {!singleOutlet && <DateRangePicker value={range} onChange={setRange} />}
      </header>

      {singleOutlet ? (
        <EmptyState
          icon={Store}
          title="Hace falta más de una sucursal"
          description="Este reporte compara sucursales entre sí. Con una sola, sus números están en el reporte de Ventas."
        />
      ) : (
        <Tabs defaultValue={initialTab} className="flex flex-col gap-4">
          <TabsList>
            <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
            <TabsTrigger value="operacion">Operación</TabsTrigger>
          </TabsList>

          <TabsContent value="dashboard" className="m-0">
            <OutletsDashboardTab range={range} />
          </TabsContent>

          <TabsContent value="operacion" className="m-0">
            <OutletsOperationsTab range={range} />
          </TabsContent>
        </Tabs>
      )}
    </div>
  )
}
