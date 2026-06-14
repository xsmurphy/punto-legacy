"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { Building2 } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useAdminCompanies, type AdminCompanyRow } from "@/hooks/use-admin"

function statusBadge(status: string, blocked: number) {
  if (blocked) return <Badge variant="destructive">Bloqueada</Badge>
  if (status === "active") return <Badge className="bg-green-600 text-white border-0">Activa</Badge>
  if (status === "cancelled") return <Badge variant="destructive">Cancelada</Badge>
  if (status === "suspended")
    return (
      <Badge variant="outline" className="text-amber-600 border-amber-600">
        Suspendida
      </Badge>
    )
  return <Badge variant="secondary">{status || "—"}</Badge>
}

const columns: ColumnDef<AdminCompanyRow, unknown>[] = [
  {
    accessorKey: "name",
    header: "Empresa",
    cell: ({ row }) => {
      const c = row.original
      return (
        <div className="flex flex-col">
          <span className="font-medium">{c.name || c.companyName || "(sin nombre)"}</span>
          {c.owner && (
            <span className="text-xs text-muted-foreground truncate">
              {[c.owner.name, c.owner.secondName].filter(Boolean).join(" ")}
              {c.owner.email ? ` · ${c.owner.email}` : ""}
            </span>
          )}
        </div>
      )
    },
  },
  {
    accessorKey: "status",
    header: "Estado",
    cell: ({ row }) => statusBadge(row.original.status, row.original.blocked),
    meta: { label: "Estado" },
  },
  {
    accessorKey: "plan",
    header: "Plan",
    cell: ({ getValue }) => {
      const v = getValue() as number | null
      return v != null ? (
        <span className="text-sm tabular-nums">{v}</span>
      ) : (
        <span className="opacity-40">—</span>
      )
    },
    meta: { label: "Plan" },
  },
  {
    accessorKey: "country",
    header: "País",
    cell: ({ getValue }) => {
      const v = getValue() as string
      return v ? <span className="text-sm">{v}</span> : <span className="opacity-40">—</span>
    },
    meta: { label: "País" },
  },
  {
    accessorKey: "createdAt",
    header: "Creada",
    cell: ({ getValue }) => {
      const v = getValue() as string | null
      if (!v) return <span className="opacity-40">—</span>
      return (
        <span className="text-sm tabular-nums text-muted-foreground">
          {new Date(v).toLocaleDateString("es-PY")}
        </span>
      )
    },
    meta: { label: "Creada" },
  },
  {
    id: "counts",
    header: "Sucursales",
    cell: ({ row }) => {
      const counts = row.original.counts
      if (!counts) return <span className="opacity-40">—</span>
      return (
        <span className="text-sm tabular-nums text-muted-foreground">
          {counts.outlets} suc · {counts.registers} cajas
        </span>
      )
    },
    meta: { label: "Sucursales/Cajas" },
  },
]

export default function AdminCompaniesPage() {
  const router = useRouter()
  const [statusFilter, setStatusFilter] = React.useState<string>("all")
  const [q, setQ] = React.useState("")

  const { data, isLoading } = useAdminCompanies({ limit: 500 })
  const rows = data?.rows ?? []

  const filtered = React.useMemo(() => {
    let out = rows
    if (statusFilter !== "all") {
      if (statusFilter === "blocked") {
        out = out.filter((r) => r.blocked)
      } else {
        out = out.filter((r) => r.status === statusFilter && !r.blocked)
      }
    }
    return out
  }, [rows, statusFilter])

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Building2 className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Empresas</h1>
        {data?.total != null && (
          <span className="text-sm text-muted-foreground">({data.total} total)</span>
        )}
      </div>

      <DataTable
        tableId="admin-companies"
        data={filtered}
        columns={columns}
        isLoading={isLoading}
        getRowId={(r) => r.id}
        onRowClick={(r) => router.push(`/admin/companies/${r.id}`)}
        searchPlaceholder="Buscar por nombre, email, país…"
        exportFileName="empresas-admin"
        emptyMessage="Sin empresas"
        toolbarSlot={
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="w-40">
              <SelectValue placeholder="Estado" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Todos los estados</SelectItem>
              <SelectItem value="active">Activas</SelectItem>
              <SelectItem value="suspended">Suspendidas</SelectItem>
              <SelectItem value="cancelled">Canceladas</SelectItem>
              <SelectItem value="blocked">Bloqueadas</SelectItem>
            </SelectContent>
          </Select>
        }
      />
    </div>
  )
}
