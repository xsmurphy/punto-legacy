"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import Link from "next/link"
import { ArrowLeft, BarChart3 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { DataTable } from "@/components/data-table/data-table"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { CompositionDonutChart } from "@/components/domain/reports/composition-donut-chart"
import { RankingBarChart } from "@/components/domain/reports/ranking-bar-chart"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { EmptyState } from "@/components/empty-state"
import { DateRangePicker, rangeToBackend } from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useFinanceReport, type FinanceReportRow } from "@/hooks/use-finance-reports"
import { formatMoney } from "@/lib/format"

function ReportTable({
  data,
  isLoading,
  tableId,
  exportFileName,
  nameHeader,
  emptyTitle,
  emptyDescription,
  showCode = false,
}: {
  data: FinanceReportRow[]
  isLoading: boolean
  tableId: string
  exportFileName: string
  nameHeader: string
  emptyTitle: string
  emptyDescription: string
  /** El corte por cuenta no lleva código contable; los otros dos sí. */
  showCode?: boolean
}) {
  const { data: bootstrap } = useBootstrap()

  const columns = React.useMemo<ColumnDef<FinanceReportRow>[]>(
    () => [
      {
        accessorKey: "name",
        header: nameHeader,
        cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
        meta: { label: nameHeader },
      },
      // El código va al lado del nombre y no al final: quien exporta esto lo
      // hace para cruzarlo contra el plan de cuentas, así que es parte de la
      // identidad de la fila, no un dato accesorio.
      ...(showCode
        ? [
            {
              accessorKey: "code",
              header: "Código",
              cell: ({ row }: { row: { original: FinanceReportRow } }) =>
                row.original.code ? (
                  <span className="tabular-nums text-muted-foreground">{row.original.code}</span>
                ) : (
                  <span className="text-muted-foreground">—</span>
                ),
              meta: { label: "Código" },
            } satisfies ColumnDef<FinanceReportRow>,
          ]
        : []),
      {
        accessorKey: "income",
        header: "Ingresos",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatMoney(getValue() as number, bootstrap)}</span>
        ),
        meta: { label: "Ingresos" },
      },
      {
        accessorKey: "expense",
        header: "Egresos",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatMoney(getValue() as number, bootstrap)}</span>
        ),
        meta: { label: "Egresos" },
      },
      {
        accessorKey: "net",
        header: "Neto",
        cell: ({ getValue }) => (
          <span className="font-medium tabular-nums">{formatMoney(getValue() as number, bootstrap)}</span>
        ),
        meta: { label: "Neto" },
      },
    ],
    [nameHeader, bootstrap]
  )

  return (
    <DataTable
      tableId={tableId}
      data={data}
      columns={columns}
      isLoading={isLoading}
      getRowId={(r) => r.id ?? "__sin__"}
      searchPlaceholder="Buscar…"
      exportFileName={exportFileName}
      emptyMessage={
        <EmptyState
          icon={BarChart3}
          title={emptyTitle}
          description={emptyDescription}
          showMarquee={false}
          className="border-dashed py-10"
        />
      }
    />
  )
}

export default function FinanzasReportesPage() {
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const byCategory = useFinanceReport("category", opts)
  const byAccount = useFinanceReport("account", opts)
  const byCostCenter = useFinanceReport("costcenter", opts)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
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
          <h1 className="text-2xl font-semibold">Ingresos y egresos por categoría</h1>
          <p className="text-sm text-muted-foreground">
            Montos del período abiertos por categoría, centro de costo o cuenta.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      <Tabs defaultValue="dashboard">
        <TabsList>
          <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
          <TabsTrigger value="category">Por categoría</TabsTrigger>
          <TabsTrigger value="costcenter">Por centro de costo</TabsTrigger>
          <TabsTrigger value="account">Por cuenta</TabsTrigger>
        </TabsList>
        <TabsContent value="dashboard" className="mt-4">
          <FinanceDashboard
            byCategory={byCategory.data?.rows ?? []}
            byCostCenter={byCostCenter.data?.rows ?? []}
            isLoading={byCategory.isLoading || byCostCenter.isLoading}
          />
        </TabsContent>
        <TabsContent value="category" className="mt-4">
          <ReportTable
            tableId="finance-reports-by-category"
            data={byCategory.data?.rows ?? []}
            isLoading={byCategory.isLoading}
            exportFileName="finanzas-por-categoria"
            showCode
            nameHeader="Categoría"
            emptyTitle="Sin movimientos en el período"
            emptyDescription="Ajustá el rango de fechas y volvé a consultar."
          />
        </TabsContent>
        {/* Tercer corte del MISMO reporte (mig 167) — misma tabla, mismo
            período, solo cambia la dimensión del GROUP BY. La fila "Sin centro
            de costo" sale al final: el centro es opcional, así que el
            histórico sin clasificar se acumula ahí hasta que alguien lo
            reclasifique desde /finanzas/movimientos. */}
        <TabsContent value="costcenter" className="mt-4">
          <ReportTable
            tableId="finance-reports-by-cost-center"
            data={byCostCenter.data?.rows ?? []}
            isLoading={byCostCenter.isLoading}
            exportFileName="finanzas-por-centro-de-costo"
            showCode
            nameHeader="Centro de costo"
            emptyTitle="Sin movimientos en el período"
            emptyDescription="Ajustá el rango de fechas y volvé a consultar."
          />
        </TabsContent>
        <TabsContent value="account" className="mt-4">
          <ReportTable
            tableId="finance-reports-by-account"
            data={byAccount.data?.rows ?? []}
            isLoading={byAccount.isLoading}
            exportFileName="finanzas-por-cuenta"
            nameHeader="Cuenta"
            emptyTitle="Sin movimientos en el período"
            emptyDescription="Ajustá el rango de fechas y volvé a consultar."
          />
        </TabsContent>
      </Tabs>
    </div>
  )
}

/**
 * Dashboard del período — sale de los mismos cortes que las pestañas, sin
 * consultas nuevas.
 *
 * Los totales se toman de UN solo corte (categoría) y no de los tres: cada
 * corte reparte el MISMO conjunto de movimientos con otra dimensión, así que
 * sumar categoría + centro de costo + cuenta daría el triple. Categoría porque
 * todo movimiento cae en alguna o en la fila de "sin clasificar", o sea que su
 * suma es el total del período.
 *
 * "Sin clasificar" es un KPI propio y no un detalle: un egreso sin categoría
 * es un egreso que el corte por categoría no puede explicar, y el porcentaje
 * dice cuánto del gráfico de al lado está a ciegas.
 */
function FinanceDashboard({
  byCategory,
  byCostCenter,
  isLoading,
}: {
  byCategory: FinanceReportRow[]
  byCostCenter: FinanceReportRow[]
  isLoading: boolean
}) {
  const { data: bootstrap } = useBootstrap()
  const money = React.useCallback((v: number) => formatMoney(v, bootstrap), [bootstrap])

  const totals = React.useMemo(() => {
    let income = 0
    let expense = 0
    let unclassifiedExpense = 0
    for (const r of byCategory) {
      income += r.income
      expense += r.expense
      if (r.id === null) unclassifiedExpense += r.expense
    }
    return {
      income,
      expense,
      net: income - expense,
      unclassifiedPct: expense > 0 ? (unclassifiedExpense / expense) * 100 : 0,
    }
  }, [byCategory])

  if (isLoading) {
    return (
      <div className="grid gap-4 lg:grid-cols-2">
        {[0, 1].map((i) => <Skeleton key={i} className="h-[280px] w-full" />)}
      </div>
    )
  }

  if (byCategory.length === 0) {
    return (
      <EmptyState
        icon={BarChart3}
        title="Sin movimientos en el período"
        description="Ajustá el rango de fechas y volvé a consultar."
      />
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <StatsRow>
        <StatTile label="Ingresos" value={money(totals.income)} />
        <StatTile label="Egresos" value={money(totals.expense)} />
        <StatTile
          label="Neto"
          value={money(totals.net)}
          tone={totals.net < 0 ? "negative" : totals.net > 0 ? "positive" : "neutral"}
          emphasis
        />
        <StatTile
          label="Egresos sin categoría"
          value={`${totals.unclassifiedPct.toFixed(1)}%`}
          tone={totals.unclassifiedPct > 20 ? "negative" : "neutral"}
        />
      </StatsRow>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold tracking-tight">Egresos por categoría</CardTitle>
            <CardDescription className="text-xs">En qué se va la plata del período.</CardDescription>
          </CardHeader>
          <CardContent>
            <CompositionDonutChart
              data={byCategory.map((r) => ({ label: r.name, value: r.expense }))}
              formatValue={money}
              restLabel="Otras categorías"
            />
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold tracking-tight">Egresos por centro de costo</CardTitle>
            <CardDescription className="text-xs">Qué área del negocio gasta más.</CardDescription>
          </CardHeader>
          <CardContent>
            <RankingBarChart
              data={byCostCenter.map((r) => ({ label: r.name, value: r.expense }))}
              valueLabel="Egresos"
              formatValue={money}
            />
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
