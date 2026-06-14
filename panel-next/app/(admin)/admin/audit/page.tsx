"use client"

import * as React from "react"
import { ScrollText, ChevronLeft, ChevronRight } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { useAdminAudit } from "@/hooks/use-admin"

const ACTION_LABELS: Record<string, { label: string; variant: "default" | "secondary" | "destructive" | "outline" }> = {
  updateCompany:  { label: "Editar empresa",    variant: "secondary" },
  grantAiCredits: { label: "Créditos IA",       variant: "default" },
  setAddons:      { label: "Add-ons",            variant: "secondary" },
  resolveRequest: { label: "Resolver solicitud", variant: "default" },
  suspendCompany: { label: "Suspender empresa",  variant: "destructive" },
  deleteCompany:  { label: "Eliminar empresa",   variant: "destructive" },
  impersonate:    { label: "Impersonar",         variant: "outline" },
  createAdmin:    { label: "Crear admin",        variant: "default" },
  updateAdmin:    { label: "Editar admin",       variant: "secondary" },
  setAdminStatus: { label: "Estado admin",       variant: "secondary" },
}

function ActionBadge({ action }: { action: string }) {
  const cfg = ACTION_LABELS[action]
  if (!cfg) return <Badge variant="secondary">{action}</Badge>
  return <Badge variant={cfg.variant}>{cfg.label}</Badge>
}

function MetaCell({ meta }: { meta: Record<string, unknown> }) {
  if (!meta || Object.keys(meta).length === 0) return <span className="text-muted-foreground">—</span>
  return (
    <span className="text-xs font-mono text-muted-foreground truncate max-w-[200px] block">
      {JSON.stringify(meta)}
    </span>
  )
}

const ALL_ACTIONS = Object.keys(ACTION_LABELS)
const PAGE_SIZE_OPTIONS = [25, 50, 100]

export default function AdminAuditPage() {
  const [page, setPage] = React.useState(1)
  const [pageSize, setPageSize] = React.useState(50)
  const [actionFilter, setActionFilter] = React.useState<string>("all")

  const { data, isLoading } = useAdminAudit({
    page,
    pageSize,
    action: actionFilter !== "all" ? actionFilter : undefined,
  })

  const rows = data?.rows ?? []
  const total = data?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / pageSize))

  // Reset page cuando cambia el filtro.
  const handleActionChange = (v: string) => {
    setActionFilter(v)
    setPage(1)
  }
  const handlePageSizeChange = (v: string) => {
    setPageSize(Number(v))
    setPage(1)
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <ScrollText className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Auditoría</h1>
        {total > 0 && (
          <span className="text-sm text-muted-foreground">({total} registros)</span>
        )}
      </div>

      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-wrap items-center gap-3">
            <CardTitle className="text-base">Log de acciones</CardTitle>
            <div className="flex items-center gap-2 ml-auto">
              <Select value={actionFilter} onValueChange={handleActionChange}>
                <SelectTrigger className="w-44">
                  <SelectValue placeholder="Acción" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todas las acciones</SelectItem>
                  {ALL_ACTIONS.map((a) => (
                    <SelectItem key={a} value={a}>
                      {ACTION_LABELS[a]?.label ?? a}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              <Select value={String(pageSize)} onValueChange={handlePageSizeChange}>
                <SelectTrigger className="w-24">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {PAGE_SIZE_OPTIONS.map((n) => (
                    <SelectItem key={n} value={String(n)}>{n} / pág.</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
        </CardHeader>

        <CardContent className="p-0">
          {isLoading ? (
            <div className="p-4 space-y-2">
              {[...Array(8)].map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
            </div>
          ) : rows.length === 0 ? (
            <p className="text-sm text-muted-foreground py-12 text-center">
              Sin registros de auditoría
            </p>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-36">Fecha</TableHead>
                    <TableHead>Admin</TableHead>
                    <TableHead>Acción</TableHead>
                    <TableHead>Entidad</TableHead>
                    <TableHead>Detalle</TableHead>
                    <TableHead className="w-28">IP</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell className="text-xs tabular-nums text-muted-foreground whitespace-nowrap">
                        {row.createdAt
                          ? new Date(row.createdAt).toLocaleString("es-PY", {
                              day: "2-digit",
                              month: "2-digit",
                              year: "numeric",
                              hour: "2-digit",
                              minute: "2-digit",
                            })
                          : "—"}
                      </TableCell>
                      <TableCell className="text-sm truncate max-w-[140px]">
                        {row.adminEmail || "—"}
                      </TableCell>
                      <TableCell>
                        <ActionBadge action={row.action} />
                      </TableCell>
                      <TableCell className="text-sm">
                        {row.targetType && (
                          <span className="text-muted-foreground">{row.targetType}: </span>
                        )}
                        {row.targetName && (
                          <span className="font-medium">{row.targetName}</span>
                        )}
                        {!row.targetName && row.targetId && (
                          <span className="font-mono text-xs text-muted-foreground truncate">{row.targetId}</span>
                        )}
                        {!row.targetType && !row.targetId && "—"}
                      </TableCell>
                      <TableCell>
                        <MetaCell meta={row.meta} />
                      </TableCell>
                      <TableCell className="text-xs font-mono text-muted-foreground">
                        {row.ip || "—"}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}

          {/* Paginación */}
          <div className="flex items-center justify-between px-4 py-3 border-t">
            <p className="text-xs text-muted-foreground">
              Página {page} de {totalPages} ({total} registros)
            </p>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1 || isLoading}
              >
                <ChevronLeft className="size-4" />
                Anterior
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page >= totalPages || isLoading}
              >
                Siguiente
                <ChevronRight className="size-4" />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
