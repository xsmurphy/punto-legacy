import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { ArrowDownRight, ArrowUpRight, ChevronRight } from "lucide-react"

// Espejo del dashboard legacy (panel/reports/dashboard.html + panel/scripts/a_report_dashboard.js).
// Widgets reales en orden de aparición; los valores son placeholders (los pega
// el cliente cuando el slice 8 conecte a /bff/reports/dashboard.php).
//
// Title del legacy: "Resumen general de su negocio".
// Date range picker (legacy: customDateR, 7d default) se agrega en slice 8.

const kpis = [
  {
    label: "Ingresos",
    value: "₲ 0",
    delta: "—",
    icon: ArrowUpRight,
    tone: "income" as const,
    href: "/reports/summary",
  },
  {
    label: "Egresos",
    value: "₲ 0",
    delta: "—",
    icon: ArrowDownRight,
    tone: "expense" as const,
    href: "/reports/purchases",
  },
  {
    label: "Ticket promedio",
    value: "₲ 0",
    delta: "—",
    href: "/reports/summary",
  },
  {
    label: "Clientes",
    value: "0",
    delta: "0 nuevos · 0 recurrentes",
    href: "/contacts",
  },
]

const secondaryKpis = [
  { label: "Pedidos", value: "0", subtitle: "0 online" },
  { label: "Mesas", value: "0%", subtitle: "ocupación" },
  { label: "Agendados", value: "0%", subtitle: "ocupación" },
  { label: "Cajas abiertas", value: "0", subtitle: "operando ahora" },
  { label: "Gift cards", value: "0", subtitle: "activas" },
]

export default function DashboardPage() {
  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <div className="flex items-center gap-2">
          <h1 className="text-2xl font-semibold">Resumen general de su negocio</h1>
          <Badge variant="outline" className="text-[10px]">SPRINT 0</Badge>
        </div>
        <p className="text-sm text-muted-foreground">
          Placeholder espejado del dashboard legacy. Conexión real a{" "}
          <code className="rounded bg-muted px-1 py-0.5 text-xs">/bff/reports/dashboard.php</code>{" "}
          llega en el slice 8.
        </p>
      </header>

      {/* Top KPIs: Ingresos / Egresos / Ticket promedio / Clientes.
          Espejo de incomeOutcomeStatsWidget + customer KPIs del legacy. */}
      <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {kpis.map((kpi) => {
          const Icon = kpi.icon
          return (
            <Card key={kpi.label} className="relative">
              <CardHeader className="pb-2">
                <CardTitle className="flex items-center justify-between text-xs font-normal text-muted-foreground">
                  <span className="flex items-center gap-1.5">
                    {Icon && (
                      <Icon
                        className={
                          kpi.tone === "income"
                            ? "size-3.5 text-emerald-500"
                            : kpi.tone === "expense"
                              ? "size-3.5 text-destructive"
                              : "size-3.5"
                        }
                      />
                    )}
                    {kpi.label}
                  </span>
                  <ChevronRight className="size-3.5 opacity-40" />
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="flex items-baseline justify-between">
                  <span className="text-2xl font-semibold tracking-tight tabular-nums">
                    {kpi.value}
                  </span>
                  <span className="text-xs text-muted-foreground">{kpi.delta}</span>
                </div>
              </CardContent>
            </Card>
          )
        })}
      </section>

      {/* Chart de ingresos por día — placeholder del summaryChart (Chart.js
          en legacy → recharts cuando aterrice el slice). */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm font-medium text-muted-foreground">
            Ingresos · últimos 7 días
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex h-[200px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
            summaryChart (recharts en slice 8)
          </div>
        </CardContent>
      </Card>

      {/* Secondary KPIs — gateados por módulo en el legacy (tables/schedule/orders
          aparecen sólo si el módulo está activo). Se renderizan condicionales
          cuando el bootstrap traiga los flags. */}
      <section className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {secondaryKpis.map((kpi) => (
          <Card key={kpi.label}>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-normal text-muted-foreground">
                {kpi.label}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex flex-col">
                <span className="text-xl font-semibold tracking-tight tabular-nums">
                  {kpi.value}
                </span>
                <span className="text-xs text-muted-foreground">{kpi.subtitle}</span>
              </div>
            </CardContent>
          </Card>
        ))}
      </section>

      {/* Charts secundarios — topHoursChart + topCategoriesChart del legacy. */}
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">
              Horas pico
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex h-[180px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
              topHoursChart
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">
              Top categorías
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex h-[180px] items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
              topCategoriesChart
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
