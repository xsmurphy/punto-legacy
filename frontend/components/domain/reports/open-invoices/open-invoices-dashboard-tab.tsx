"use client"

/**
 * Dashboard de Cuentas por cobrar y pagar — las dos puntas del crédito juntas.
 *
 * Una sola llamada (`?view=summary`) trae los dos lados: el neto entre lo que
 * entra y lo que sale es el número que el dueño viene a buscar, y pedirlo en
 * dos requests serían dos fotos de momentos distintos.
 *
 * Tres bloques, en el orden en que se deciden las cosas:
 *  1. Cuánto hay de cada lado y cuánto está vencido (StatsRow).
 *  2. Antigüedad por lado — barras AGRUPADAS, no apiladas: la pregunta es
 *     comparar cobrar contra pagar en cada tramo, y apilarlas sumaría plata
 *     que entra con plata que sale, que no es una cantidad que exista.
 *  3. Proyección por vencimiento y los dos top 10, que es a quién llamar.
 *
 * La proyección NO es un pronóstico: reparte lo ya emitido según su fecha de
 * vencimiento. Se dice en el título de la card, no solo en el código.
 */

import * as React from "react"
import Link from "next/link"
import {
  Bar,
  BarChart,
  CartesianGrid,
  ComposedChart,
  Line,
  XAxis,
  YAxis,
} from "recharts"
import { AlertCircle, Wallet } from "lucide-react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { EmptyState } from "@/components/empty-state"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type OpenInvoicesSide,
  type OpenInvoicesSummaryResponse,
  type OpenInvoicesTopContact,
} from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"

/** Etiqueta de cada tramo de antigüedad, contado desde el VENCIMIENTO. */
const AGING_LABELS: Record<string, string> = {
  "0-30": "1 a 30 días",
  "31-60": "31 a 60 días",
  "61-90": "61 a 90 días",
  "90+": "Más de 90 días",
}

const sidesChartConfig = {
  receivable: { label: "Por cobrar", color: "var(--chart-1)" },
  payable: { label: "Por pagar", color: "var(--chart-3)" },
} satisfies ChartConfig

const projectionChartConfig = {
  inflow: { label: "Entra", color: "var(--chart-1)" },
  outflow: { label: "Sale", color: "var(--chart-3)" },
  net: { label: "Neto", color: "var(--brand)" },
} satisfies ChartConfig

export function OpenInvoicesDashboardTab() {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(() => ({ params: { view: "summary" } }), [])
  const { data, isLoading, error } = useReport<OpenInvoicesSummaryResponse>("open_invoices", opts)

  const money = React.useCallback((v: number) => formatMoney(v, bootstrap), [bootstrap])

  const agingData = React.useMemo(() => {
    if (!data) return []
    const bucketOf = (side: OpenInvoicesSide, key: string) =>
      side.aging.find((b) => b.bucket === key)?.amount ?? 0
    const rows = [
      {
        tramo: "Por vencer",
        receivable: data.receivable.notDue.amount,
        payable: data.payable.notDue.amount,
      },
    ]
    for (const key of Object.keys(AGING_LABELS)) {
      rows.push({
        tramo: AGING_LABELS[key],
        receivable: bucketOf(data.receivable, key),
        payable: bucketOf(data.payable, key),
      })
    }
    return rows
  }, [data])

  const projectionData = React.useMemo(() => {
    if (!data) return []
    const p = data.projection
    return [
      { label: "Vencido", inflow: p.overdue.inflow, outflow: p.overdue.outflow, net: p.overdue.net },
      ...p.weeks.map((w) => ({
        label: `Sem ${w.week}`,
        inflow: w.inflow,
        outflow: w.outflow,
        net: w.net,
      })),
      // Lo que vence después de la semana 8 entra al gráfico en vez de
      // desaparecer: sin esta barra, el total de la proyección no cierra con el
      // total abierto y nadie tendría cómo darse cuenta.
      { label: "Después", inflow: p.beyond.inflow, outflow: p.beyond.outflow, net: p.beyond.net },
    ]
  }, [data])

  if (error) {
    return (
      <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
        <AlertCircle className="mt-0.5 size-4 text-destructive" />
        <div>
          <p className="font-medium">No se pudo cargar el reporte</p>
          <p className="text-xs text-muted-foreground">{error.message}</p>
        </div>
      </div>
    )
  }

  if (isLoading || !data) {
    return (
      <div className="flex flex-col gap-6">
        <Skeleton className="h-[92px] w-full" />
        <Skeleton className="h-[300px] w-full" />
        <div className="grid gap-4 lg:grid-cols-2">
          <Skeleton className="h-[280px] w-full" />
          <Skeleton className="h-[280px] w-full" />
        </div>
      </div>
    )
  }

  const overdueTotal = data.receivable.overdue.amount + data.payable.overdue.amount
  const sinVencimiento =
    data.receivable.sinVencimiento.count + data.payable.sinVencimiento.count
  const isEmpty = data.totals.receivableCount === 0 && data.totals.payableCount === 0

  if (isEmpty) {
    return (
      <EmptyState
        icon={Wallet}
        title="Sin cuentas a crédito pendientes"
        description="Cuando se registre una venta o una compra a crédito sin saldar, el resumen aparece acá."
      />
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <StatsRow>
        <StatTile
          label="Por cobrar"
          value={money(data.totals.receivable)}
          tone="positive"
        />
        <StatTile
          label="Por pagar"
          value={money(data.totals.payable)}
          tone="negative"
        />
        <StatTile
          label="Neto"
          value={money(data.totals.net)}
          tone={data.totals.net >= 0 ? "positive" : "negative"}
          emphasis
        />
        <StatTile
          label="Vencido (las dos puntas)"
          value={money(overdueTotal)}
          tone={overdueTotal > 0 ? "negative" : "neutral"}
        />
      </StatsRow>

      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">
            Antigüedad de la deuda
          </CardTitle>
          <CardDescription className="text-xs">
            Días contados desde el VENCIMIENTO de cada comprobante, no desde su emisión: un
            plazo largo recién otorgado no es una deuda vieja.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <ChartContainer config={sidesChartConfig} className="h-[280px] w-full">
            <BarChart data={agingData} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
              <XAxis
                dataKey="tramo"
                tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                tick={{ fontSize: 10, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
                tickFormatter={compactNumber}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={
                  <ChartTooltipContent
                    formatter={(value, name) => (
                      <div className="flex w-full items-center justify-between gap-3">
                        <span className="text-muted-foreground">
                          {sidesChartConfig[name as keyof typeof sidesChartConfig]?.label ?? name}
                        </span>
                        <span className="font-medium tabular-nums">
                          {money(Number(value) || 0)}
                        </span>
                      </div>
                    )}
                  />
                }
              />
              <ChartLegend content={<ChartLegendContent />} />
              <Bar dataKey="receivable" fill="var(--color-receivable)" radius={[4, 4, 0, 0]} maxBarSize={38} />
              <Bar dataKey="payable" fill="var(--color-payable)" radius={[4, 4, 0, 0]} maxBarSize={38} />
            </BarChart>
          </ChartContainer>

          {sinVencimiento > 0 && (
            <p className="text-xs text-muted-foreground">
              {sinVencimiento === 1
                ? "1 comprobante no tiene vencimiento cargado"
                : `${sinVencimiento} comprobantes no tienen vencimiento cargado`}
              : su antigüedad se mide desde la fecha de emisión. Están sumados en los
              tramos de arriba, no aparte.
            </p>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">
            Qué vence en las próximas 8 semanas
          </CardTitle>
          <CardDescription className="text-xs">
            Reparte lo ya emitido según su fecha de vencimiento. No es un pronóstico: no
            proyecta ventas futuras ni estima si se va a cobrar. Al {data.today}.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ChartContainer config={projectionChartConfig} className="h-[300px] w-full">
            <ComposedChart data={projectionData} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
              <XAxis
                dataKey="label"
                tick={{ fontSize: 10, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                tick={{ fontSize: 10, fill: "var(--muted-foreground)" }}
                tickLine={false}
                axisLine={false}
                tickFormatter={compactNumber}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={
                  <ChartTooltipContent
                    labelFormatter={(label) => weekRangeLabel(String(label), data)}
                    formatter={(value, name) => (
                      <div className="flex w-full items-center justify-between gap-3">
                        <span className="text-muted-foreground">
                          {projectionChartConfig[name as keyof typeof projectionChartConfig]?.label ?? name}
                        </span>
                        <span className="font-medium tabular-nums">
                          {money(Number(value) || 0)}
                        </span>
                      </div>
                    )}
                  />
                }
              />
              <ChartLegend content={<ChartLegendContent />} />
              <Bar dataKey="inflow" fill="var(--color-inflow)" radius={[4, 4, 0, 0]} maxBarSize={24} />
              <Bar dataKey="outflow" fill="var(--color-outflow)" radius={[4, 4, 0, 0]} maxBarSize={24} />
              <Line type="monotone" dataKey="net" stroke="var(--color-net)" strokeWidth={2} dot={false} />
            </ComposedChart>
          </ChartContainer>
        </CardContent>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <TopContactsCard
          title="Quién más debe"
          description="Clientes con más saldo pendiente. El monto es lo que falta cobrar, no lo emitido."
          rows={data.receivable.top}
          money={money}
          emptyMessage="Sin ventas a crédito pendientes."
        />
        <TopContactsCard
          title="A quién más se le debe"
          description="Proveedores con más saldo pendiente de pago."
          rows={data.payable.top}
          money={money}
          emptyMessage="Sin compras a crédito pendientes."
        />
      </div>
    </div>
  )
}

/**
 * Top 10 de una punta. Lista compacta y no `<DataTable>`: son 10 filas fijas
 * sin búsqueda ni orden ni export — el listado completo, con todo eso, es la
 * pestaña de al lado (context/14 §3).
 */
function TopContactsCard({
  title,
  description,
  rows,
  money,
  emptyMessage,
}: {
  title: string
  description: string
  rows: OpenInvoicesTopContact[]
  money: (v: number) => string
  emptyMessage: string
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">{title}</CardTitle>
        <CardDescription className="text-xs">{description}</CardDescription>
      </CardHeader>
      <CardContent>
        {rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">{emptyMessage}</p>
        ) : (
          <ul className="flex flex-col">
            {rows.map((r) => (
              <li key={r.contactId}>
                <Link
                  href={`/contacts/${r.contactId}`}
                  className="flex items-center justify-between gap-3 rounded-md px-2 py-2 hover:bg-accent"
                >
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-medium">
                      {r.name || "(sin nombre)"}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {oldestLabel(r)}
                    </span>
                  </span>
                  <span className="shrink-0 text-sm font-semibold tabular-nums">
                    {money(r.open)}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

/** "3 comprobantes · el más viejo vence hace 45 días (001-001-0000012)". */
function oldestLabel(r: OpenInvoicesTopContact): string {
  const docs = r.count === 1 ? "1 comprobante" : `${r.count} comprobantes`
  if (!r.oldest) return docs
  const d = r.oldest.daysOverdue
  const estado =
    d > 0
      ? `vencido hace ${d} ${d === 1 ? "día" : "días"}`
      : d === 0
        ? "vence hoy"
        : `vence en ${-d} ${-d === 1 ? "día" : "días"}`
  const nro = r.oldest.invoiceNo ? ` (${r.oldest.invoiceNo})` : ""
  return `${docs} · el más viejo, ${estado}${nro}`
}

/** En el tooltip de una semana, el rango de fechas que cubre. */
function weekRangeLabel(label: string, data: OpenInvoicesSummaryResponse): string {
  const m = /^Sem (\d+)$/.exec(label)
  if (!m) return label
  const w = data.projection.weeks.find((x) => x.week === Number(m[1]))
  return w ? `${niceDay(w.from)} al ${niceDay(w.to)}` : label
}

function niceDay(iso: string): string {
  return /^\d{4}-\d{2}-\d{2}$/.test(iso) ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}` : iso
}

function compactNumber(v: number): string {
  if (Math.abs(v) >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (Math.abs(v) >= 1_000) return `${(v / 1_000).toFixed(0)}k`
  return String(Math.round(v))
}
