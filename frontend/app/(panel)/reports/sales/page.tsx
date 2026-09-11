"use client"

/**
 * Reporte de Ventas — el tablero único de cómo vendió el negocio.
 *
 * Fusión del 2026-09-11 (owner): `/reports/summary` y `/reports/transactions`
 * eran dos entradas del índice para el MISMO hecho en dos granos —el período
 * agregado y cada venta de ese período—, así que había que saber de antemano a
 * qué nivel de detalle se quería entrar. Mismo criterio con el que Artículos
 * absorbió Categorías y Marcas el 2026-09-10.
 *
 * A diferencia de aquella consolidación, acá las dos URLs viejas SÍ redirigen
 * (`next.config.ts`): son reportes que la gente tiene en marcadores, y
 * `/reports/transactions` además era item de sidebar.
 *
 * Las pestañas se MOVIERON, no se reescribieron:
 *  - Dashboard    → `SalesDashboardTab`, que era la página `/reports/summary`.
 *  - Transacciones / Pagos / Cotizaciones → `<TransactionsList>`, el mismo
 *    componente que el POS monta en `/pos/transacciones`; sigue siendo la
 *    única implementación del listado.
 *
 * ── Por qué las cuatro están al MISMO nivel (owner, 2026-09-11) ──
 * El listado traía sus propias pestañas (Transacciones · Pagos recibidos ·
 * Cotizaciones), así que la pantalla mostraba DOS filas de píldoras pegadas y
 * había que elegir dos veces para llegar a una sola cosa. Ahora las cuatro
 * vistas son de primer nivel y el listado recibe cuál mostrar
 * (`<TransactionsList view=…>`). El POS, que monta el mismo componente SIN esa
 * prop, sigue con sus pestañas internas: ahí no hay un nivel de arriba que
 * las absorba.
 *
 * El rango es UNO SOLO, leído acá del `useDateRange()` global y pasado a las
 * dos pestañas: dos consumidores del hook en la misma pantalla pelean por el
 * mismo estado compartido (es la razón por la que `RankingReportPage` ganó
 * `embeddedRange`), y cambiar de pestaña no tiene por qué re-preguntar el
 * período.
 *
 * Permisos: las dos vistas ya se gateaban con la MISMA clave
 * (`reports.sales.view`, el gate real de `api/v1/reports/`), así que la fusión
 * no cambia quién ve qué — ver `lib/navigation/routes.ts`.
 */

import * as React from "react"
import Link from "next/link"
import { useSearchParams } from "next/navigation"
import { ArrowLeft } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { DateRangePicker } from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { SalesDashboardTab } from "@/components/domain/reports/sales/sales-dashboard-tab"
import { TransactionsList } from "@/components/domain/transactions/transactions-list"

const TAB_IDS = ["dashboard", "transacciones", "pagos", "cotizaciones"] as const

export default function SalesReportPage() {
  const { range, setRange } = useDateRange()
  // `?tab=` deep-linkea una pestaña. Lo usan los redirects de las URLs viejas
  // y las entradas de la paleta/sidebar, que sobrevivieron a la fusión: quien
  // busca "transacciones" no tiene por qué saber que ahora vive adentro de
  // Ventas.
  const searchParams = useSearchParams()
  const requested = searchParams.get("tab")
  const initialTab =
    requested && (TAB_IDS as readonly string[]).includes(requested)
      ? requested
      : "dashboard"

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Ventas</h1>
          <p className="text-sm text-muted-foreground">
            Cómo vendió el negocio en el período: el panorama comparado con el
            período anterior, y cada venta con su documento, estado y detalle.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      <Tabs defaultValue={initialTab} className="flex flex-col gap-4">
        <TabsList>
          <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
          <TabsTrigger value="transacciones">Transacciones</TabsTrigger>
          <TabsTrigger value="pagos">Pagos</TabsTrigger>
          <TabsTrigger value="cotizaciones">Cotizaciones</TabsTrigger>
        </TabsList>

        <TabsContent value="dashboard" className="m-0">
          <SalesDashboardTab range={range} />
        </TabsContent>

        <TabsContent value="transacciones" className="m-0">
          <TransactionsList embeddedRange={range} view="transacciones" />
        </TabsContent>

        <TabsContent value="pagos" className="m-0">
          <TransactionsList embeddedRange={range} view="cobros" />
        </TabsContent>

        <TabsContent value="cotizaciones" className="m-0">
          <TransactionsList embeddedRange={range} view="quotes" />
        </TabsContent>
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
