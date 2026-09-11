"use client"

import * as React from "react"
import { ArrowRightLeft, TriangleAlert } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { DataTable } from "@/components/data-table/data-table"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { AdminRoleGate } from "@/components/admin/admin-role-gate"
import { MigrationFormDialog } from "@/components/admin/migration-form-dialog"
import { MigrationDetailDialog } from "@/components/admin/migration-detail-dialog"
import {
  useAdminMigrations,
  type AdminMigrationJob,
  type AdminMigrationStatus,
} from "@/hooks/use-admin"
import { formatDateTime } from "@/lib/format-date"

const STATUS_LABEL: Record<AdminMigrationStatus, string> = {
  pending: "En cola",
  running: "Importando",
  done: "Lista",
  failed: "Con errores",
}

const STATUS_VARIANT: Record<AdminMigrationStatus, "default" | "secondary" | "destructive" | "outline"> = {
  pending: "outline",
  running: "secondary",
  done: "default",
  failed: "destructive",
}

const DOMAIN_LABEL: Record<string, string> = {
  catalog: "Catálogo",
  customers: "Clientes",
  config: "Configuración",
  users: "Usuarios",
  payments: "Medios de pago",
}

/** Suma los `imported` de todos los dominios, ignorando `options`. */
function importedTotal(progress: Record<string, unknown>): number {
  return Object.entries(progress).reduce((acc, [key, v]) => {
    if (key === "options" || typeof v !== "object" || v === null) return acc
    const n = (v as { imported?: unknown }).imported
    return acc + (typeof n === "number" ? n : 0)
  }, 0)
}

export default function AdminMigrationsPage() {
  return (
    <AdminRoleGate minRole="support">
      <AdminMigrationsPageContent />
    </AdminRoleGate>
  )
}

function AdminMigrationsPageContent() {
  const { data, isLoading } = useAdminMigrations()
  const [formOpen, setFormOpen] = React.useState(false)
  const [detailId, setDetailId] = React.useState<string | null>(null)

  const jobs = data?.jobs ?? []
  const ready = data?.ready ?? true

  const columns: ColumnDef<AdminMigrationJob, unknown>[] = [
    {
      accessorKey: "companyName",
      header: "Empresa destino",
      cell: ({ row }) => (
        <div className="flex flex-col">
          <span className="font-medium">{row.original.companyName || "(sin nombre)"}</span>
          {/* "panel legacy" y no la marca vieja: regla #2 del proyecto — no se
              introduce ENCOM en UI nueva. */}
          <span className="text-sm text-muted-foreground">Desde el panel legacy</span>
        </div>
      ),
      meta: { label: "Empresa destino" },
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ row }) => (
        <Badge variant={STATUS_VARIANT[row.original.status]}>
          {STATUS_LABEL[row.original.status] ?? row.original.status}
        </Badge>
      ),
      meta: { label: "Estado" },
    },
    {
      accessorKey: "domains",
      header: "Qué migra",
      cell: ({ row }) => (
        <span className="text-sm text-muted-foreground">
          {row.original.domains.map((d) => DOMAIN_LABEL[d] ?? d).join(", ") || "—"}
        </span>
      ),
      meta: { label: "Qué migra" },
    },
    {
      id: "imported",
      header: "Importado",
      cell: ({ row }) => {
        const n = importedTotal(row.original.progress)
        const failed = row.original.errors.length
        return (
          <div className="flex items-center gap-2">
            <span className="text-sm">{n} registros</span>
            {failed > 0 && (
              <Badge variant="destructive">
                {failed} {failed === 1 ? "error" : "errores"}
              </Badge>
            )}
          </div>
        )
      },
      meta: { label: "Importado" },
    },
    {
      accessorKey: "createdAt",
      header: "Lanzada",
      cell: ({ row }) => (
        <span className="text-sm text-muted-foreground">
          {row.original.createdAt ? formatDateTime(row.original.createdAt) : "—"}
        </span>
      ),
      meta: { label: "Lanzada" },
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-center gap-3">
        <ArrowRightLeft className="size-5 text-muted-foreground" />
        <div className="flex-1">
          <h1 className="text-2xl font-semibold">Migraciones</h1>
          <p className="text-sm text-muted-foreground">
            Trae el catálogo con sus combos y recetas, los clientes, las sucursales y cajas, los usuarios y
            los medios de pago de un cliente del sistema legacy a una empresa de Punto ya creada.
          </p>
        </div>
        <Button onClick={() => setFormOpen(true)} disabled={!ready}>
          Nueva migración
        </Button>
      </header>

      {/* Sin la variable de entorno no hay a dónde conectarse. Se avisa ACÁ y se
          bloquea el botón: el operador no puede descubrirlo después de haber
          tipeado la contraseña de un cliente. */}
      {!ready && (
        <Alert variant="destructive">
          <TriangleAlert className="size-4" />
          <AlertTitle>Falta configurar el acceso al panel legacy</AlertTitle>
          <AlertDescription>
            La variable de entorno <code>ENCOM_MIGRATION_URL</code> no está definida en el backend. Cargala
            en Coolify y volvé a entrar para poder lanzar migraciones.
          </AlertDescription>
        </Alert>
      )}

      <DataTable
        tableId="admin-migrations"
        data={jobs}
        columns={columns}
        isLoading={isLoading}
        getRowId={(r) => r.jobId}
        onRowClick={(r) => setDetailId(r.jobId)}
        searchPlaceholder="Buscar empresa…"
        emptyMessage="Todavía no se migró ningún cliente"
      />

      <MigrationFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        onCreated={(jobId) => {
          toast.success("Migración en cola. Empieza en menos de dos minutos.")
          setDetailId(jobId)
        }}
      />

      <MigrationDetailDialog
        jobId={detailId}
        onOpenChange={(v) => {
          if (!v) setDetailId(null)
        }}
      />
    </div>
  )
}
