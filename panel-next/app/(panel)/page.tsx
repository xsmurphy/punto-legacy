"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { ArrowDownRight, ArrowUpRight, ChevronRight } from "lucide-react"

import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useDashboardWidget,
  type InfoWidget,
  type IncomeOutcomeStatsWidget,
} from "@/hooks/use-dashboard-widget"
import { formatInt, formatMoney } from "@/lib/format"

// Espejo del dashboard legacy (panel/reports/dashboard.html + panel/scripts/a_report_dashboard.js).
// Conectado a `/v1/reports/dashboard?widget=*` (real).
// Date range picker (legacy: customDateR, 7d default) se agrega en un slice futuro.

export default function DashboardPage() {
  const { data: bootstrap } = useBootstrap()
  const stats = useDashboardWidget<IncomeOutcomeStatsWidget>("incomeOutcomeStats")
  const info = useDashboardWidget<InfoWidget>("info")

  const loading = stats.isLoading || info.isLoading

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Resumen general de su negocio</h1>
        <p className="text-sm text-muted-foreground">Últimos 7 días</p>
      </header>

      {/* Top KPIs: Ingresos / Egresos / Ticket promedio / Clientes(tickets).
          Wireados a incomeOutcomeStats. */}
      <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard
          label="Ingresos"
          value={loading ? null : formatMoney(stats.data?.total, bootstrap)}
          icon="up"
          href="/reports/summary"
        />
        <KpiCard
          label="Egresos"
          value={loading ? null : formatMoney(stats.data?.expenses, bootstrap)}
          icon="down"
          href="/reports/purchases"
        />
        <KpiCard
          label="Ticket promedio"
          value={loading ? null : formatMoney(stats.data?.customerAverage, bootstrap)}
          href="/reports/summary"
        />
        <KpiCard
          label="Tickets"
          value={loading ? null : formatInt(stats.data?.count, bootstrap)}
          href="/reports/transactions"
        />
      </section>

      {/* Chart de ingresos por día — placeholder hasta que el slice de charts
          arme el wrapper de recharts contra `/v1/reports/dashboard?widget=incomeOutcomeStats&type=chart`. */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm font-medium text-muted-foreground">
            Ingresos · últimos 7 días
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex h-[200px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
            recharts (próximo slice)
          </div>
        </CardContent>
      </Card>

      {/* Secondary KPIs — del widget `info` (cajas, gift cards, users, items, plan). */}
      <section className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <SecondaryKpi
          label="Cajas abiertas"
          value={info.isLoading ? null : formatInt(info.data?.openDrawersCount, bootstrap)}
          subtitle="operando ahora"
        />
        <SecondaryKpi
          label="Sucursales"
          value={info.isLoading ? null : formatInt(info.data?.outletsCount, bootstrap)}
          subtitle="activas"
        />
        <SecondaryKpi
          label="Usuarios"
          value={info.isLoading ? null : formatInt(info.data?.usersCount, bootstrap)}
          subtitle={
            info.data?.usersMax
              ? `de ${formatInt(info.data.usersMax, bootstrap)} permitidos`
              : "registrados"
          }
        />
        <SecondaryKpi
          label="Productos"
          value={info.isLoading ? null : formatInt(info.data?.itemsCount, bootstrap)}
          subtitle={
            info.data?.itemsMax
              ? `de ${formatInt(info.data.itemsMax, bootstrap)} permitidos`
              : "en catálogo"
          }
        />
        <SecondaryKpi
          label="Gift cards"
          value={info.isLoading ? null : formatInt(info.data?.giftCardsCount, bootstrap)}
          subtitle="vendidas"
        />
      </section>
    </div>
  )
}

// ── helpers de render ────────────────────────────────────────────────────

function KpiCard({
  label,
  value,
  icon,
  href: _href,
}: {
  label: string
  value: string | null
  icon?: "up" | "down"
  href?: string
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center justify-between text-xs font-normal text-muted-foreground">
          <span className="flex items-center gap-1.5">
            {icon === "up" && <ArrowUpRight className="size-3.5 text-emerald-500" />}
            {icon === "down" && <ArrowDownRight className="size-3.5 text-destructive" />}
            {label}
          </span>
          <ChevronRight className="size-3.5 opacity-40" />
        </CardTitle>
      </CardHeader>
      <CardContent>
        {value === null ? (
          <Skeleton className="h-8 w-24" />
        ) : (
          <span className="text-2xl font-semibold tracking-tight tabular-nums">{value}</span>
        )}
      </CardContent>
    </Card>
  )
}

function SecondaryKpi({
  label,
  value,
  subtitle,
}: {
  label: string
  value: string | null
  subtitle?: string
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-xs font-normal text-muted-foreground">{label}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="flex flex-col">
          {value === null ? (
            <Skeleton className="h-6 w-16" />
          ) : (
            <span className="text-xl font-semibold tracking-tight tabular-nums">{value}</span>
          )}
          {subtitle && (
            <span className="text-xs text-muted-foreground">{subtitle}</span>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
