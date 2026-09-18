"use client"

import * as React from "react"
import { useSearchParams, useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { CalendarDays } from "lucide-react"
import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Line,
  ReferenceLine,
  XAxis,
  YAxis,
} from "recharts"
import { Button } from "@/components/ui/button"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type SummaryYearResponse,
  type SummaryYearMonth,
} from "@/hooks/use-reports"
import { BackLink } from "@/components/page/back-link"
import { formatInt, formatMoney } from "@/lib/format"

const MONTH_NAMES = [
  "Enero",
  "Febrero",
  "Marzo",
  "Abril",
  "Mayo",
  "Junio",
  "Julio",
  "Agosto",
  "Septiembre",
  "Octubre",
  "Noviembre",
  "Diciembre",
]
const chartConfig = {
  income: { label: "Ingresos netos", color: "var(--chart-1)" },
  expensesTotal: { label: "Egresos por compras", color: "var(--chart-3)" },
  result: { label: "Resultado", color: "var(--chart-5)" },
} satisfies ChartConfig

type MonthlyRow = SummaryYearMonth & {
  name: string
  income: number
  result: number
}

export default function SummaryYearPage() {
  return (
    <React.Suspense fallback={null}>
      <AnnualReport />
    </React.Suspense>
  )
}

function AnnualReport() {
  const { data: bootstrap } = useBootstrap()
  const router = useRouter()
  const search = useSearchParams()
  const currentYear = Number(
    new Intl.DateTimeFormat("en", {
      year: "numeric",
      timeZone: bootstrap?.timezone || "UTC",
    }).format(new Date())
  )
  const permissions = bootstrap?.user?.permissions
  const canView = Boolean(
    permissions?.includes("reports.sales.view") &&
    permissions?.includes("reports.purchases.view")
  )
  const requestedYear = search.get("year")
  const year =
    requestedYear && /^[1-9]\d{3}$/.test(requestedYear)
      ? Number(requestedYear)
      : currentYear
  const { data, isLoading, error, refetch } = useReport<SummaryYearResponse>(
    "summary_year",
    { params: { y: String(year) }, enabled: canView }
  )
  const years = [...new Set([year, currentYear, ...(data?.years ?? [])])].sort(
    (a, b) => b - a
  )
  const rows = React.useMemo<MonthlyRow[]>(
    () =>
      (data?.months ?? []).map((m) => {
        // Misma definición de ventas netas que el Dashboard de Ventas.
        const income = m.salesTotal - m.discount - m.returnsTotal
        return {
          ...m,
          name: MONTH_NAMES[m.month - 1],
          income,
          result: income - m.expensesTotal,
        }
      }),
    [data]
  )
  const totals = rows.reduce(
    (sum, row) => ({
      income: sum.income + row.income,
      expenses: sum.expenses + row.expensesTotal,
      result: sum.result + row.result,
      count: sum.count + row.count,
    }),
    { income: 0, expenses: 0, result: 0, count: 0 }
  )
  const columns = React.useMemo<ColumnDef<MonthlyRow, unknown>[]>(() => {
    const money = (
      key: keyof MonthlyRow,
      label: string
    ): ColumnDef<MonthlyRow, unknown> => ({
      accessorKey: key,
      header: label,
      cell: ({ getValue }) => (
        <span className="tabular-nums">
          {formatMoney(Number(getValue()), bootstrap)}
        </span>
      ),
      meta: {
        footerSum: true,
        footerFormat: (n: number) => formatMoney(n, bootstrap),
      },
    })
    return [
      {
        accessorKey: "name",
        header: "Mes",
        sortingFn: (a, b) => a.original.month - b.original.month,
        cell: ({ row }) => (
          <span className="font-medium">{row.original.name}</span>
        ),
      },
      {
        accessorKey: "count",
        header: "Ventas",
        cell: ({ getValue }) => formatInt(Number(getValue()), bootstrap),
        meta: { footerSum: true },
      },
      money("salesTotal", "Ventas brutas"),
      money("discount", "Descuentos"),
      money("returnsTotal", "Devoluciones"),
      money("income", "Ingresos netos"),
      money("purchaseReturnsTotal", "Devoluciones de compra"),
      money("expensesTotal", "Egresos por compras"),
      money("result", "Resultado"),
    ]
  }, [bootstrap])

  if (permissions && !canView)
    return (
      <EmptyState
        icon={CalendarDays}
        title="Sin acceso al resumen anual"
        description="Necesitás permisos para consultar reportes de ventas y compras."
      />
    )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink href="/reports" label="Volver a reportes" />
          <h1 className="text-2xl font-semibold">Resumen anual</h1>
          <p className="text-sm text-muted-foreground">
            Ingresos, egresos y resultado, mes a mes del año seleccionado.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <label htmlFor="report-year" className="text-sm font-medium">
            Año
          </label>
          <Select
            value={String(year)}
            onValueChange={(value) => {
              const params = new URLSearchParams(search.toString())
              params.set("year", value)
              router.replace(`/reports/summary-year?${params}`, {
                scroll: false,
              })
            }}
          >
            <SelectTrigger id="report-year" className="w-32">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {years.map((y) => (
                <SelectItem key={y} value={String(y)}>
                  {y}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </header>
      {error ? (
        <Card>
          <CardContent className="flex flex-col gap-3 pt-6">
            <EmptyState
              icon={CalendarDays}
              title="No se pudo cargar el resumen"
              description={error.message}
            />
            <Button variant="outline" onClick={() => void refetch()}>
              Reintentar
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          <StatsRow>
            <StatTile
              label="Ingresos netos"
              value={formatMoney(totals.income, bootstrap)}
              isLoading={isLoading || !bootstrap}
            />
            <StatTile
              label="Egresos por compras"
              value={formatMoney(totals.expenses, bootstrap)}
              isLoading={isLoading || !bootstrap}
            />
            <StatTile
              label="Resultado"
              value={formatMoney(totals.result, bootstrap)}
              isLoading={isLoading || !bootstrap}
              emphasis
            />
            <StatTile
              label="Ventas"
              value={formatInt(totals.count, bootstrap)}
              isLoading={isLoading || !bootstrap}
            />
          </StatsRow>
          <Card>
            <CardHeader>
              <CardTitle>Evolución mensual · {year}</CardTitle>
              <CardDescription>
                Ingresos netos de descuentos y devoluciones. Compras netas de
                notas de crédito, según la fecha del documento y sin duplicar
                sus pagos.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <ChartContainer config={chartConfig} className="h-80 w-full">
                <ComposedChart
                  data={rows}
                  accessibilityLayer
                  margin={{ top: 8, right: 12, left: 24, bottom: 0 }}
                >
                  <CartesianGrid vertical={false} />
                  <XAxis
                    dataKey="name"
                    tickFormatter={(name: string) => name.slice(0, 3)}
                    tickLine={false}
                    axisLine={false}
                    minTickGap={8}
                  />
                  <YAxis
                    tickLine={false}
                    axisLine={false}
                    tickFormatter={(value: number) =>
                      formatInt(value, bootstrap)
                    }
                    width={85}
                  />
                  <ReferenceLine y={0} stroke="var(--border)" />
                  <ChartTooltip
                    content={
                      <ChartTooltipContent
                        formatter={(value, name) =>
                          `${chartConfig[name as keyof typeof chartConfig]?.label ?? name}: ${formatMoney(Number(value), bootstrap)}`
                        }
                      />
                    }
                  />
                  <ChartLegend content={<ChartLegendContent />} />
                  <Bar
                    dataKey="income"
                    fill="var(--color-income)"
                    radius={[3, 3, 0, 0]}
                  />
                  <Line
                    type="linear"
                    dataKey="expensesTotal"
                    stroke="var(--color-expensesTotal)"
                    strokeWidth={2}
                    dot={false}
                  />
                  <Line
                    type="linear"
                    dataKey="result"
                    stroke="var(--color-result)"
                    strokeWidth={2}
                    dot={false}
                  />
                </ComposedChart>
              </ChartContainer>
            </CardContent>
          </Card>
          <section className="flex flex-col gap-3">
            <h2 className="text-base font-semibold tracking-tight">
              Comparativo mensual
            </h2>
            <DataTable
              tableId="report-summary-year-months"
              columns={columns}
              data={rows}
              getRowId={(row) => String(row.month)}
              isLoading={isLoading || !bootstrap}
              pageSize={25}
              exportFileName={`resumen-anual-${year}`}
              searchPlaceholder="Buscar mes…"
            />
            <p className="text-sm text-muted-foreground">
              Resultado = ingresos netos − compras netas; no es utilidad ni
              margen de los artículos vendidos. La devolución de compra (nota
              de crédito del proveedor) resta de los egresos. No incluye
              movimientos manuales de caja ni gastos de Finanzas. Los meses
              sin movimientos se muestran en cero.
            </p>
          </section>
        </>
      )}
    </div>
  )
}
