"use client"

/**
 * Pestaña "Bolsillos" de `/reports/sales` — el reporte de la wallet
 * (context/74 §13). Visible solo con el módulo `wallet` activo; el gate real
 * es el de `api/v1/reports/wallet.php` (`reports.sales.view` + módulo).
 *
 * Por qué dentro de Ventas y no un reporte suelto (owner: acoplar a lo que ya
 * existe): la carga ES una venta y el consumo es la otra mitad de esa misma
 * plata; quien mira cómo vendió el negocio es quien tiene que ver cuánto de
 * eso sigue debiendo en saldo y cómo se entregó.
 *
 * Es además el CONTROL de los consumos con saldo: el importe de un consumo lo
 * manda la caja y no pasa por el arqueo ni por el margen (D12), así que un
 * precio bajado solo se ve acá. "Diferencias" compara lo debitado contra el
 * valor de lista congelado en cada línea (mig 235) y separa lo que la caja
 * declaró como descuento de lo que no tiene explicación.
 *
 * Arquetipo Reporte (context/84 §4): KPIs grises con comparación contra el
 * período anterior → gráfico en card blanca → detalle. El rango llega por
 * prop desde la página (un solo `useDateRange()` por pantalla).
 */

import * as React from "react"
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts"
import { AlertCircle, WalletCards } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport } from "@/hooks/use-reports"
import { pctDelta, shiftRangeBackwards } from "@/lib/reports/previous-range"
import { formatInt, formatMoney } from "@/lib/format"
import { formatQty } from "@/lib/format-qty"
import { formatDateTime } from "@/lib/format-date"
import { cn } from "@/lib/utils"
import {
  bucketTooltipLabel,
  formatBucketTick,
  perUnit,
  tooltipPoint,
  type Granularity,
  type TimeBucket,
} from "@/lib/charts/granularity"
import { partialBarCells } from "@/components/domain/reports/partial-bar-cells"

// ── Contrato de /v1/reports/wallet ──────────────────────────────────────────

interface WalletSummary {
  loaded: number
  consumed: number
  consumptions: number
  liability: number
  differences: {
    unexplained: number
    withDiscount: number
    count: number
    unexplainedCount: number
  }
}

interface DifferenceRow {
  transactionId: string
  date: string
  number: number | null
  userName: string
  registerName: string
  customerName: string
  pocketName: string
  listValue: number
  charged: number
  discount: number
  unexplained: number
}

interface DifferenceGroup {
  id: string
  name: string
  count: number
  discount: number
  unexplained: number
}

interface WalletReport {
  summary: WalletSummary
  /** Cargado y consumido por período: día / semana / mes según el rango (servidor). */
  series: {
    granularity: Granularity
    points: Array<TimeBucket & { loaded: number; consumed: number }>
  }
  byProduct: Array<{ itemId: string; name: string; units: number; value: number }>
  byPocket: Array<{
    pocketId: string
    name: string
    active: boolean
    loaded: number
    consumed: number
    balance: number
  }>
  differences: {
    rows: DifferenceRow[]
    byUser: DifferenceGroup[]
    byRegister: DifferenceGroup[]
  }
}

type Bootstrap = ReturnType<typeof useBootstrap>["data"]

// ── Pestaña ─────────────────────────────────────────────────────────────────

export function SalesWalletTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const current = React.useMemo(() => rangeToBackend(range), [range])
  const previous = React.useMemo(
    () => shiftRangeBackwards(current.from, current.to),
    [current],
  )

  const report = useReport<WalletReport>("wallet", {
    from: current.from,
    to: current.to,
    params: { dataset: "full" },
  })
  const prev = useReport<WalletSummary>("wallet", {
    from: previous.from,
    to: previous.to,
    params: { dataset: "summary" },
  })

  const s = report.data?.summary
  const p = prev.data
  const loading = report.isLoading

  return (
    <div className="flex flex-col gap-6">
      {report.error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{report.error.message}</p>
          </div>
        </div>
      )}

      <StatsRow>
        <StatTile
          label="Cargado"
          value={formatMoney(s?.loaded, bootstrap)}
          delta={{ pct: pctDelta(s?.loaded ?? 0, p?.loaded ?? 0) }}
          isLoading={loading}
        />
        <StatTile
          label="Consumido"
          value={formatMoney(s?.consumed, bootstrap)}
          delta={{ pct: pctDelta(s?.consumed ?? 0, p?.consumed ?? 0) }}
          isLoading={loading}
        />
        <StatTile
          label="Saldo por entregar"
          value={formatMoney(s?.liability, bootstrap)}
          delta={{ pct: pctDelta(s?.liability ?? 0, p?.liability ?? 0) }}
          isLoading={loading}
        />
        <StatTile
          label="Diferencias detectadas"
          value={formatMoney(s?.differences.unexplained, bootstrap)}
          tone={(s?.differences.unexplained ?? 0) > 0 ? "negative" : "neutral"}
          delta={{
            pct: pctDelta(s?.differences.unexplained ?? 0, p?.differences.unexplained ?? 0),
            higherIsBetter: false,
          }}
          emphasis
          isLoading={loading}
        />
      </StatsRow>

      <SeriesChart
        data={report.data?.series.points ?? []}
        granularity={report.data?.series.granularity ?? "day"}
        isLoading={loading}
        bootstrap={bootstrap}
      />

      <DifferencesSection
        data={report.data?.differences}
        summary={s}
        isLoading={loading}
        bootstrap={bootstrap}
      />

      <PocketsCard rows={report.data?.byPocket ?? []} isLoading={loading} bootstrap={bootstrap} />

      <ProductsCard rows={report.data?.byProduct ?? []} isLoading={loading} bootstrap={bootstrap} />
    </div>
  )
}

// ── Gráfico: cargado vs consumido por día ───────────────────────────────────

const seriesChartConfig = {
  loaded: { label: "Cargado", color: "var(--chart-1)" },
  consumed: { label: "Consumido", color: "var(--chart-3)" },
} satisfies ChartConfig

function SeriesChart({
  data,
  granularity,
  isLoading,
  bootstrap,
}: {
  data: WalletReport["series"]["points"]
  granularity: Granularity
  isLoading: boolean
  bootstrap: Bootstrap
}) {
  const empty = !isLoading && data.every((d) => d.loaded === 0 && d.consumed === 0)
  return (
    <Card>
      <CardHeader>
        <CardTitle>{perUnit("Cargado y consumido", granularity)}</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-[260px] w-full" />
        ) : empty ? (
          <p className="text-sm text-muted-foreground">Sin cargas ni consumos en el período.</p>
        ) : (
          <ChartContainer config={seriesChartConfig} className="h-[260px] w-full">
            <BarChart data={data} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
              <XAxis
                dataKey="bucket"
                tickFormatter={(v: string) => formatBucketTick(String(v), granularity)}
                tick={{ fontSize: 10, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                tick={{ fontSize: 10, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
                tickFormatter={(v: number) => compactNumber(v)}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={
                  <ChartTooltipContent
                    labelFormatter={(label, payload) =>
                      bucketTooltipLabel(tooltipPoint(payload), granularity) ||
                      formatBucketTick(String(label), granularity)
                    }
                    formatter={(value, name) => (
                      <div className="flex w-full items-center justify-between gap-3">
                        <span className="text-muted-foreground">
                          {seriesChartConfig[name as keyof typeof seriesChartConfig]?.label ?? name}
                        </span>
                        <span className="font-medium tabular-nums">
                          {formatMoney(Number(value) || 0, bootstrap)}
                        </span>
                      </div>
                    )}
                  />
                }
              />
              <ChartLegend content={<ChartLegendContent />} />
              <Bar dataKey="loaded" fill="var(--color-loaded)" radius={[4, 4, 0, 0]} maxBarSize={28}>
                {partialBarCells(data)}
              </Bar>
              <Bar dataKey="consumed" fill="var(--color-consumed)" radius={[4, 4, 0, 0]} maxBarSize={28}>
                {partialBarCells(data)}
              </Bar>
            </BarChart>
          </ChartContainer>
        )}
      </CardContent>
    </Card>
  )
}

// ── Diferencias: el control ─────────────────────────────────────────────────

function DifferencesSection({
  data,
  summary,
  isLoading,
  bootstrap,
}: {
  data: WalletReport["differences"] | undefined
  summary: WalletSummary | undefined
  isLoading: boolean
  bootstrap: Bootstrap
}) {
  const columns = React.useMemo<ColumnDef<DifferenceRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatDateTime(getValue() as string, "d MMM yyyy HH:mm")}</span>
        ),
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "number",
        header: "Número",
        cell: ({ getValue }) => {
          const v = getValue() as number | null
          return <span className="tabular-nums">{v !== null ? v : "—"}</span>
        },
        meta: { label: "Número" },
      },
      { accessorKey: "userName", header: "Usuario", meta: { label: "Usuario" } },
      { accessorKey: "registerName", header: "Caja", meta: { label: "Caja" } },
      { accessorKey: "customerName", header: "Cliente", meta: { label: "Cliente" } },
      { accessorKey: "pocketName", header: "Bolsillo", meta: { label: "Bolsillo" } },
      moneyColumn<DifferenceRow>("listValue", "Valor de lista", bootstrap),
      moneyColumn<DifferenceRow>("charged", "Cobrado", bootstrap),
      moneyColumn<DifferenceRow>("discount", "Con descuento", bootstrap),
      moneyColumn<DifferenceRow>("unexplained", "Sin descuento", bootstrap, true),
    ],
    [bootstrap],
  )

  const rows = data?.rows ?? []
  const none = !isLoading && (summary?.differences.count ?? 0) === 0

  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-xl font-semibold">Diferencias contra el precio de lista</h2>
      {none ? (
        <p className="text-sm text-muted-foreground">Sin diferencias en el período.</p>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <GroupCard title="Por usuario" rows={data?.byUser ?? []} isLoading={isLoading} bootstrap={bootstrap} />
            <GroupCard title="Por caja" rows={data?.byRegister ?? []} isLoading={isLoading} bootstrap={bootstrap} />
          </div>
          <DataTable<DifferenceRow>
            tableId="reports-wallet-differences"
            data={rows}
            columns={columns}
            getRowId={(r) => r.transactionId}
            isLoading={isLoading}
            searchPlaceholder="Buscar por usuario, caja, cliente…"
            exportFileName="bolsillos_diferencias"
            emptyMessage={
              <EmptyState icon={WalletCards} title="Sin diferencias en el período" />
            }
          />
        </>
      )}
    </section>
  )
}

/**
 * Diferencias agrupadas (por usuario o por caja). Lista corta embebida: con
 * menos de 10 filas va en `divide-y`, desde 10 en `DataTable` (context/84 T18).
 */
function GroupCard({
  title,
  rows,
  isLoading,
  bootstrap,
}: {
  title: string
  rows: DifferenceGroup[]
  isLoading: boolean
  bootstrap: Bootstrap
}) {
  const columns = React.useMemo<ColumnDef<DifferenceGroup>[]>(
    () => [
      {
        accessorKey: "name",
        header: title === "Por caja" ? "Caja" : "Usuario",
        cell: ({ getValue }) => (getValue() as string) || "—",
        meta: { label: title === "Por caja" ? "Caja" : "Usuario" },
      },
      {
        accessorKey: "count",
        header: "Consumos",
        cell: ({ getValue }) => <span className="tabular-nums">{formatInt(getValue() as number, bootstrap)}</span>,
        meta: { label: "Consumos", className: "text-right" },
      },
      moneyColumn<DifferenceGroup>("discount", "Con descuento", bootstrap),
      moneyColumn<DifferenceGroup>("unexplained", "Sin descuento", bootstrap, true),
    ],
    [bootstrap, title],
  )

  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-5 w-full" />
            ))}
          </div>
        ) : rows.length >= 10 ? (
          <DataTable<DifferenceGroup>
            tableId={title === "Por caja" ? "reports-wallet-by-register" : "reports-wallet-by-user"}
            data={rows}
            columns={columns}
            getRowId={(r) => r.id || r.name}
            exportFileName={title === "Por caja" ? "bolsillos_diferencias_por_caja" : "bolsillos_diferencias_por_usuario"}
          />
        ) : (
          <div className="flex flex-col divide-y">
            {rows.map((g) => (
              <div key={g.id || g.name} className="flex items-center justify-between gap-3 py-2 text-sm">
                <div className="flex min-w-0 flex-col">
                  <span className="truncate font-medium">{g.name || "—"}</span>
                  <span className="text-xs text-muted-foreground">
                    {formatInt(g.count, bootstrap)} {g.count === 1 ? "consumo" : "consumos"}
                    {g.discount > 0 && ` · ${formatMoney(g.discount, bootstrap)} con descuento`}
                  </span>
                </div>
                <span
                  className={cn(
                    "shrink-0 font-medium tabular-nums",
                    g.unexplained > 0 && "text-destructive",
                  )}
                >
                  {formatMoney(g.unexplained, bootstrap)}
                </span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

// ── Por bolsillo ────────────────────────────────────────────────────────────

function PocketsCard({
  rows,
  isLoading,
  bootstrap,
}: {
  rows: WalletReport["byPocket"]
  isLoading: boolean
  bootstrap: Bootstrap
}) {
  const total = rows.reduce(
    (acc, r) => ({
      loaded: acc.loaded + r.loaded,
      consumed: acc.consumed + r.consumed,
      balance: acc.balance + r.balance,
    }),
    { loaded: 0, consumed: 0, balance: 0 },
  )

  return (
    <Card>
      <CardHeader>
        <CardTitle>Por bolsillo</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-24 w-full" />
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">Sin bolsillos.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Bolsillo</TableHead>
                <TableHead className="text-right">Cargado</TableHead>
                <TableHead className="text-right">Consumido</TableHead>
                <TableHead className="text-right">Saldo por entregar</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((r) => (
                <TableRow key={r.pocketId}>
                  <TableCell className={cn(!r.active && "text-muted-foreground")}>{r.name}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(r.loaded, bootstrap)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(r.consumed, bootstrap)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(r.balance, bootstrap)}</TableCell>
                </TableRow>
              ))}
              {rows.length > 1 && (
                <TableRow className="bg-muted/50 font-semibold hover:bg-muted/50">
                  <TableCell>Total</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(total.loaded, bootstrap)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(total.consumed, bootstrap)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(total.balance, bootstrap)}</TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}

// ── Consumido por producto ──────────────────────────────────────────────────

function ProductsCard({
  rows,
  isLoading,
  bootstrap,
}: {
  rows: WalletReport["byProduct"]
  isLoading: boolean
  bootstrap: Bootstrap
}) {
  const columns = React.useMemo<ColumnDef<WalletReport["byProduct"][number]>[]>(
    () => [
      { accessorKey: "name", header: "Producto", meta: { label: "Producto" } },
      {
        accessorKey: "units",
        header: "Unidades",
        cell: ({ getValue }) => <span className="tabular-nums">{formatQty(getValue() as number, bootstrap)}</span>,
        meta: { label: "Unidades", className: "text-right" },
      },
      moneyColumn<WalletReport["byProduct"][number]>("value", "Valor", bootstrap),
    ],
    [bootstrap],
  )

  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-xl font-semibold">Consumido por producto</h2>
      <DataTable<WalletReport["byProduct"][number]>
        tableId="reports-wallet-products"
        data={rows}
        columns={columns}
        getRowId={(r) => r.itemId}
        isLoading={isLoading}
        searchPlaceholder="Buscar producto…"
        exportFileName="bolsillos_consumido_por_producto"
        emptyMessage={<EmptyState icon={WalletCards} title="Sin consumos en el período" />}
      />
    </section>
  )
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function moneyColumn<T>(
  key: keyof T & string,
  label: string,
  bootstrap: Bootstrap,
  alert = false,
): ColumnDef<T> {
  return {
    accessorKey: key,
    header: label,
    cell: ({ getValue }) => {
      const v = Number(getValue()) || 0
      return (
        <span className={cn("font-medium tabular-nums", alert && v > 0 && "text-destructive")}>
          {formatMoney(v, bootstrap)}
        </span>
      )
    },
    meta: { label, className: "text-right" },
  }
}

function compactNumber(v: number): string {
  if (Math.abs(v) >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (Math.abs(v) >= 1_000) return `${(v / 1_000).toFixed(0)}k`
  return String(Math.round(v))
}
