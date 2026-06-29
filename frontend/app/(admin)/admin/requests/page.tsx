"use client"

import * as React from "react"
import Link from "next/link"
import { FileText, Loader2 } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"
import { toast } from "sonner"

import { DataTable } from "@/components/data-table/data-table"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

import { useAdminRequests, useAdminResolveRequest, type BillingRequest } from "@/hooks/use-admin"

function statusBadge(status: string) {
  if (status === "pending") return <Badge variant="outline" className="text-amber-600 border-amber-600">Pendiente</Badge>
  if (status === "approved") return <Badge className="bg-green-600 text-white border-0">Aprobada</Badge>
  if (status === "rejected") return <Badge variant="destructive">Rechazada</Badge>
  return <Badge variant="secondary">{status}</Badge>
}

function fmtDate(v: string | null | undefined): string {
  if (!v) return "—"
  return new Date(v).toLocaleDateString("es-PY")
}

export default function AdminRequestsPage() {
  const [statusFilter, setStatusFilter] = React.useState("pending")
  const { data, isLoading } = useAdminRequests(statusFilter)
  const resolveRequest = useAdminResolveRequest()

  const rows = Array.isArray(data) ? data : []

  const columns: ColumnDef<BillingRequest, unknown>[] = [
    {
      accessorKey: "companyName",
      header: "Empresa",
      cell: ({ row }) => (
        <Link
          href={`/admin/companies/${row.original.companyId}`}
          className="font-medium hover:underline"
          onClick={(e) => e.stopPropagation()}
        >
          {row.original.companyName || "(sin nombre)"}
        </Link>
      ),
    },
    {
      accessorKey: "requestedPlanCode",
      header: "Plan solicitado",
      cell: ({ getValue }) => (
        <span className="tabular-nums">{getValue() as number}</span>
      ),
      meta: { label: "Plan solicitado" },
    },
    {
      accessorKey: "currentPlanCode",
      header: "Plan actual",
      cell: ({ getValue }) => {
        const v = getValue() as number | null
        return v != null ? <span className="tabular-nums text-muted-foreground">{v}</span> : <span className="opacity-40">—</span>
      },
      meta: { label: "Plan actual" },
    },
    {
      accessorKey: "note",
      header: "Nota",
      cell: ({ getValue }) => {
        const v = getValue() as string | null
        return v ? <span className="text-sm">{v}</span> : <span className="opacity-40">—</span>
      },
      meta: { label: "Nota" },
    },
    {
      accessorKey: "createdAt",
      header: "Fecha",
      cell: ({ getValue }) => (
        <span className="text-sm tabular-nums text-muted-foreground">
          {fmtDate(getValue() as string | null)}
        </span>
      ),
      meta: { label: "Fecha" },
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ getValue }) => statusBadge(getValue() as string),
      meta: { label: "Estado" },
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => {
        const req = row.original
        if (req.status !== "pending") return null
        return (
          <div className="flex gap-2" onClick={(e) => e.stopPropagation()}>
            <Button
              size="sm"
              variant="outline"
              className="text-green-600 border-green-600 hover:bg-green-50"
              disabled={resolveRequest.isPending}
              onClick={() =>
                resolveRequest.mutate(
                  { requestId: req.id, approve: true },
                  {
                    onSuccess: () => toast.success("Solicitud aprobada"),
                    onError: (err) => toast.error(err.message ?? "Error"),
                  },
                )
              }
            >
              {resolveRequest.isPending ? <Loader2 className="size-3 animate-spin" /> : "Aprobar"}
            </Button>
            <Button
              size="sm"
              variant="outline"
              className="text-destructive border-destructive hover:bg-red-50"
              disabled={resolveRequest.isPending}
              onClick={() =>
                resolveRequest.mutate(
                  { requestId: req.id, approve: false },
                  {
                    onSuccess: () => toast.success("Solicitud rechazada"),
                    onError: (err) => toast.error(err.message ?? "Error"),
                  },
                )
              }
            >
              Rechazar
            </Button>
          </div>
        )
      },
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <FileText className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Solicitudes de plan</h1>
      </div>

      <DataTable
        tableId="admin-requests"
        data={rows}
        columns={columns}
        isLoading={isLoading}
        getRowId={(r) => r.id}
        searchPlaceholder="Buscar empresa…"
        emptyMessage="Sin solicitudes"
        exportFileName="solicitudes-admin"
        toolbarSlot={
          <Select
            value={statusFilter || "__none__"}
            onValueChange={(v) => setStatusFilter(v === "__none__" ? "" : v)}
          >
            <SelectTrigger className="w-44">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="pending">Pendientes</SelectItem>
              <SelectItem value="approved">Aprobadas</SelectItem>
              <SelectItem value="rejected">Rechazadas</SelectItem>
              <SelectItem value="__none__">Todas</SelectItem>
            </SelectContent>
          </Select>
        }
      />
    </div>
  )
}
