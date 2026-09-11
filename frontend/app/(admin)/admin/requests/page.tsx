"use client"

/**
 * Cola de solicitudes de los tenants.
 *
 * Dos tipos, misma pantalla: CAMBIO DE PLAN (`billing_request`) y ALTA DE
 * SUCURSAL (`outlet_request`, mig 219). Van juntas y no en dos entradas del
 * menú porque para el que atiende son la misma tarea —un comercio pidió algo y
 * hay que resolverlo— y el nav de /admin ya se llama "Solicitudes".
 *
 * La diferencia que sí importa está en la acción: aprobar una sucursal CREA la
 * sucursal (con su depósito y su caja) y sube la facturación mensual del
 * tenant al precio de su plan. Rechazar exige motivo — el comercio lo recibe
 * por correo, así que "no" a secas no alcanza.
 */

import * as React from "react"
import Link from "next/link"
import { FileText, Check, X } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { toast } from "sonner"

import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Textarea } from "@/components/ui/textarea"

import {
  useAdminOutletRequests,
  useAdminRequests,
  useAdminResolveOutletRequest,
  useAdminResolveRequest,
  type AdminOutletRequest,
  type BillingRequest,
} from "@/hooks/use-admin"
import { formatPuntoSaasDate } from "@/lib/punto-saas-locale"

function statusBadge(status: string) {
  if (status === "pending") return <Badge variant="outline" className="text-amber-600 border-amber-600">Pendiente</Badge>
  if (status === "approved") return <Badge className="bg-green-600 text-white border-0">Aprobada</Badge>
  if (status === "rejected") return <Badge variant="destructive">Rechazada</Badge>
  return <Badge variant="secondary">{status}</Badge>
}

function fmtDate(v: string | null | undefined): string {
  if (!v) return "—"
  return formatPuntoSaasDate(v)
}

function empresaCell(companyId: string, companyName: string) {
  return (
    <Link
      href={`/admin/companies/${companyId}`}
      className="font-medium hover:underline"
      onClick={(e) => e.stopPropagation()}
    >
      {companyName || "(sin nombre)"}
    </Link>
  )
}

function StatusFilter({
  value,
  onChange,
}: {
  value: string
  onChange: (v: string) => void
}) {
  return (
    <Select
      value={value || "__none__"}
      onValueChange={(v) => onChange(v === "__none__" ? "" : v)}
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
  )
}

export default function AdminRequestsPage() {
  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <FileText className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Solicitudes</h1>
      </div>

      <Tabs defaultValue="outlets">
        <TabsList>
          <TabsTrigger value="outlets">Sucursales</TabsTrigger>
          <TabsTrigger value="plans">Planes</TabsTrigger>
        </TabsList>
        <TabsContent value="outlets" className="mt-4">
          <OutletRequestsTable />
        </TabsContent>
        <TabsContent value="plans" className="mt-4">
          <PlanRequestsTable />
        </TabsContent>
      </Tabs>
    </div>
  )
}

// ── Sucursales ───────────────────────────────────────────────────────────────

const rejectSchema = z.object({
  reason: z.string().trim().min(1, "El motivo es requerido").max(1000),
})

type RejectValues = z.infer<typeof rejectSchema>

function OutletRequestsTable() {
  const [statusFilter, setStatusFilter] = React.useState("pending")
  const { data, isLoading } = useAdminOutletRequests(statusFilter)
  const resolve = useAdminResolveOutletRequest()

  /** Solicitud que se está rechazando. `null` = diálogo cerrado. */
  const [rejecting, setRejecting] = React.useState<AdminOutletRequest | null>(null)

  const rows = data?.rows ?? []

  const columns: ColumnDef<AdminOutletRequest, unknown>[] = [
    {
      accessorKey: "companyName",
      header: "Empresa",
      cell: ({ row }) => empresaCell(row.original.companyId, row.original.companyName),
    },
    {
      accessorKey: "name",
      header: "Sucursal pedida",
      cell: ({ row }) => (
        <div className="flex flex-col">
          <span className="font-medium">{row.original.name}</span>
          {row.original.address && (
            <span className="text-xs text-muted-foreground">{row.original.address}</span>
          )}
        </div>
      ),
      meta: { label: "Sucursal pedida" },
    },
    {
      accessorKey: "requestedByName",
      header: "Quién",
      cell: ({ getValue }) => {
        const v = getValue() as string | null
        return v ? <span className="text-sm">{v}</span> : <span className="opacity-40">—</span>
      },
      meta: { label: "Quién" },
    },
    {
      accessorKey: "createdAt",
      header: "Cuándo",
      cell: ({ getValue }) => (
        <span className="text-sm tabular-nums text-muted-foreground">
          {fmtDate(getValue() as string | null)}
        </span>
      ),
      meta: { label: "Cuándo" },
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ getValue }) => statusBadge(getValue() as string),
      meta: { label: "Estado" },
    },
    {
      accessorKey: "reason",
      header: "Motivo",
      cell: ({ getValue }) => {
        const v = getValue() as string | null
        return v ? <span className="text-sm">{v}</span> : <span className="opacity-40">—</span>
      },
      meta: { label: "Motivo" },
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => {
        const req = row.original
        if (req.status !== "pending") return null
        return (
          <div onClick={(e) => e.stopPropagation()}>
            <RowActions
              actions={[
                {
                  label: "Aprobar",
                  icon: Check,
                  disabled: resolve.isPending,
                  onSelect: () =>
                    resolve.mutate(
                      { requestId: req.id, approve: true },
                      {
                        onSuccess: () => toast.success("Sucursal creada y solicitud aprobada"),
                        onError: (err) => toast.error(err.message ?? "Error"),
                      },
                    ),
                },
                {
                  label: "Rechazar",
                  icon: X,
                  variant: "destructive",
                  disabled: resolve.isPending,
                  // Abre el diálogo en vez de resolver de una: el motivo es
                  // obligatorio y viaja al comercio por correo.
                  onSelect: () => setRejecting(req),
                },
              ]}
            />
          </div>
        )
      },
    },
  ]

  return (
    <>
      <DataTable
        tableId="admin-outlet-requests"
        data={rows}
        columns={columns}
        isLoading={isLoading}
        getRowId={(r) => r.id}
        searchPlaceholder="Buscar empresa o sucursal…"
        emptyMessage="Sin solicitudes de sucursal"
        exportFileName="solicitudes-sucursal"
        toolbarSlot={<StatusFilter value={statusFilter} onChange={setStatusFilter} />}
      />

      <RejectOutletDialog
        request={rejecting}
        onOpenChange={(open) => !open && setRejecting(null)}
      />
    </>
  )
}

function RejectOutletDialog({
  request,
  onOpenChange,
}: {
  request: AdminOutletRequest | null
  onOpenChange: (open: boolean) => void
}) {
  return (
    <Dialog open={request !== null} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        {request && (
          <RejectOutletForm request={request} onDone={() => onOpenChange(false)} />
        )}
      </DialogContent>
    </Dialog>
  )
}

function RejectOutletForm({
  request,
  onDone,
}: {
  request: AdminOutletRequest
  onDone: () => void
}) {
  const resolve = useAdminResolveOutletRequest()
  const form = useForm<RejectValues>({
    resolver: zodResolver(rejectSchema),
    defaultValues: { reason: "" },
  })

  function onSubmit(values: RejectValues) {
    resolve.mutate(
      { requestId: request.id, approve: false, reason: values.reason },
      {
        onSuccess: () => {
          toast.success("Solicitud rechazada")
          onDone()
        },
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
        <DialogHeader>
          <DialogTitle>Rechazar solicitud</DialogTitle>
          <DialogDescription>
            {request.companyName || "(sin nombre)"} pidió la sucursal &ldquo;
            {request.name}&rdquo;. El motivo se le envía por correo.
          </DialogDescription>
        </DialogHeader>

        <FormField
          control={form.control}
          name="reason"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Motivo</FormLabel>
              <FormControl>
                <Textarea
                  autoFocus
                  rows={3}
                  placeholder="Ej: la cuenta tiene facturas vencidas"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={onDone}
            disabled={resolve.isPending}
          >
            Cancelar
          </Button>
          <Button type="submit" variant="destructive" disabled={resolve.isPending}>
            Rechazar
          </Button>
        </DialogFooter>
      </form>
    </Form>
  )
}

// ── Planes ───────────────────────────────────────────────────────────────────

function PlanRequestsTable() {
  const [statusFilter, setStatusFilter] = React.useState("pending")
  const { data, isLoading } = useAdminRequests(statusFilter)
  const resolveRequest = useAdminResolveRequest()

  const rows = Array.isArray(data) ? data : []

  const columns: ColumnDef<BillingRequest, unknown>[] = [
    {
      accessorKey: "companyName",
      header: "Empresa",
      cell: ({ row }) => empresaCell(row.original.companyId, row.original.companyName),
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
          <div onClick={(e) => e.stopPropagation()}>
            <RowActions
              actions={[
                {
                  label: "Aprobar",
                  icon: Check,
                  disabled: resolveRequest.isPending,
                  onSelect: () =>
                    resolveRequest.mutate(
                      { requestId: req.id, approve: true },
                      {
                        onSuccess: () => toast.success("Solicitud aprobada"),
                        onError: (err) => toast.error(err.message ?? "Error"),
                      },
                    ),
                },
                {
                  label: "Rechazar",
                  icon: X,
                  variant: "destructive",
                  disabled: resolveRequest.isPending,
                  onSelect: () =>
                    resolveRequest.mutate(
                      { requestId: req.id, approve: false },
                      {
                        onSuccess: () => toast.success("Solicitud rechazada"),
                        onError: (err) => toast.error(err.message ?? "Error"),
                      },
                    ),
                },
              ]}
            />
          </div>
        )
      },
    },
  ]

  return (
    <DataTable
      tableId="admin-requests"
      data={rows}
      columns={columns}
      isLoading={isLoading}
      getRowId={(r) => r.id}
      searchPlaceholder="Buscar empresa…"
      emptyMessage="Sin solicitudes"
      exportFileName="solicitudes-admin"
      toolbarSlot={<StatusFilter value={statusFilter} onChange={setStatusFilter} />}
    />
  )
}
