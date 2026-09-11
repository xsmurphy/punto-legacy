"use client"

/**
 * Reporte de Órdenes — tres tabs sobre el mismo rango de fechas.
 *
 *   Dashboard → volumen, demora entre etapas, re-trabajo y demanda.
 *   Espacios  → sesiones, duración, personas y la matriz espacio × hora.
 *   Listado   → una fila por orden; clic abre el detalle (`/orders/[id]`).
 *
 * Vive acá y no en `/reports/operaciones` (decisión del owner 2026-09-10, corrige
 * la D1 de context/62): es la misma pregunta —qué pasó con las órdenes— con
 * tres cortes, y dos páginas obligaban a saber de antemano por cuál entrar.
 *
 * Cada tab pide su bloque del backend por separado (`?include=…`) y solo
 * cuando se abre: el listado no tiene por qué pagar el costo del log de
 * eventos, y los espacios son un recorrido aparte.
 *
 * El tab va en la URL (`?tab=`) para que "Volver" desde el detalle de una
 * orden caiga en el Listado, que es de donde vino, y no en el Dashboard.
 */

import * as React from "react"
import Link from "next/link"
import { usePathname, useRouter, useSearchParams } from "next/navigation"
import { AlertCircle, ArrowLeft, Lock } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { DateRangePicker, rangeToBackend } from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"
import { OrdersList } from "@/components/domain/orders/orders-list"
import { OperationsDashboardTab } from "@/components/domain/reports/orders/operations-dashboard-tab"
import { OperationsSpacesTab } from "@/components/domain/reports/orders/operations-spaces-tab"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useDateRange } from "@/hooks/use-date-range"
import { usePermission } from "@/hooks/use-permissions"
import { useReport, type OperationsReport } from "@/hooks/use-reports"
import { shiftRangeBackwards } from "@/lib/reports/previous-range"

const TABS = ["dashboard", "espacios", "listado"] as const
type TabKey = (typeof TABS)[number]

/**
 * Espejo del gate del backend. Los tres tabs pegan contra dos endpoints y los
 * dos piden la MISMA clave: `/v1/reports/operations` (dashboard y espacios) y
 * `/v1/reports/orders` (listado). Sin este espejo, un rol sin la clave veía la
 * página armarse entera y se comía los 403 de a uno por tab.
 *
 * Es también la clave del detalle al que lleva el listado (`/orders/[id]`, que
 * sí va por `/v1/orders-core?id=`), y la que ya declara la entrada de
 * navegación en `lib/navigation/routes.ts`. `requires` de ahí solo decide si la
 * ruta se OFRECE (sidebar y paleta); entrar por URL directa no pasa por ese
 * filtro, así que el gate de la página va acá.
 */
const VIEW_PERMISSION = "reports.sales.view"

function isTab(v: string | null): v is TabKey {
  return v !== null && (TABS as readonly string[]).includes(v)
}

export default function OrdersReportPage() {
  const { data: bootstrap } = useBootstrap()
  const canView = usePermission(VIEW_PERMISSION)
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])
  const previousOpts = React.useMemo(() => shiftRangeBackwards(opts.from, opts.to), [opts])

  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  const requested = searchParams.get("tab")
  const tab: TabKey = isTab(requested) ? requested : "dashboard"

  const setTab = React.useCallback(
    (v: string) => {
      const params = new URLSearchParams(searchParams.toString())
      params.set("tab", v)
      router.replace(`${pathname}?${params.toString()}`, { scroll: false })
    },
    [pathname, router, searchParams],
  )

  const current = useReport<OperationsReport>("operations", {
    ...opts,
    params: { include: "volume,stages,demand" },
    enabled: canView && tab === "dashboard",
  })
  // El período anterior de igual duración, solo lo que lleva delta.
  const previous = useReport<OperationsReport>("operations", {
    ...previousOpts,
    params: { include: "volume,stages" },
    enabled: canView && tab === "dashboard",
  })
  const spaces = useReport<OperationsReport>("operations", {
    ...opts,
    params: { include: "spaces" },
    enabled: canView && tab === "espacios",
  })

  // El listado muestra su propio error; acá solo los del tab abierto.
  const error =
    tab === "dashboard" ? current.error : tab === "espacios" ? spaces.error : null

  if (!canView) {
    return (
      <div className="flex flex-col gap-6">
        <header className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Órdenes</h1>
        </header>
        <EmptyState
          icon={Lock}
          title="No tenés acceso a este reporte"
          description="Ver órdenes requiere el permiso de reportes de ventas. Pedíselo a quien administra el comercio."
        />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Órdenes</h1>
          <p className="text-sm text-muted-foreground">
            Cuántas órdenes entraron, cuánto tardó cada etapa y cómo se usaron los
            espacios. Hacé clic en una orden del listado para ver su detalle.
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

      <Tabs value={tab} onValueChange={setTab} className="flex flex-col gap-4">
        <TabsList>
          <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
          <TabsTrigger value="espacios">Espacios</TabsTrigger>
          <TabsTrigger value="listado">Listado</TabsTrigger>
        </TabsList>

        <TabsContent value="dashboard" className="m-0">
          <OperationsDashboardTab
            report={current.data}
            previous={previous.data}
            isLoading={current.isLoading}
            bootstrap={bootstrap}
          />
        </TabsContent>

        <TabsContent value="espacios" className="m-0">
          <OperationsSpacesTab
            spaces={spaces.data?.spaces}
            isLoading={spaces.isLoading}
            bootstrap={bootstrap}
          />
        </TabsContent>

        <TabsContent value="listado" className="m-0">
          <OrdersList backHref="/reports" embeddedRange={range} />
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
