"use client"

/**
 * Reporte de Producción — espejo de panel/reports/production.html.
 *
 * Backend: GET /v1/reports/production?view=general&from=&to=
 * → { rows: [...], totals: { qty, cogs, utility } }
 *
 * Pestañas (F1 de context/76, 2026-09-10): Dashboard, Productos (la vista
 * `general` que había antes), Consumos, Mermas y Órdenes. `view=compound` NO
 * se muestra: mezcla la tabla legacy `production`, que nada escribe hoy.
 */

import * as React from "react"
import Link from "next/link"
import { AlertCircle, ArrowLeft, Factory } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { useSearchParams } from "next/navigation"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { ProductionDashboardTab } from "@/components/domain/reports/production/production-dashboard-tab"
import { ProductionConsumptionTab } from "@/components/domain/reports/production/production-consumption-tab"
import { ProductionWasteTab } from "@/components/domain/reports/production/production-waste-tab"
import { ProductionOrdersTab } from "@/components/domain/reports/production/production-orders-tab"
import { useDateRange } from "@/hooks/use-date-range"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type ProductionReportResponse } from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { StatsRow, StatTile } from "@/components/stat-tile"

const TAB_IDS = ["dashboard", "productos", "consumos", "mermas", "ordenes"] as const

export default function ProductionReportPage() {
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
          <h1 className="text-2xl font-semibold">Producción</h1>
          <p className="text-sm text-muted-foreground">
            Qué se produjo, cuánto insumo se consumió, cuánto se perdió y con qué rendimiento.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {/* F1 de context/76: todas las pestañas salen de vistas que el backend
          ya tenía o de dato que ya se guarda. Desvío contra receta, merma de
          insumos y responsables necesitan F0 (empezar a guardar el dato). */}
      <Tabs defaultValue={initialTab} className="flex flex-col gap-4">
        <TabsList>
          <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
          <TabsTrigger value="productos">Productos</TabsTrigger>
          <TabsTrigger value="consumos">Consumos</TabsTrigger>
          <TabsTrigger value="mermas">Mermas</TabsTrigger>
          <TabsTrigger value="ordenes">Órdenes</TabsTrigger>
        </TabsList>
        <TabsContent value="dashboard" className="m-0"><ProductionDashboardTab range={range} /></TabsContent>
        <TabsContent value="productos" className="m-0"><ProductsTab range={range} /></TabsContent>
        <TabsContent value="consumos" className="m-0"><ProductionConsumptionTab range={range} /></TabsContent>
        <TabsContent value="mermas" className="m-0"><ProductionWasteTab range={range} /></TabsContent>
        <TabsContent value="ordenes" className="m-0"><ProductionOrdersTab range={range} /></TabsContent>
      </Tabs>
    </div>
  )
}

function ProductsTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "general" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<ProductionReportResponse>("production", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])
  const totals = data?.totals

  const columns = React.useMemo(
    () => [
      {
        accessorKey: "name",
        header: "Producto",
        cell: ({ row }: { row: { original: { name: string; sku: string; category: string; typeLabel: string } } }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span className="font-medium">{r.name || "(sin nombre)"}</span>
              <div className="flex items-center gap-1.5 mt-0.5">
                {r.sku && (
                  <span className="text-[10px] text-muted-foreground">{r.sku}</span>
                )}
                {r.category && (
                  <span className="text-[10px] text-muted-foreground">· {r.category}</span>
                )}
              </div>
            </div>
          )
        },
        meta: { label: "Producto" },
      },
      {
        accessorKey: "typeLabel",
        header: "Tipo",
        cell: ({ getValue }: { getValue: () => unknown }) => (
          <Badge variant="secondary" className="text-[10px]">
            {(getValue() as string) || "—"}
          </Badge>
        ),
        meta: { label: "Tipo" },
      },
      {
        accessorKey: "units",
        header: "Unidades",
        cell: ({ getValue }: { getValue: () => unknown }) => (
          <span className="tabular-nums font-medium">
            {formatInt(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Unidades", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "average",
        header: "Costo prom.",
        cell: ({ getValue }: { getValue: () => unknown }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Costo prom.", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "cogs",
        header: "Costo total",
        cell: ({ getValue }: { getValue: () => unknown }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Costo total", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "utility",
        header: "Utilidad",
        cell: ({ getValue }: { getValue: () => unknown }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Utilidad", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {!isLoading && totals && totals.qty > 0 && (
        <StatsRow>
          <StatTile label="Unidades producidas" value={formatInt(totals.qty, bootstrap)} />
          <StatTile
            label="Costo total"
            value={formatMoney(totals.cogs, bootstrap)}
          />
          <StatTile
            label="Utilidad"
            value={formatMoney(totals.utility, bootstrap)}
            emphasis
          />
        </StatsRow>
      )}

      <DataTable
        tableId="report-production"
        data={rows}
        columns={columns}
        getRowId={(r) => r.itemId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por producto, categoría…"
        exportFileName="reporte_produccion"
        emptyMessage={
          <EmptyState
            icon={Factory}
            title="Sin registros de producción"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
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
