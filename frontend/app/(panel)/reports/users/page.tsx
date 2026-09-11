"use client"

/**
 * Reporte Equipo — cuánto vendió cada persona, qué comisión le corresponde y
 * cómo se movió a lo largo del período.
 *
 * Pestañas (patrón de `/reports/production`):
 *   Dashboard  → `view=summary`     (KPIs, ranking, participación y evolución)
 *   Detalle    → sin `view`         (la tabla histórica, con los que no vendieron)
 *   Comisiones → `view=commissions` (el detalle liquidable por vendedor)
 *
 * El rango vive acá, en el header, y baja a las tres: dos `useDateRange()` en
 * la misma pantalla pelean por el mismo estado compartido (mismo motivo por el
 * que `RankingReportPage` ganó `embeddedRange`).
 *
 * La atribución de una venta a una persona es
 * COALESCE(vendedor de la línea, operador de la venta) — el comentario largo
 * de por qué está en `api/lib/Reports/UsersService.php`.
 */

import * as React from "react"
import Link from "next/link"
import { useSearchParams } from "next/navigation"
import { ArrowLeft } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { DateRangePicker } from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { UsersDashboardTab } from "@/components/domain/reports/users/users-dashboard-tab"
import { UsersDetailTab } from "@/components/domain/reports/users/users-detail-tab"
import { UsersCommissionsTab } from "@/components/domain/reports/users/users-commissions-tab"

const TAB_IDS = ["dashboard", "detalle", "comisiones"] as const

export default function UsersReportPage() {
  const { range, setRange } = useDateRange()
  const searchParams = useSearchParams()
  const requested = searchParams.get("tab")
  const initialTab =
    requested && (TAB_IDS as readonly string[]).includes(requested) ? requested : "dashboard"

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Equipo</h1>
          <p className="text-sm text-muted-foreground">
            Ventas, comisiones y ticket promedio por persona del período.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      <Tabs defaultValue={initialTab} className="flex flex-col gap-4">
        <TabsList>
          <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
          <TabsTrigger value="detalle">Detalle</TabsTrigger>
          <TabsTrigger value="comisiones">Comisiones</TabsTrigger>
        </TabsList>
        <TabsContent value="dashboard" className="m-0"><UsersDashboardTab range={range} /></TabsContent>
        <TabsContent value="detalle" className="m-0"><UsersDetailTab range={range} /></TabsContent>
        <TabsContent value="comisiones" className="m-0"><UsersCommissionsTab range={range} /></TabsContent>
      </Tabs>
    </div>
  )
}

function BackLink() {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href="/reports">
        <ArrowLeft className="size-3.5" />
        Volver a reportes
      </Link>
    </Button>
  )
}
