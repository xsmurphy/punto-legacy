"use client"

import * as React from "react"
import Link from "next/link"
import { Building2, TrendingUp, Users, AlertCircle, PlusCircle, Clock, DollarSign, Zap, HeartPulse } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/empty-state"
import { useAdminOverview, useAdminRequests, useAdminHealthList } from "@/hooks/use-admin"

function healthScoreBadge(level: string, score: number) {
  const cls =
    level === "green"
      ? "bg-emerald-600 text-white border-0"
      : level === "yellow"
        ? "bg-amber-500 text-white border-0"
        : "bg-destructive text-destructive-foreground border-0"
  return <Badge className={`${cls} text-xs tabular-nums`}>{score}</Badge>
}

function statusBadge(status: string, blocked: number) {
  if (blocked) return <Badge variant="destructive">Bloqueada</Badge>
  if (status === "active") return <Badge className="bg-green-600 text-white">Activa</Badge>
  if (status === "cancelled") return <Badge variant="destructive">Cancelada</Badge>
  if (status === "suspended")
    return (
      <Badge variant="outline" className="text-amber-600 border-amber-600">
        Suspendida
      </Badge>
    )
  return <Badge variant="secondary">{status}</Badge>
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

  const companies = overview?.companies
  const mrr = overview?.mrr ?? 0
  const arr = overview?.arr ?? 0
  const newThisMonth = overview?.newThisMonth ?? 0

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Dashboard</h1>
        <p className="text-muted-foreground text-sm mt-0.5">Vista general del sistema</p>
      </div>

      {/* KPI cards — fila 1: empresas */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Total empresas</CardTitle>
            <Building2 className="size-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            {loadingOverview ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold">{companies?.total ?? 0}</div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Activas</CardTitle>
            <TrendingUp className="size-4 text-green-600" />
          </CardHeader>
          <CardContent>
            {loadingOverview ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold text-green-600">{companies?.active ?? 0}</div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Trial/Vencidas</CardTitle>
            <Clock className="size-4 text-amber-500" />
          </CardHeader>
          <CardContent>
            {loadingOverview ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold text-amber-500">{companies?.trial ?? 0}</div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Suspendidas</CardTitle>
            <AlertCircle className="size-4 text-destructive" />
          </CardHeader>
          <CardContent>
            {loadingOverview ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold text-destructive">{companies?.suspended ?? 0}</div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Nuevas este mes</CardTitle>
            <PlusCircle className="size-4 text-blue-500" />
          </CardHeader>
          <CardContent>
            {loadingOverview ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="text-3xl font-bold text-blue-500">{newThisMonth}</div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* KPI cards — fila 2: financiero */}
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">MRR</CardTitle>
            <DollarSign className="size-4 text-emerald-600" />
          </CardHeader>
          <CardContent>
            {loadingOverview ? (
              <Skeleton className="h-8 w-24" />
            ) : (
              <div className="text-3xl font-bold text-emerald-600">
                {mrr.toLocaleString("es-PY", { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
              </div>
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
              <div className="text-3xl font-bold text-emerald-700">
                {arr.toLocaleString("es-PY", { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Solicitudes pendientes</CardTitle>
            <Users className="size-4 text-orange-500" />
          </CardHeader>
          <CardContent>
            {loadingRequests ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <div className="flex items-center gap-2">
                <div className="text-3xl font-bold text-orange-500">{pendingCount}</div>
                {pendingCount > 0 && (
                  <Link href="/admin/requests">
                    <Badge variant="destructive" className="text-xs cursor-pointer">Ver</Badge>
                  </Link>
                )}
              </div>
            )}
          </CardContent>
        </Card>
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
                      {c.balance.toLocaleString("es-PY")} cr.
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
