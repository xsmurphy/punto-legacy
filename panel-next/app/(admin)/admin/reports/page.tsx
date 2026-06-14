"use client"

import * as React from "react"
import { BarChart3, Construction } from "lucide-react"
import {
  PieChart,
  Pie,
  Cell,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from "recharts"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Badge } from "@/components/ui/badge"
import { useAdminCompanies, useAdminPlans, type AdminCompanyRow } from "@/hooks/use-admin"

const COLORS = ["#22c55e", "#f59e0b", "#ef4444", "#3b82f6", "#8b5cf6", "#ec4899"]

function groupBy<T>(arr: T[], fn: (item: T) => string): Record<string, T[]> {
  return arr.reduce<Record<string, T[]>>((acc, item) => {
    const key = fn(item)
    if (!acc[key]) acc[key] = []
    acc[key].push(item)
    return acc
  }, {})
}

function monthKey(dateStr: string | null): string {
  if (!dateStr) return "desconocido"
  const d = new Date(dateStr)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`
}

function computeReports(rows: AdminCompanyRow[], planCodeToName: Record<number, string>) {
  // Por plan
  const byPlan = groupBy(rows, (r) => {
    const code = r.plan ?? 0
    return planCodeToName[code] ?? `Plan ${code}`
  })
  const planDist = Object.entries(byPlan).map(([name, items]) => ({
    name,
    value: items.length,
  }))

  // Por país
  const byCountry = groupBy(rows, (r) => r.country || "Desconocido")
  const countryDist = Object.entries(byCountry)
    .map(([name, items]) => ({ name, value: items.length }))
    .sort((a, b) => b.value - a.value)
    .slice(0, 10)

  // Empresas nuevas por mes (últimos 12 meses)
  const now = new Date()
  const months: string[] = []
  for (let i = 11; i >= 0; i--) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
    months.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`)
  }
  const byMonth = groupBy(rows, (r) => monthKey(r.createdAt))
  const monthDist = months.map((m) => ({
    mes: m.slice(5), // "MM" para display
    nuevas: byMonth[m]?.length ?? 0,
  }))

  return { planDist, countryDist, monthDist }
}

export default function AdminReportsPage() {
  const { data: companiesData, isLoading } = useAdminCompanies({ limit: 500 })
  const { data: plansData } = useAdminPlans()

  const rows = companiesData?.rows ?? []
  const planCodeToName = React.useMemo(() => {
    const map: Record<number, string> = {}
    for (const p of plansData?.rows ?? []) {
      map[p.code] = p.name
    }
    return map
  }, [plansData])

  const { planDist, countryDist, monthDist } = React.useMemo(
    () => computeReports(rows, planCodeToName),
    [rows, planCodeToName],
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <BarChart3 className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Reportes</h1>
        <Badge variant="outline" className="text-xs">V1 — datos client-side</Badge>
      </div>

      {/* KPI MRR/ARR — placeholder */}
      <div className="grid gap-4 sm:grid-cols-2">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">MRR (estimado)</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-3xl font-bold text-muted-foreground">—</p>
            <p className="text-xs text-muted-foreground mt-1">
              Disponible próximamente — requiere endpoint /admin/dashboard
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">ARR (estimado)</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-3xl font-bold text-muted-foreground">—</p>
            <p className="text-xs text-muted-foreground mt-1">
              Disponible próximamente
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Charts */}
      <div className="grid gap-6 lg:grid-cols-2">
        {/* Por plan */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Distribución por plan</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-48 w-full" />
            ) : (
              <ResponsiveContainer width="100%" height={220}>
                <PieChart>
                  <Pie
                    data={planDist}
                    dataKey="value"
                    nameKey="name"
                    cx="50%"
                    cy="50%"
                    outerRadius={80}
                    label={({ name, value }) => `${name}: ${value}`}
                    labelLine={false}
                  >
                    {planDist.map((_, i) => (
                      <Cell key={i} fill={COLORS[i % COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>

        {/* Por país */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Distribución por país</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-48 w-full" />
            ) : (
              <ResponsiveContainer width="100%" height={220}>
                <PieChart>
                  <Pie
                    data={countryDist}
                    dataKey="value"
                    nameKey="name"
                    cx="50%"
                    cy="50%"
                    outerRadius={80}
                    label={({ name, value }) => `${name}: ${value}`}
                    labelLine={false}
                  >
                    {countryDist.map((_, i) => (
                      <Cell key={i} fill={COLORS[i % COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Empresas nuevas por mes */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Empresas nuevas — últimos 12 meses</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-48 w-full" />
          ) : (
            <ResponsiveContainer width="100%" height={220}>
              <BarChart data={monthDist} margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis dataKey="mes" tick={{ fontSize: 12 }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                <Tooltip />
                <Bar dataKey="nuevas" name="Nuevas" fill="#3b82f6" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </CardContent>
      </Card>

      {/* Placeholders */}
      <div className="grid gap-4 sm:grid-cols-2">
        <Card className="border-dashed">
          <CardHeader className="pb-2">
            <div className="flex items-center gap-2">
              <Construction className="size-4 text-muted-foreground" />
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Top consumidores IA
              </CardTitle>
            </div>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-muted-foreground">Disponible próximamente</p>
          </CardContent>
        </Card>
        <Card className="border-dashed">
          <CardHeader className="pb-2">
            <div className="flex items-center gap-2">
              <Construction className="size-4 text-muted-foreground" />
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Pagos del período
              </CardTitle>
            </div>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-muted-foreground">Disponible próximamente</p>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
