"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { BarChart3 } from "lucide-react"

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { DataTable } from "@/components/data-table/data-table"
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
}: {
  data: FinanceReportRow[]
  isLoading: boolean
  tableId: string
  exportFileName: string
  nameHeader: string
  emptyTitle: string
  emptyDescription: string
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
          <h1 className="text-2xl font-semibold">Reportes</h1>
          <p className="text-sm text-muted-foreground">
            Montos de ingresos y egresos del período por categoría o cuenta.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      <Tabs defaultValue="category">
        <TabsList>
          <TabsTrigger value="category">Por categoría</TabsTrigger>
          <TabsTrigger value="costcenter">Por centro de costo</TabsTrigger>
          <TabsTrigger value="account">Por cuenta</TabsTrigger>
        </TabsList>
        <TabsContent value="category" className="mt-4">
          <ReportTable
            tableId="finance-reports-by-category"
            data={byCategory.data?.rows ?? []}
            isLoading={byCategory.isLoading}
            exportFileName="finanzas-por-categoria"
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
