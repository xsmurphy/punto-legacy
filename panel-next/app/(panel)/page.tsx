"use client"

import * as React from "react"
import Link from "next/link"
import {
  ArrowDownRight,
  ArrowUpRight,
  ChevronRight,
  CreditCard,
  Wallet,
  Users as UsersIcon,
  TrendingUp,
  TrendingDown,
  PackageCheck,
  Layers,
  Smile,
  Meh,
  Frown,
  Receipt,
  ShoppingBag,
  Gift,
} from "lucide-react"

import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from "recharts"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Badge } from "@/components/ui/badge"
import { Progress } from "@/components/ui/progress"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useDashboardWidget,
  type CustomersWidget,
  type IncomeOutcomeStatsWidget,
  type InfoWidget,
  type OrdersWidget,
  type PaymentStatusWidget,
  type SatisfactionWidget,
  type TopItemRow,
  type TopTaxonomyRow,
} from "@/hooks/use-dashboard-widget"
import { formatInt, formatMoney } from "@/lib/format"
import { cn } from "@/lib/utils"

/**
 * Dashboard — espejo del panel legacy con widgets agrupados.
 * Cada sección consulta su propio widget. Se siguen las shapes de
 * api/lib/Reports/DashboardService.php.
 */
export default function DashboardPage() {
  const { data: bootstrap } = useBootstrap()
  const stats = useDashboardWidget<IncomeOutcomeStatsWidget>("incomeOutcomeStats")
  const info = useDashboardWidget<InfoWidget>("info")
  const paymentStatus = useDashboardWidget<PaymentStatusWidget>("paymentStatus")
  const customers = useDashboardWidget<CustomersWidget>("customers")
  const topItems = useDashboardWidget<TopItemRow[]>("topItems")
  const topCategories = useDashboardWidget<TopTaxonomyRow[]>("topCategories")
  const satisfaction = useDashboardWidget<SatisfactionWidget>("satisfaction")
  const orders = useDashboardWidget<OrdersWidget>("orders")

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Resumen general de su negocio</h1>
        <p className="text-sm text-muted-foreground">Últimos 7 días</p>
      </header>

      {/* ── HERO: Ingresos / Egresos / Ganancias / Margen+Tickets ── */}
      <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard
          label="Ingresos"
          value={fmtMoney(stats.data?.total, bootstrap, stats.isLoading)}
          icon="up"
          accent="primary"
        />
        <KpiCard
          label="Egresos"
          value={fmtMoney(stats.data?.expenses, bootstrap, stats.isLoading)}
          icon="down"
        />
        <KpiCard
          label="Ganancias"
          value={fmtMoney(stats.data?.revenue, bootstrap, stats.isLoading)}
        />
        <DualKpi
          label1="Margen"
          value1={stats.isLoading ? null : `${stats.data?.margin ?? 0}%`}
          label2="Tickets"
          value2={stats.isLoading ? null : formatInt(stats.data?.count, bootstrap)}
        />
      </section>

      {/* ── ÓRDENES / NPS Satisfacción (módulos opcionales — se ocultan si vacíos) ── */}
      <section className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <OrdersCard orders={orders.data} isLoading={orders.isLoading} bootstrap={bootstrap} />
        <SatisfactionCard data={satisfaction.data} isLoading={satisfaction.isLoading} />
      </section>

      {/* ── Tipos de ventas + Cuentas por cobrar ── */}
      <section className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <PaymentSplitCard
          title="Tipos de venta"
          data={paymentStatus.data}
          isLoading={paymentStatus.isLoading}
          bootstrap={bootstrap}
          mode="sale-type"
        />
        <PaymentSplitCard
          title="Cuentas por cobrar"
          data={paymentStatus.data}
          isLoading={paymentStatus.isLoading}
          bootstrap={bootstrap}
          mode="receivables"
        />
      </section>

      {/* ── Clientes ── */}
      <section className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <CustomersCard data={customers.data} isLoading={customers.isLoading} />
        <InfoGeneralCard
          stats={stats.data}
          info={info.data}
          loading={stats.isLoading || info.isLoading}
          bootstrap={bootstrap}
        />
      </section>

      {/* ── Top 5 Artículos + Top 10 Categorías ── */}
      <section className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <TopItemsCard data={topItems.data ?? []} isLoading={topItems.isLoading} bootstrap={bootstrap} />
        <TopCategoriesCard data={topCategories.data ?? []} isLoading={topCategories.isLoading} />
      </section>

      {/* ── Plan + Secondary KPIs ── */}
      <section>
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center justify-between text-sm font-medium">
              <span>Plan {info.data?.plan || ""}</span>
              <Link
                href="/settings"
                className="flex items-center gap-1 text-xs font-normal text-muted-foreground hover:text-foreground"
              >
                Cambiar plan <ChevronRight className="size-3.5" />
              </Link>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
              <PlanStat
                label="Productos"
                value={info.isLoading ? null : formatInt(info.data?.itemsCount, bootstrap)}
                max={info.data?.itemsMax || undefined}
                bootstrap={bootstrap}
              />
              <PlanStat
                label="Usuarios"
                value={info.isLoading ? null : formatInt(info.data?.usersCount, bootstrap)}
                max={info.data?.usersMax || undefined}
                bootstrap={bootstrap}
              />
              <PlanStat
                label="Sucursales"
                value={info.isLoading ? null : formatInt(info.data?.outletsCount, bootstrap)}
              />
              <PlanStat
                label="Transacciones (mes)"
                value={info.isLoading ? null : formatInt(info.data?.transactionsCount, bootstrap)}
              />
              <PlanStat
                label="Gift cards vigentes"
                value={info.isLoading ? null : formatInt(info.data?.giftCardsCount, bootstrap)}
              />
            </div>
          </CardContent>
        </Card>
      </section>
    </div>
  )
}

// ── KPI cards ──────────────────────────────────────────────────────────────

function KpiCard({
  label,
  value,
  icon,
  accent,
}: {
  label: string
  value: React.ReactNode
  icon?: "up" | "down"
  accent?: "primary"
}) {
  return (
    <Card className={cn(accent === "primary" && "ring-2 ring-primary/20")}>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-1.5 text-xs font-normal text-muted-foreground uppercase tracking-wide">
          {icon === "up" && <ArrowUpRight className="size-3.5 text-emerald-500" />}
          {icon === "down" && <ArrowDownRight className="size-3.5 text-destructive" />}
          {label}
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-semibold tracking-tight tabular-nums">{value}</div>
      </CardContent>
    </Card>
  )
}

function DualKpi({
  label1,
  value1,
  label2,
  value2,
}: {
  label1: string
  value1: string | null
  label2: string
  value2: string | null
}) {
  return (
    <Card>
      <CardContent className="grid grid-cols-2 gap-2 p-5">
        <div className="flex flex-col gap-1">
          <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
            {label1}
          </span>
          {value1 === null ? (
            <Skeleton className="h-7 w-16" />
          ) : (
            <span className="text-xl font-semibold tabular-nums">{value1}</span>
          )}
        </div>
        <div className="flex flex-col gap-1 border-l pl-3">
          <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
            {label2}
          </span>
          {value2 === null ? (
            <Skeleton className="h-7 w-16" />
          ) : (
            <span className="text-xl font-semibold tabular-nums">{value2}</span>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

// ── Órdenes + Satisfacción ─────────────────────────────────────────────────

function OrdersCard({
  orders,
  isLoading,
  bootstrap,
}: {
  orders: OrdersWidget | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  // Si el módulo orders está apagado, el endpoint devuelve [] (vacío).
  if (!isLoading && !orders) return <ModuleOffCard title="Órdenes" />
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <ShoppingBag className="size-4 text-muted-foreground" />
          Órdenes en curso
        </CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-2 gap-4">
        <Stat
          label="Total"
          value={isLoading ? null : formatInt(orders?.ordersCount, bootstrap)}
        />
        <Stat
          label="Online"
          value={isLoading ? null : formatInt(orders?.onlineCount, bootstrap)}
        />
      </CardContent>
    </Card>
  )
}

function SatisfactionCard({
  data,
  isLoading,
}: {
  data: SatisfactionWidget | undefined
  isLoading: boolean
}) {
  if (!isLoading && !data) return <ModuleOffCard title="Satisfacción (NPS)" />
  const det = data?.detractors.percent ?? 0
  const pas = data?.passives.percent ?? 0
  const pro = data?.promoters.percent ?? 0
  const totalResp = (data?.detractors.count ?? 0) + (data?.passives.count ?? 0) + (data?.promoters.count ?? 0)
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <Smile className="size-4 text-muted-foreground" />
          Satisfacción de clientes (NPS)
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {/* Barra apilada con los 3 segmentos (espejo del legacy) */}
        {isLoading ? (
          <Skeleton className="h-3 w-full rounded-full" />
        ) : (
          <div className="flex h-3 w-full overflow-hidden rounded-full bg-muted">
            {det > 0 && (
              <div
                className="bg-destructive transition-all"
                style={{ width: `${det}%` }}
                title={`Detractores · ${det}%`}
              />
            )}
            {pas > 0 && (
              <div
                className="bg-amber-500 transition-all"
                style={{ width: `${pas}%` }}
                title={`Pasivos · ${pas}%`}
              />
            )}
            {pro > 0 && (
              <div
                className="bg-emerald-500 transition-all"
                style={{ width: `${pro}%` }}
                title={`Promotores · ${pro}%`}
              />
            )}
          </div>
        )}

        <div className="grid grid-cols-3 gap-3">
          <NpsLegend
            icon={<Frown className="size-3.5 text-destructive" />}
            label="Detractores"
            percent={data?.detractors.percent ?? null}
            count={data?.detractors.count ?? null}
            isLoading={isLoading}
          />
          <NpsLegend
            icon={<Meh className="size-3.5 text-amber-500" />}
            label="Pasivos"
            percent={data?.passives.percent ?? null}
            count={data?.passives.count ?? null}
            isLoading={isLoading}
          />
          <NpsLegend
            icon={<Smile className="size-3.5 text-emerald-500" />}
            label="Promotores"
            percent={data?.promoters.percent ?? null}
            count={data?.promoters.count ?? null}
            isLoading={isLoading}
          />
        </div>

        {!isLoading && (
          <div className="text-center text-[10px] text-muted-foreground tabular-nums">
            {totalResp} respuestas en total
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function NpsLegend({
  icon,
  label,
  percent,
  count,
  isLoading,
}: {
  icon: React.ReactNode
  label: string
  percent: number | null
  count: number | null
  isLoading: boolean
}) {
  return (
    <div className="flex flex-col gap-0.5 text-center">
      <div className="flex items-center justify-center gap-1 text-[10px] uppercase tracking-wide text-muted-foreground">
        {icon}
        {label}
      </div>
      {isLoading ? (
        <Skeleton className="mx-auto h-5 w-10" />
      ) : (
        <>
          <span className="text-base font-semibold tabular-nums">{percent ?? 0}%</span>
          <span className="text-[10px] text-muted-foreground tabular-nums">{count ?? 0} resp.</span>
        </>
      )}
    </div>
  )
}

// ── Tipos de ventas + Cuentas por cobrar ──────────────────────────────────

function PaymentSplitCard({
  title,
  data,
  isLoading,
  bootstrap,
  mode,
}: {
  title: string
  data: PaymentStatusWidget | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
  mode: "sale-type" | "receivables"
}) {
  const isSaleType = mode === "sale-type"
  const left = (isSaleType ? data?.contado : data?.cobrado) ?? 0
  const right = (isSaleType ? data?.credito : data?.porcobrar) ?? 0
  const leftCount = isSaleType ? data?.contadoCount : data?.cobradoCount
  const rightCount = isSaleType ? data?.creditoCount : data?.porcobrarCount
  const leftLabel = isSaleType ? "Al contado" : "Cobrado"
  const rightLabel = isSaleType ? "A crédito" : "Por cobrar"
  const total = left + right
  const totalCount = (leftCount ?? 0) + (rightCount ?? 0)

  // Donut data — recharts ignora segments con value=0. Para mostrar un donut
  // incluso cuando todo es 0 usamos un placeholder gris.
  const pieData =
    total > 0
      ? [
          { name: leftLabel, value: left, color: "var(--color-primary, hsl(var(--primary)))" },
          { name: rightLabel, value: right, color: "var(--color-muted-foreground, hsl(var(--muted-foreground)))" },
        ]
      : [{ name: "Sin datos", value: 1, color: "hsl(var(--muted))" }]

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          {isSaleType ? <Wallet className="size-4 text-muted-foreground" /> : <CreditCard className="size-4 text-muted-foreground" />}
          {title}
        </CardTitle>
      </CardHeader>
      <CardContent className="flex items-center gap-4">
        <div className="relative size-32 shrink-0">
          {isLoading ? (
            <Skeleton className="size-full rounded-full" />
          ) : (
            <>
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={pieData}
                    dataKey="value"
                    cx="50%"
                    cy="50%"
                    innerRadius="62%"
                    outerRadius="100%"
                    paddingAngle={total > 0 ? 2 : 0}
                    strokeWidth={0}
                  >
                    {pieData.map((entry, i) => (
                      <Cell key={i} fill={entry.color} />
                    ))}
                  </Pie>
                  {total > 0 && <Tooltip content={<DonutTooltip bootstrap={bootstrap} />} />}
                </PieChart>
              </ResponsiveContainer>
              <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-base font-bold tabular-nums">{totalCount}</span>
                <span className="text-[9px] uppercase tracking-wide text-muted-foreground">
                  ventas
                </span>
              </div>
            </>
          )}
        </div>
        <div className="flex flex-1 flex-col gap-2">
          <SplitRow
            dot="bg-primary"
            label={leftLabel}
            amount={isLoading ? null : formatMoney(left, bootstrap)}
            count={isLoading ? null : formatInt(leftCount, bootstrap)}
          />
          <SplitRow
            dot="bg-muted-foreground/40"
            label={rightLabel}
            amount={isLoading ? null : formatMoney(right, bootstrap)}
            count={isLoading ? null : formatInt(rightCount, bootstrap)}
          />
        </div>
      </CardContent>
    </Card>
  )
}

function SplitRow({
  dot,
  label,
  amount,
  count,
}: {
  dot: string
  label: string
  amount: string | null
  count: string | null
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <div className="flex items-center gap-2 text-[10px] uppercase tracking-wide text-muted-foreground">
        <span className={cn("size-2 rounded-full", dot)} />
        {label}
      </div>
      {amount === null ? (
        <Skeleton className="h-6 w-20" />
      ) : (
        <>
          <span className="text-base font-semibold tabular-nums">{amount}</span>
          <span className="text-[10px] text-muted-foreground tabular-nums">{count} ventas</span>
        </>
      )}
    </div>
  )
}

function DonutTooltip({
  active,
  payload,
  bootstrap,
}: {
  active?: boolean
  payload?: Array<{ name?: string; value?: number; payload?: { name?: string } }>
  bootstrap?: ReturnType<typeof useBootstrap>["data"]
}) {
  if (!active || !payload || payload.length === 0) return null
  const p = payload[0]
  return (
    <div className="rounded-md border bg-popover px-2 py-1 text-xs shadow">
      <div className="font-medium">{p.payload?.name ?? p.name}</div>
      <div className="tabular-nums text-muted-foreground">
        {formatMoney(p.value ?? 0, bootstrap)}
      </div>
    </div>
  )
}

// ── Clientes ──────────────────────────────────────────────────────────────

function CustomersCard({
  data,
  isLoading,
}: {
  data: CustomersWidget | undefined
  isLoading: boolean
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <UsersIcon className="size-4 text-muted-foreground" />
          Clientes
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid grid-cols-3 gap-3">
          <Stat
            label="Total"
            value={isLoading ? null : formatInt(data?.total, undefined)}
          />
          <Stat
            label="Nuevos"
            value={isLoading ? null : formatInt(data?.new, undefined)}
            icon={<TrendingUp className="size-3.5 text-emerald-500" />}
          />
          <Stat
            label="Recurrentes"
            value={isLoading ? null : formatInt(data?.old, undefined)}
            icon={<TrendingDown className="size-3.5 text-amber-500" />}
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <div className="flex items-center justify-between text-xs">
            <span className="text-muted-foreground">Tasa de retorno</span>
            <span className="font-medium tabular-nums">
              {isLoading ? "…" : `${data?.returnRate ?? 0}%`}
            </span>
          </div>
          <Progress value={Math.min(100, Math.max(0, data?.returnRate ?? 0))} className="h-1.5" />
        </div>
      </CardContent>
    </Card>
  )
}

// ── Info general (Ticket promedio + cajas) ────────────────────────────────

function InfoGeneralCard({
  stats,
  info,
  loading,
  bootstrap,
}: {
  stats: IncomeOutcomeStatsWidget | undefined
  info: InfoWidget | undefined
  loading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <Receipt className="size-4 text-muted-foreground" />
          Información general
        </CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-2 gap-3">
        <Stat
          label="Ticket promedio"
          value={loading ? null : formatMoney(stats?.customerAverage, bootstrap)}
        />
        <Stat
          label="Cajas abiertas"
          value={loading ? null : formatInt(info?.openDrawersCount, bootstrap)}
        />
      </CardContent>
    </Card>
  )
}

// ── Top 5 artículos ───────────────────────────────────────────────────────

function TopItemsCard({
  data,
  isLoading,
  bootstrap,
}: {
  data: TopItemRow[]
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <PackageCheck className="size-4 text-muted-foreground" />
          Top 5 Artículos
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">
            {[1, 2, 3, 4, 5].map((i) => (
              <Skeleton key={i} className="h-8 w-full" />
            ))}
          </div>
        ) : data.length === 0 ? (
          <p className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">
            Sin ventas en este período.
          </p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="text-xs">Artículo</TableHead>
                <TableHead className="w-20 text-right text-xs">Cantidad</TableHead>
                <TableHead className="w-28 text-right text-xs">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((row, i) => (
                <TableRow key={`${row.name}-${i}`}>
                  <TableCell className="font-medium">{row.name || "(sin nombre)"}</TableCell>
                  <TableCell className="text-right tabular-nums">{row.count}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatMoney(row.total, bootstrap)}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}

// ── Top 10 Categorías ────────────────────────────────────────────────────

function TopCategoriesCard({
  data,
  isLoading,
}: {
  data: TopTaxonomyRow[]
  isLoading: boolean
}) {
  const max = data.reduce((m, r) => Math.max(m, r.total), 0)
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium">
          <Layers className="size-4 text-muted-foreground" />
          Top 10 Categorías
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">
            {[1, 2, 3, 4, 5].map((i) => (
              <Skeleton key={i} className="h-5 w-full" />
            ))}
          </div>
        ) : data.length === 0 ? (
          <p className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">
            Sin ventas en este período.
          </p>
        ) : (
          <div className="flex flex-col gap-2">
            {data.map((row, i) => (
              <div key={`${row.title}-${i}`} className="flex flex-col gap-1">
                <div className="flex items-center justify-between text-xs">
                  <span className="font-medium">{row.title}</span>
                  <span className="tabular-nums text-muted-foreground">
                    {row.total.toFixed(0)}
                  </span>
                </div>
                <Progress
                  value={max > 0 ? (row.total / max) * 100 : 0}
                  className="h-1"
                />
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

// ── Pequeños helpers ─────────────────────────────────────────────────────

function Stat({
  label,
  value,
  icon,
}: {
  label: string
  value: string | null
  icon?: React.ReactNode
}) {
  return (
    <div className="flex flex-col gap-1">
      <span className="flex items-center gap-1.5 text-[10px] uppercase tracking-wide text-muted-foreground">
        {icon}
        {label}
      </span>
      {value === null ? (
        <Skeleton className="h-6 w-16" />
      ) : (
        <span className="text-lg font-semibold tabular-nums">{value}</span>
      )}
    </div>
  )
}

function PlanStat({
  label,
  value,
  max,
  bootstrap,
}: {
  label: string
  value: string | null
  max?: number
  bootstrap?: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <div className="flex flex-col gap-1 rounded-md border bg-muted/30 p-3">
      <span className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</span>
      {value === null ? (
        <Skeleton className="h-6 w-16" />
      ) : (
        <>
          <span className="text-base font-semibold tabular-nums">{value}</span>
          {max ? (
            <span className="text-[10px] text-muted-foreground tabular-nums">
              / {formatInt(max, bootstrap)} permitidos
            </span>
          ) : null}
        </>
      )}
    </div>
  )
}

function ModuleOffCard({ title }: { title: string }) {
  return (
    <Card className="opacity-60">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
          <Gift className="size-4" />
          {title}
        </CardTitle>
      </CardHeader>
      <CardContent>
        <p className="text-xs text-muted-foreground">
          Módulo no activado para tu plan.
        </p>
      </CardContent>
    </Card>
  )
}

function fmtMoney(
  v: number | undefined,
  bootstrap: ReturnType<typeof useBootstrap>["data"],
  loading: boolean,
): React.ReactNode {
  if (loading) return <Skeleton className="h-8 w-24 inline-block align-middle" />
  return formatMoney(v ?? 0, bootstrap)
}
