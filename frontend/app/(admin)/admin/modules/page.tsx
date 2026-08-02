"use client"

import * as React from "react"
import { Blocks } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { DataTable } from "@/components/data-table/data-table"
import { Badge } from "@/components/ui/badge"
import { Switch } from "@/components/ui/switch"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"

import {
  useAdminModuleCatalog,
  useAdminUpdateModuleCatalog,
  type AdminModuleCatalogEntry,
} from "@/hooks/use-admin"
import { MODULES_CATALOG } from "@/lib/modules-catalog"
import { AdminRoleGate } from "@/components/admin/admin-role-gate"

const VISIBILITY_LABEL: Record<string, string> = {
  ga: "GA (disponible)",
  beta: "Beta",
  hidden: "Oculto",
}

function titleFor(key: string): string {
  return MODULES_CATALOG.find((m) => m.key === key)?.title ?? key
}

export default function AdminModulesPage() {
  return (
    <AdminRoleGate minRole="owner">
      <AdminModulesPageContent />
    </AdminRoleGate>
  )
}

function AdminModulesPageContent() {
  const { data, isLoading } = useAdminModuleCatalog()
  const update = useAdminUpdateModuleCatalog()
  const [pendingKill, setPendingKill] = React.useState<AdminModuleCatalogEntry | null>(null)

  const rows = data?.rows ?? []

  const columns: ColumnDef<AdminModuleCatalogEntry, unknown>[] = [
    {
      accessorKey: "key",
      header: "Módulo",
      cell: ({ row }) => (
        <div className="flex flex-col">
          <span className="font-medium">{titleFor(row.original.key)}</span>
          <span className="text-xs text-muted-foreground font-mono">{row.original.key}</span>
        </div>
      ),
    },
    {
      accessorKey: "price",
      header: "Precio",
      cell: ({ row }) => (
        <MoneyInput
          className="w-32"
          value={row.original.price}
          onChange={(v) =>
            update.mutate(
              { key: row.original.key, price: v ?? 0 },
              { onError: (err) => toast.error(err.message ?? "Error") },
            )
          }
        />
      ),
      meta: { label: "Precio" },
    },
    {
      accessorKey: "visibility",
      header: "Visibilidad",
      cell: ({ row }) => (
        <Select
          value={row.original.visibility}
          onValueChange={(v) =>
            update.mutate(
              { key: row.original.key, visibility: v },
              {
                onSuccess: () => toast.success("Visibilidad actualizada"),
                onError: (err) => toast.error(err.message ?? "Error"),
              },
            )
          }
        >
          <SelectTrigger className="w-40" onClick={(e) => e.stopPropagation()}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {Object.entries(VISIBILITY_LABEL).map(([v, label]) => (
              <SelectItem key={v} value={v}>{label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      ),
      meta: { label: "Visibilidad" },
    },
    {
      accessorKey: "killswitch",
      header: "Kill-switch",
      cell: ({ row }) => (
        <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
          <Switch
            checked={row.original.killswitch}
            onCheckedChange={(checked) => {
              if (checked) {
                setPendingKill(row.original)
              } else {
                update.mutate(
                  { key: row.original.key, killswitch: false },
                  {
                    onSuccess: () => toast.success("Módulo reactivado para todos los tenants"),
                    onError: (err) => toast.error(err.message ?? "Error"),
                  },
                )
              }
            }}
          />
          {row.original.killswitch && <Badge variant="destructive">apagado global</Badge>}
        </div>
      ),
      meta: { label: "Kill-switch" },
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Blocks className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Módulos</h1>
      </div>

      <DataTable
        tableId="admin-modules"
        data={rows}
        columns={columns}
        isLoading={isLoading}
        getRowId={(r) => r.key}
        searchPlaceholder="Buscar módulo…"
        emptyMessage="Sin módulos"
      />

      <AlertDialog open={!!pendingKill} onOpenChange={(v) => { if (!v) setPendingKill(null) }}>
        <AlertDialogContent className="sm:max-w-md">
          <AlertDialogHeader>
            <AlertDialogTitle>¿Apagar &quot;{pendingKill ? titleFor(pendingKill.key) : ""}&quot; para TODOS los tenants?</AlertDialogTitle>
            <AlertDialogDescription>
              Ningún tenant va a poder usar este módulo mientras el kill-switch esté activo, sin importar su
              configuración individual. No se toca el estado por-tenant — al apagar el switch, cada tenant vuelve
              exactamente a como estaba.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (!pendingKill) return
                update.mutate(
                  { key: pendingKill.key, killswitch: true },
                  {
                    onSuccess: () => toast.success("Módulo apagado para todos los tenants"),
                    onError: (err) => toast.error(err.message ?? "Error"),
                  },
                )
                setPendingKill(null)
              }}
            >
              Apagar para todos
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
