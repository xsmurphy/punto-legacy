"use client"

import * as React from "react"
import { BarChart3, DollarSign, Zap } from "lucide-react"
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
import { Input } from "@/components/ui/input"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Badge } from "@/components/ui/badge"
import { useAdminOverview, useAdminPayments } from "@/hooks/use-admin"

const COLORS = ["#22c55e", "#f59e0b", "#ef4444", "#3b82f6", "#8b5cf6", "#ec4899"]

function todayStr() {
  return new Date().toISOString().slice(0, 10)
}

function thirtyDaysAgoStr() {
  const d = new Date()
  d.setDate(d.getDate() - 30)
  return d.toISOString().slice(0, 10)
}

function paymentStatusLabel(status: number): string {
  if (status === 1) return "Aprobado"
  if (status === 0) return "Pendiente"
  return String(status)
}

export default function AdminReportsPage() {
  const { data: overview, isLoading: loadingOverview } = useAdminOverview()

  const [from, setFrom] = React.useState(thirtyDaysAgoStr)
  const [to, setTo] = React.useState(todayStr)

  const { data: paymentsData, isLoading: loadingPayments } = useAdminPayments(from, to)

  const mrr = overview?.mrr ?? 0
  const arr = overview?.arr ?? 0

  const planDist = React.useMemo(
    () =>
      (overview?.byPlan ?? []).map((p) => ({
        name: p.planName || `Plan ${p.planCode}`,
        value: p.count,
      })),
    [overview],
  )

  const countryDist = React.useMemo(
    () =>
      (overview?.byCountry ?? []).slice(0, 10).map((c) => ({
        name: c.country,
        value: c.count,
      })),
    [overview],
  )

  const monthDist = React.useMemo(
    () =>
      (overview?.newPerMonth ?? []).map((m) => ({
        mes: m.month.slice(5),
        nuevas: m.count,
      })),
    [overview],
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <BarChart3 className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Reportes</h1>
      </div>

      {/* KPI MRR/ARR */}
      <div className="grid gap-4 sm:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">MRR</CardTitle>
            <DollarSign className="size-4 text-emerald-600" />
          </CardHeader>
          <CardContent>
            {loadingOverview ? (
              <Skeleton className="h-8 w-24" />
            ) : (
              <p className="text-3xl font-bold text-emerald-600">
                {mrr.toLocaleString("es-PY", { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
              </p>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">ARR</CardTitle>
            <DollarSign className="size-4 text-emerald-700" />
          </CardHeader>
          <CardContent>
            {loadingOverview ? (
              <Skeleton className="h-8 w-24" />
            ) : (
              <p className="text-3xl font-bold text-emerald-700">
                {arr.toLocaleString("es-PY", { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
              </p>
            )}
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
            {loadingOverview ? (
              <Skeleton className="h-48 w-full" />
            ) : planDist.length === 0 ? (
              <p className="text-sm text-muted-foreground py-12 text-center">Sin datos</p>
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
            {loadingOverview ? (
              <Skeleton className="h-48 w-full" />
            ) : countryDist.length === 0 ? (
              <p className="text-sm text-muted-foreground py-12 text-center">Sin datos</p>
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
          {loadingOverview ? (
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

      {/* Top consumidores IA */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle className="text-base">Top consumidores IA</CardTitle>
          <Zap className="size-4 text-violet-500" />
        </CardHeader>
        <CardContent>
          {loadingOverview ? (
            <Skeleton className="h-32 w-full" />
          ) : (overview?.topAiCredits ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground py-4 text-center">Sin datos</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>#</TableHead>
                  <TableHead>Empresa</TableHead>
                  <TableHead className="text-right">Créditos IA</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(overview?.topAiCredits ?? []).map((c, i) => (
                  <TableRow key={c.companyId}>
                    <TableCell className="text-muted-foreground text-sm">{i + 1}</TableCell>
                    <TableCell className="font-medium">{c.name || "(sin nombre)"}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {c.balance.toLocaleString("es-PY")}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Pagos del período */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Pagos del período</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Date range inputs */}
          <div className="flex flex-wrap gap-3 items-center">
            <div className="flex items-center gap-2">
              <span className="text-sm text-muted-foreground">Desde</span>
              <Input
                type="date"
                value={from}
                onChange={(e) => setFrom(e.target.value)}
                className="w-40"
              />
            </div>
            <div className="flex items-center gap-2">
              <span className="text-sm text-muted-foreground">Hasta</span>
              <Input
                type="date"
                value={to}
                onChange={(e) => setTo(e.target.value)}
                className="w-40"
              />
            </div>
            {!loadingPayments && paymentsData && (
              <div className="ml-auto text-sm text-muted-foreground">
                {paymentsData.count} pago(s) — Total:{" "}
                <span className="font-semibold text-foreground">
                  {paymentsData.total.toLocaleString("es-PY", {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 2,
                  })}
                </span>
              </div>
            )}
          </div>

          {loadingPayments ? (
            <Skeleton className="h-48 w-full" />
          ) : (paymentsData?.rows ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground py-8 text-center">
              Sin pagos en el período seleccionado
            </p>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Fecha</TableHead>
                    <TableHead>Empresa</TableHead>
                    <TableHead className="text-right">Monto</TableHead>
                    <TableHead>Factura</TableHead>
                    <TableHead>Estado</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(paymentsData?.rows ?? []).map((row, i) => (
                    <TableRow key={i}>
                      <TableCell className="text-sm tabular-nums text-muted-foreground">
                        {row.date
                          ? new Date(row.date).toLocaleDateString("es-PY")
                          : "—"}
                      </TableCell>
                      <TableCell className="font-medium">
                        {row.companyName || "(sin nombre)"}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {row.amount.toLocaleString("es-PY", {
                          minimumFractionDigits: 0,
                          maximumFractionDigits: 2,
                        })}
                      </TableCell>
                      <TableCell className="text-sm tabular-nums text-muted-foreground">
                        {row.invoice || "—"}
                      </TableCell>
                      <TableCell>
                        <Badge
                          variant={row.status === 1 ? "default" : "secondary"}
                          className={row.status === 1 ? "bg-green-600 text-white" : ""}
                        >
                          {paymentStatusLabel(row.status)}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
