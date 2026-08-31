"use client"

/**
 * Reporte de Análisis de Clientes — tres tabs sobre `/v1/reports/customers`.
 *
 *   Dashboard  → composición de la cartera, tasas y comportamiento de compra.
 *   Listado    → una fila por cliente con todas sus métricas (DataTable).
 *   Geográfico → dónde residen: localidades, ciudades y mapa de calor.
 *
 * Cada sección del backend se pide por separado (`?include=…`) en vez de en un
 * solo blob:
 *   - `rows` sin `include` es el shape histórico del endpoint, que ya consumen
 *     las read-tools del agente / MCP — no debían empezar a pagar el costo de
 *     las secciones nuevas;
 *   - `geo` recorre el padrón entero de clientes, así que solo se dispara
 *     cuando el usuario abre ese tab (`enabled`);
 *   - `geo` además NO lleva rango de fechas: dónde vive un cliente no depende
 *     del período (ver `CustomersService::geography`).
 */

import * as React from "react"
import Link from "next/link"
import { AlertCircle, ArrowLeft } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type CustomerRow,
  type CustomersDashboard,
  type CustomersGeo,
} from "@/hooks/use-reports"
import { CustomersDashboardTab } from "@/components/domain/reports/customers/customers-dashboard-tab"
import { CustomersGeoTab } from "@/components/domain/reports/customers/customers-geo-tab"
import { CustomersListTab } from "@/components/domain/reports/customers/customers-list-tab"

type TabKey = "dashboard" | "listado" | "geografico"

export default function CustomersReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])
  const [tab, setTab] = React.useState<TabKey>("dashboard")

  const listado = useReport<{ rows: CustomerRow[] }>("customers", opts)
  const dashboard = useReport<{ dashboard: CustomersDashboard }>("customers", {
    ...opts,
    params: { include: "dashboard" },
  })
  // El tab geográfico recorre todo el padrón: se pide recién cuando se abre, y
  // queda cacheado por TanStack Query si el usuario vuelve.
  const geo = useReport<{ geo: CustomersGeo }>("customers", {
    params: { include: "geo" },
    enabled: tab === "geografico",
  })

  const rows = React.useMemo(() => listado.data?.rows ?? [], [listado.data])

  // El rango solo afecta a dashboard y listado; el error del tab geográfico se
  // muestra dentro de su propio tab para no gritar en los otros dos.
  const error = listado.error ?? dashboard.error ?? null

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Análisis de Clientes</h1>
          <p className="text-sm text-muted-foreground">
            Quiénes son, cuánto compran y en qué zonas viven.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-sm text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      <Tabs
        value={tab}
        onValueChange={(v) => setTab(v as TabKey)}
        className="flex flex-col gap-4"
      >
        <TabsList>
          <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
          <TabsTrigger value="listado">Listado</TabsTrigger>
          <TabsTrigger value="geografico">Geográfico</TabsTrigger>
        </TabsList>

        <TabsContent value="dashboard" className="m-0">
          <CustomersDashboardTab
            dashboard={dashboard.data?.dashboard}
            rows={rows}
            isLoading={dashboard.isLoading || listado.isLoading}
            bootstrap={bootstrap}
          />
        </TabsContent>

        <TabsContent value="listado" className="m-0">
          <CustomersListTab
            rows={rows}
            isLoading={listado.isLoading}
            bootstrap={bootstrap}
          />
        </TabsContent>

        <TabsContent value="geografico" className="m-0">
          {geo.error ? (
            <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
              <AlertCircle className="mt-0.5 size-4 text-destructive" />
              <div>
                <p className="font-medium">
                  No se pudo cargar el análisis geográfico
                </p>
                <p className="text-sm text-muted-foreground">
                  {geo.error.message}
                </p>
              </div>
            </div>
          ) : (
            <CustomersGeoTab
              geo={geo.data?.geo}
              isLoading={geo.isLoading}
              bootstrap={bootstrap}
            />
          )}
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
      className="w-fit h-7 -ml-2 text-sm text-muted-foreground hover:text-foreground"
    >
      <Link href="/reports">
        <ArrowLeft className="size-3.5" />
        Volver a reportes
      </Link>
    </Button>
  )
}
