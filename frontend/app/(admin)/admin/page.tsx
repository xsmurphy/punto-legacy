"use client"

import * as React from "react"
import Link from "next/link"
import {
  Building2,
  TrendingUp,
  Users,
  AlertCircle,
  PlusCircle,
  Clock,
  DollarSign,
  Zap,
  HeartPulse,
  UserMinus,
} from "lucide-react"
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  XAxis,
  YAxis,
} from "recharts"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { useAdminOverview, useAdminRequests, useAdminHealthList } from "@/hooks/use-admin"
import {
  formatPuntoSaasDate,
  formatPuntoSaasMoney,
  formatPuntoSaasNumber,
} from "@/lib/punto-saas-locale"

function healthScoreBadge(level: string, score: number) {
  const cls =
    level === "green"
      ? "bg-emerald-600 text-white border-0"
      : level === "yellow"
        ? "bg-amber-500 text-white border-0"
        : "bg-destructive text-destructive-foreground border-0"
  return <Badge className={`${cls} text-xs tabular-nums`}>{score}</Badge>
}

/** 'YYYY-MM' → "ene", "feb", ... (mes corto, locale de Punto S.A.). */
function monthLabel(m: string): string {
  if (!/^\d{4}-\d{2}$/.test(m)) return m
  const d = new Date(`${m}-02T00:00:00`)
  return formatPuntoSaasDate(d, { month: "short" }).replace(".", "")
}

export default function AdminDashboardPage() {
  const { data: overview, isLoading: loadingOverview } = useAdminOverview()
  const { data: pendingRequests, isLoading: loadingRequests } = useAdminRequests("pending")
  const { data: healthList, isLoading: loadingHealth } = useAdminHealthList()

  const pendingCount = Array.isArray(pendingRequests) ? pendingRequests.length : 0
  const topRequests = Array.isArray(pendingRequests) ? pendingRequests.slice(0, 5) : []

  const atRiskTenants = (healthList ?? [])
    .filter((h) => h.level !== "green")
    .sort((a, b) => a.score - b.score)
    .slice(0, 6)
  const redCount = (healthList ?? []).filter((h) => h.level === "red").length

  const companies = overview?.companies
  const saas = overview?.saas
  const mrr = overview?.mrr ?? 0
  const newThisMonth = overview?.newThisMonth ?? 0

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Dashboard</h1>
        <p className="text-muted-foreground text-sm mt-0.5">Vista general del sistema</p>
      </div>

      {/* KPI row 1 — tenants */}
      <StatsRow className="flex-wrap">
        <StatTile
          icon={<Building2 className="size-3.5" />}
          label="Total empresas"
          value={formatPuntoSaasNumber(companies?.total)}
          isLoading={loadingOverview}
        />
        <StatTile
          icon={<TrendingUp className="size-3.5" />}
          label="Activos"
          value={formatPuntoSaasNumber(saas?.tenantsGoodStanding)}
          tone="positive"
          isLoading={loadingOverview}
        />
        <StatTile
          icon={<Clock className="size-3.5" />}
          label="En trial"
          value={formatPuntoSaasNumber(saas?.tenantsTrial)}
          isLoading={loadingOverview}
        />
        <StatTile
          icon={<AlertCircle className="size-3.5" />}
          label="Morosos / vencidos"
          value={formatPuntoSaasNumber(saas?.tenantsDelinquent)}
          tone="negative"
          isLoading={loadingOverview}
        />
        <StatTile
          icon={<PlusCircle className="size-3.5" />}
          label="Altas del mes"
          value={formatPuntoSaasNumber(newThisMonth)}
          isLoading={loadingOverview}
        />
        <StatTile
          icon={<UserMinus className="size-3.5" />}
          label="Bajas del mes"
          value={formatPuntoSaasNumber(saas?.churnedThisMonth)}
          tone={((saas?.churnedThisMonth ?? 0) > 0) ? "negative" : "neutral"}
          isLoading={loadingOverview}
        />
      </StatsRow>

      {/* KPI row 2 — financiero / IA / riesgo */}
      <StatsRow className="flex-wrap">
        <StatTile
          icon={<DollarSign className="size-3.5" />}
          label="MRR"
          value={formatPuntoSaasMoney(mrr)}
          tone="positive"
          emphasis
          isLoading={loadingOverview}
        />
        <StatTile
          icon={<Zap className="size-3.5" />}
          label="Créditos IA (mes)"
          value={formatPuntoSaasNumber(saas?.aiCreditsConsumedThisMonth)}
          isLoading={loadingOverview}
        />
        <StatTile
          icon={<HeartPulse className="size-3.5" />}
          label="Tenants en rojo"
          value={formatPuntoSaasNumber(redCount)}
          tone={redCount > 0 ? "negative" : "neutral"}
          isLoading={loadingHealth}
        />
        <StatTile
          icon={<Users className="size-3.5" />}
          label="Solicitudes pendientes"
          value={formatPuntoSaasNumber(pendingCount)}
          isLoading={loadingRequests}
        />
      </StatsRow>

      {/* Charts SaaS — F1 */}
      <div className="grid gap-4 lg:grid-cols-2">
        <MrrChart data={overview?.series.mrrByMonth} isLoading={loadingOverview} />
        <TenantsFlowChart data={overview?.series.tenantsByMonth} isLoading={loadingOverview} />
        <AiCreditsChart
          data={overview?.series.aiCreditsByMonth}
          capabilities={overview?.aiCapabilities ?? []}
          isLoading={loadingOverview}
        />
        <GmvChart data={overview?.series.gmvByMonth} isLoading={loadingOverview} />
      </div>

      {/* Grid de widgets */}
      <div className="grid gap-6 lg:grid-cols-2">
        {/* Solicitudes pendientes */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="text-base">Solicitudes pendientes</CardTitle>
            {pendingCount > 0 && (
              <Badge variant="destructive" className="text-xs">{pendingCount}</Badge>
            )}
          </CardHeader>
          <CardContent>
            {loadingRequests ? (
              <div className="space-y-2">
                {[...Array(3)].map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
              </div>
            ) : topRequests.length === 0 ? (
              <p className="text-sm text-muted-foreground py-4 text-center">
                Sin solicitudes pendientes
              </p>
            ) : (
              <div className="space-y-2">
                {topRequests.map((req) => (
                  <div
                    key={req.id}
                    className="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                  >
                    <div className="truncate">
                      <p className="font-medium truncate">{req.companyName}</p>
                      <p className="text-xs text-muted-foreground">Plan {req.requestedPlanCode}</p>
                    </div>
                    <Link href="/admin/requests">
                      <Button variant="outline" size="sm">Ver</Button>
                    </Link>
                  </div>
                ))}
                {pendingCount > 5 && (
                  <Link href="/admin/requests" className="block">
                    <Button variant="link" size="sm" className="w-full">
                      Ver todas ({pendingCount})
                    </Button>
                  </Link>
                )}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Top consumidores IA */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="text-base">Top consumidores IA</CardTitle>
            <Zap className="size-4 text-violet-500" />
          </CardHeader>
          <CardContent>
            {loadingOverview ? (
              <div className="space-y-2">
                {[...Array(5)].map((_, i) => <Skeleton key={i} className="h-8 w-full" />)}
              </div>
            ) : (overview?.topAiCredits ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground py-4 text-center">Sin datos</p>
            ) : (
              <div className="space-y-1.5">
                {(overview?.topAiCredits ?? []).map((c) => (
                  <Link
                    key={c.companyId}
                    href={`/admin/companies/${c.companyId}`}
                    className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-accent/50 transition-colors"
                  >
                    <span className="text-sm font-medium truncate">{c.name || "(sin nombre)"}</span>
                    <Badge variant="secondary" className="text-xs tabular-nums ml-2 shrink-0">
                      {formatPuntoSaasNumber(c.balance)} cr.
                    </Badge>
                  </Link>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Tenants en riesgo (F2 — salud del tenant) */}
        <Card className="lg:col-span-2">
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="text-base">Tenants en riesgo</CardTitle>
            <HeartPulse className="size-4 text-destructive" />
          </CardHeader>
          <CardContent>
            {loadingHealth ? (
              <div className="space-y-2">
                {[...Array(3)].map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
              </div>
            ) : atRiskTenants.length === 0 ? (
              <EmptyState
                icon={HeartPulse}
                title="Sin tenants en riesgo"
                description="Todos los tenants están en verde por ahora."
                ghost={false}
                className="py-4"
              />
            ) : (
              <div className="space-y-2">
                {atRiskTenants.map((h) => (
                  <Link
                    key={h.companyId}
                    href={`/admin/companies/${h.companyId}`}
                    className="flex items-center justify-between gap-3 rounded-md border px-3 py-2 hover:bg-accent/50 transition-colors"
                  >
                    <div className="min-w-0">
                      <p className="text-sm font-medium truncate">{h.name || "(sin nombre)"}</p>
                      {h.topIssue && (
                        <p className="text-xs text-muted-foreground truncate">{h.topIssue}</p>
                      )}
                    </div>
                    {healthScoreBadge(h.level, h.score)}
                  </Link>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

// ── Charts SaaS (F1) ─────────────────────────────────────────────────────────

const mrrChartConfig = {
  mrr: { label: "MRR", color: "var(--chart-1)" },
} satisfies ChartConfig

function MrrChart({
  data,
  isLoading,
}: {
  data: { month: string; mrr: number }[] | undefined
  isLoading: boolean
}) {
  const hasData = (data ?? []).some((p) => p.mrr > 0)
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">MRR — últimos 12 meses</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-[220px] w-full" />
        ) : !hasData ? (
          <EmptyChart label="Sin MRR en el período" />
        ) : (
          <ChartContainer config={mrrChartConfig} className="h-[220px] w-full">
            <LineChart data={data} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey="month"
                tickFormatter={monthLabel}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickFormatter={(v: number) => formatPuntoSaasMoney(v)}
                tickLine={false}
                axisLine={false}
                width={64}
              />
              <ChartTooltip
                cursor={{ stroke: "var(--border)" }}
                content={
                  <ChartTooltipContent
                    labelFormatter={(l) => monthLabel(String(l))}
                    formatter={(value) => (
                      <span className="font-medium tabular-nums">{formatPuntoSaasMoney(Number(value) || 0)}</span>
                    )}
                  />
                }
              />
              <Line
                type="monotone"
                dataKey="mrr"
                stroke="var(--color-mrr)"
                strokeWidth={2}
                dot={false}
              />
            </LineChart>
          </ChartContainer>
        )}
      </CardContent>
    </Card>
  )
}

const tenantsFlowChartConfig = {
  new: { label: "Altas", color: "var(--chart-1)" },
  churned: { label: "Bajas", color: "var(--destructive)" },
} satisfies ChartConfig

function TenantsFlowChart({
  data,
  isLoading,
}: {
  data: { month: string; new: number; churned: number }[] | undefined
  isLoading: boolean
}) {
  const hasData = (data ?? []).some((p) => p.new > 0 || p.churned > 0)
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Altas vs. bajas por mes</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-[220px] w-full" />
        ) : !hasData ? (
          <EmptyChart label="Sin movimientos de tenants en el período" />
        ) : (
          <ChartContainer config={tenantsFlowChartConfig} className="h-[220px] w-full">
            <BarChart data={data} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey="month"
                tickFormatter={monthLabel}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                allowDecimals={false}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
                width={28}
              />
              <ChartTooltip
                cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                content={<ChartTooltipContent labelFormatter={(l) => monthLabel(String(l))} />}
              />
              <ChartLegend content={<ChartLegendContent />} />
              <Bar dataKey="new" fill="var(--color-new)" radius={[4, 4, 0, 0]} maxBarSize={24} />
              <Bar dataKey="churned" fill="var(--color-churned)" radius={[4, 4, 0, 0]} maxBarSize={24} />
            </BarChart>
          </ChartContainer>
        )}
      </CardContent>
    </Card>
  )
}

function AiCreditsChart({
  data,
  capabilities,
  isLoading,
}: {
  data: (Record<string, number | string> & { month: string; total: number })[] | undefined
  capabilities: string[]
  isLoading: boolean
}) {
  const config = React.useMemo(() => {
    const c: ChartConfig = {}
    capabilities.forEach((cap, i) => {
      c[cap] = { label: cap, color: `var(--chart-${(i % 5) + 1})` }
    })
    return c
  }, [capabilities])

  const hasData = (data ?? []).some((p) => (p.total as number) > 0)

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Consumo de créditos IA por mes</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-[220px] w-full" />
        ) : !hasData || capabilities.length === 0 ? (
          <EmptyChart label="Sin consumo de IA en el período" />
        ) : (
          <ChartContainer config={config} className="h-[220px] w-full">
            <AreaChart data={data} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey="month"
                tickFormatter={monthLabel}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                allowDecimals={false}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
                width={36}
              />
              <ChartTooltip
                cursor={{ stroke: "var(--border)" }}
                content={<ChartTooltipContent labelFormatter={(l) => monthLabel(String(l))} />}
              />
              <ChartLegend content={<ChartLegendContent />} />
              {capabilities.map((cap) => (
                <Area
                  key={cap}
                  type="monotone"
                  dataKey={cap}
                  stackId="ai"
                  stroke={`var(--color-${cap})`}
                  fill={`var(--color-${cap})`}
                  fillOpacity={0.5}
                />
              ))}
            </AreaChart>
          </ChartContainer>
        )}
      </CardContent>
    </Card>
  )
}

const gmvChartConfig = {
  gmv: { label: "GMV", color: "var(--chart-2)" },
} satisfies ChartConfig

function GmvChart({
  data,
  isLoading,
}: {
  data: { month: string; gmv: number }[] | undefined
  isLoading: boolean
}) {
  const hasData = (data ?? []).some((p) => p.gmv > 0)
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">GMV agregado por mes</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-[220px] w-full" />
        ) : !hasData ? (
          <EmptyChart label="Sin ventas registradas en el período" />
        ) : (
          <ChartContainer config={gmvChartConfig} className="h-[220px] w-full">
            <LineChart data={data} margin={{ top: 8, right: 12, left: -10, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey="month"
                tickFormatter={monthLabel}
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                fontSize={10}
                stroke="var(--muted-foreground)"
                tickFormatter={(v: number) => formatPuntoSaasMoney(v)}
                tickLine={false}
                axisLine={false}
                width={64}
              />
              <ChartTooltip
                cursor={{ stroke: "var(--border)" }}
                content={
                  <ChartTooltipContent
                    labelFormatter={(l) => monthLabel(String(l))}
                    formatter={(value) => (
                      <span className="font-medium tabular-nums">{formatPuntoSaasMoney(Number(value) || 0)}</span>
                    )}
                  />
                }
              />
              <Line
                type="monotone"
                dataKey="gmv"
                stroke="var(--color-gmv)"
                strokeWidth={2}
                dot={false}
              />
            </LineChart>
          </ChartContainer>
        )}
      </CardContent>
    </Card>
  )
}

function EmptyChart({ label }: { label: string }) {
  return (
    <div className="flex h-[220px] items-center justify-center">
      <EmptyState icon={TrendingUp} title={label} showMarquee={false} className="border-0 p-0" />
    </div>
  )
}
