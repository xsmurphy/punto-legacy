"use client"

import * as React from "react"
import Link from "next/link"
import { Building2, TrendingUp, Users, AlertCircle, PlusCircle, Clock } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Button } from "@/components/ui/button"
import { useAdminCompanies, useAdminRequests, type AdminCompanyRow } from "@/hooks/use-admin"

// TODO: reemplazar por /admin/dashboard endpoint cuando se agregue al backend.
// Por ahora los KPIs se computan client-side desde la lista de empresas.

function kpiFromRows(rows: AdminCompanyRow[]) {
  const now = new Date()
  const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1)

  const total = rows.length
  const active = rows.filter((r) => r.status === "active" && !r.blocked).length
  const trial = rows.filter((r) => r.status === "active" && r.planExpired).length
  const suspended = rows.filter(
    (r) => r.status === "cancelled" || r.status === "suspended" || r.blocked,
  ).length
  const newThisMonth = rows.filter((r) => {
    if (!r.createdAt) return false
    return new Date(r.createdAt) >= startOfMonth
  }).length

  return { total, active, trial, suspended, newThisMonth }
}

function statusBadge(status: string, blocked: number) {
  if (blocked) return <Badge variant="destructive">Bloqueada</Badge>
  if (status === "active") return <Badge className="bg-green-600 text-white">Activa</Badge>
  if (status === "cancelled") return <Badge variant="destructive">Cancelada</Badge>
  if (status === "suspended") return <Badge variant="outline" className="text-amber-600 border-amber-600">Suspendida</Badge>
  return <Badge variant="secondary">{status}</Badge>
}

export default function AdminDashboardPage() {
  const { data: companiesData, isLoading: loadingCompanies } = useAdminCompanies({ limit: 500 })
  const { data: pendingRequests, isLoading: loadingRequests } = useAdminRequests("pending")

  const rows = companiesData?.rows ?? []
  const kpi = kpiFromRows(rows)
  const pendingCount = Array.isArray(pendingRequests) ? pendingRequests.length : 0

  // Últimas 10 empresas creadas
  const latestCompanies = [...rows]
    .sort((a, b) => {
      const da = a.createdAt ? new Date(a.createdAt).getTime() : 0
      const db = b.createdAt ? new Date(b.createdAt).getTime() : 0
      return db - da
    })
    .slice(0, 10)

  // Top 5 solicitudes pendientes
  const topRequests = Array.isArray(pendingRequests) ? pendingRequests.slice(0, 5) : []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Dashboard</h1>
        <p className="text-muted-foreground text-sm mt-0.5">Vista general del sistema</p>
      </div>

      {/* KPI cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Total empresas</CardTitle>
            <Building2 className="size-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            {loadingCompanies ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold">{kpi.total}</div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Activas</CardTitle>
            <TrendingUp className="size-4 text-green-600" />
          </CardHeader>
          <CardContent>
            {loadingCompanies ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold text-green-600">{kpi.active}</div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Trial/Vencidas</CardTitle>
            <Clock className="size-4 text-amber-500" />
          </CardHeader>
          <CardContent>
            {loadingCompanies ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold text-amber-500">{kpi.trial}</div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Suspendidas</CardTitle>
            <AlertCircle className="size-4 text-destructive" />
          </CardHeader>
          <CardContent>
            {loadingCompanies ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold text-destructive">{kpi.suspended}</div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Nuevas este mes</CardTitle>
            <PlusCircle className="size-4 text-blue-500" />
          </CardHeader>
          <CardContent>
            {loadingCompanies ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold text-blue-500">{kpi.newThisMonth}</div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Dos columnas */}
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

        {/* Últimas empresas */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Últimas empresas registradas</CardTitle>
          </CardHeader>
          <CardContent>
            {loadingCompanies ? (
              <div className="space-y-2">
                {[...Array(5)].map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
              </div>
            ) : latestCompanies.length === 0 ? (
              <p className="text-sm text-muted-foreground py-4 text-center">Sin datos</p>
            ) : (
              <div className="space-y-1.5">
                {latestCompanies.map((c) => (
                  <Link
                    key={c.id}
                    href={`/admin/companies/${c.id}`}
                    className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-accent/50 transition-colors"
                  >
                    <div className="truncate">
                      <span className="text-sm font-medium truncate">{c.name || c.companyName}</span>
                      {c.country && (
                        <span className="ml-2 text-xs text-muted-foreground">{c.country}</span>
                      )}
                    </div>
                    <div className="ml-2 shrink-0">
                      {statusBadge(c.status, c.blocked)}
                    </div>
                  </Link>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <p className="text-xs text-muted-foreground">
        {/* TODO: agregar endpoint /admin/dashboard en backend para KPIs agregados (MRR, créditos IA del mes, etc.) */}
        Los KPIs se calculan client-side desde la lista de empresas (V1). Para MRR real se requiere endpoint dedicado.
      </p>
    </div>
  )
}
