"use client"

import { ServerCog, ExternalLink, CheckCircle2, XCircle } from "lucide-react"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { AdminRoleGate } from "@/components/admin/admin-role-gate"

import { useAdminSystemStatus } from "@/hooks/use-admin"

function niceDateTime(v: string | null): string {
  if (!v) return "—"
  return new Date(v).toLocaleString("es-PY", { dateStyle: "medium", timeStyle: "short" })
}

function AdminSystemPageContent() {
  const { data, isLoading } = useAdminSystemStatus()

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <ServerCog className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Sistema</h1>
      </div>

      {isLoading ? (
        <div className="space-y-4">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-48 w-full" />
        </div>
      ) : data ? (
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-base font-semibold tracking-tight">Versión</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">App version</span>
                <span className="font-mono">{data.version.appVersion}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Deploy (mtime)</span>
                <span className="tabular-nums">{niceDateTime(data.version.deployedAt)}</span>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base font-semibold tracking-tight">Contadores</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Tenants</span>
                <span className="tabular-nums font-medium">{data.counts.tenants.toLocaleString("es-PY")}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Usuarios</span>
                <span className="tabular-nums font-medium">{data.counts.users.toLocaleString("es-PY")}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Transacciones hoy</span>
                <span className="tabular-nums font-medium">
                  {data.counts.transactionsToday.toLocaleString("es-PY")}
                </span>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base font-semibold tracking-tight">Errores (Sentry)</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex items-center gap-2">
                {data.sentry.configured ? (
                  <CheckCircle2 className="size-4 text-primary" />
                ) : (
                  <XCircle className="size-4 text-muted-foreground" />
                )}
                <span>{data.sentry.configured ? "Configurado" : "No configurado (SENTRY_DSN vacío)"}</span>
              </div>
              {data.sentry.link && (
                <a
                  href={data.sentry.link}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
                >
                  Ver en Sentry
                  <ExternalLink className="size-3.5" />
                </a>
              )}
            </CardContent>
          </Card>

          <Card className="md:col-span-2">
            <CardHeader>
              <CardTitle className="text-base font-semibold tracking-tight">
                Últimas migraciones aplicadas
              </CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Archivo</TableHead>
                    <TableHead className="text-right">Aplicada</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.migrations.map((m) => (
                    <TableRow key={m.filename}>
                      <TableCell className="font-mono text-sm">{m.filename}</TableCell>
                      <TableCell className="text-right tabular-nums text-sm text-muted-foreground">
                        {niceDateTime(m.appliedAt)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>
      ) : (
        <Badge variant="destructive">No se pudo cargar el estado del sistema</Badge>
      )}
    </div>
  )
}

export default function AdminSystemPage() {
  return (
    <AdminRoleGate minRole="owner">
      <AdminSystemPageContent />
    </AdminRoleGate>
  )
}
