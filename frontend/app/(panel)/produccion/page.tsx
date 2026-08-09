"use client"

import * as React from "react"
import { useRouter, useSearchParams } from "next/navigation"
import { Factory, Plus, Trash2 } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { DateRangePicker, rangeToBackend } from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"
import { useProductionOrders } from "@/hooks/use-production"
import { useWasteEvents } from "@/hooks/use-waste"
import { formatMoney, formatInt } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"
import type { ProductionOrder, ProductionStatus, WasteEvent } from "@/lib/types/production"

import { NewProductionDialog } from "@/components/domain/production/new-production-dialog"
import { ProductionDetailDialog } from "@/components/domain/production/production-detail-dialog"
import { RegisterWasteDialog } from "@/components/domain/production/register-waste-dialog"
import { PRODUCTION_STATUS_META } from "@/components/domain/production/status-meta"

const ALL_STATUS = "__all__"

export default function ProduccionPage() {
  // useSearchParams() requiere Suspense boundary (Next App Router) — mismo
  // patrón que items/page.tsx; ver comentario en pos/layout.tsx.
  return (
    <React.Suspense fallback={null}>
      <ProduccionPageInner />
    </React.Suspense>
  )
}

function ProduccionPageInner() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const { data: bootstrap } = useBootstrap()
  const canManage = usePermission("production.manage")
  const { range, setRange } = useDateRange()

  const [status, setStatus] = React.useState<ProductionStatus | typeof ALL_STATUS>(ALL_STATUS)
  const [newOrderOpen, setNewOrderOpen] = React.useState(false)
  const [wasteDialogOpen, setWasteDialogOpen] = React.useState(false)
  const [detailOrderId, setDetailOrderId] = React.useState<string | null>(null)

  // ?newItemId=<id> llega desde el botón "Producir" del detalle de item —
  // abre el dialog de nueva orden con el producto preseleccionado.
  const newItemId = searchParams.get("newItemId")
  React.useEffect(() => {
    if (newItemId) setNewOrderOpen(true)
  }, [newItemId])

  const closeNewOrder = (open: boolean) => {
    setNewOrderOpen(open)
    if (!open && newItemId) {
      router.replace("/produccion")
    }
  }

  const { from, to } = rangeToBackend(range)
  const { data: ordersData, isLoading: ordersLoading } = useProductionOrders({
    status: status === ALL_STATUS ? null : status,
    from,
    to,
  })
  const { data: wasteData, isLoading: wasteLoading } = useWasteEvents({ from, to })

  const orderColumns: ColumnDef<ProductionOrder, unknown>[] = React.useMemo(
    () => [
      {
        id: "docNumber",
        header: "Nº",
        // Correlativo por sucursal (mig 129). Los registros anteriores a la
        // migración pueden no tener número.
        cell: ({ row }) => (
          <span className="tabular-nums text-sm text-muted-foreground">
            {row.original.docNumber ?? "—"}
          </span>
        ),
        meta: { label: "Nº de orden" },
      },
      {
        accessorKey: "itemName",
        header: "Producto",
        cell: ({ row }) => <span className="font-medium">{row.original.itemName ?? "—"}</span>,
        meta: { label: "Producto" },
      },
      {
        id: "qty",
        header: "Cantidad",
        cell: ({ row }) => {
          const o = row.original
          return (
            <span className="tabular-nums text-sm">
              {formatInt(o.qtyPlanned, bootstrap)}
              {o.qtyProduced !== null && (
                <span className="text-muted-foreground"> ({formatInt(o.qtyProduced, bootstrap)} ok)</span>
              )}
            </span>
          )
        },
        meta: { label: "Cantidad" },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ row }) => {
          const meta = PRODUCTION_STATUS_META[row.original.status]
          return <Badge variant={meta.variant}>{meta.label}</Badge>
        },
        meta: { label: "Estado" },
      },
      {
        id: "unitCogs",
        header: "Costo unitario",
        cell: ({ row }) => (
          <span className="tabular-nums text-sm text-muted-foreground">
            {row.original.unitCogs !== null ? formatMoney(row.original.unitCogs, bootstrap) : "—"}
          </span>
        ),
        meta: { label: "Costo unitario" },
      },
      {
        id: "createdAt",
        header: "Fecha",
        cell: ({ row }) => (
          <span className="text-sm text-muted-foreground">
            {row.original.createdAt ? formatDateTime(row.original.createdAt) : "—"}
          </span>
        ),
        meta: { label: "Fecha" },
      },
    ],
    [bootstrap],
  )

  const wasteColumns: ColumnDef<WasteEvent, unknown>[] = React.useMemo(
    () => [
      {
        id: "docNumber",
        header: "Nº",
        // Correlativo por sucursal (mig 129). Los registros anteriores a la
        // migración pueden no tener número.
        cell: ({ row }) => (
          <span className="tabular-nums text-sm text-muted-foreground">
            {row.original.docNumber ?? "—"}
          </span>
        ),
        meta: { label: "Nº de merma" },
      },
      {
        accessorKey: "itemName",
        header: "Producto",
        cell: ({ row }) => <span className="font-medium">{row.original.itemName}</span>,
        meta: { label: "Producto" },
      },
      {
        accessorKey: "qty",
        header: "Cantidad",
        cell: ({ row }) => (
          <span className="tabular-nums text-sm">{formatInt(row.original.qty, bootstrap)}</span>
        ),
        meta: { label: "Cantidad" },
      },
      {
        accessorKey: "reasonName",
        header: "Motivo",
        cell: ({ row }) => row.original.reasonName ?? "—",
        meta: { label: "Motivo" },
      },
      {
        id: "source",
        header: "Origen",
        cell: ({ row }) => (
          <Badge variant="outline">
            {row.original.source === "production" ? "Producción" : "Manual"}
          </Badge>
        ),
        meta: { label: "Origen" },
      },
      {
        id: "cost",
        header: "Costo",
        cell: ({ row }) => (
          <span className="tabular-nums text-sm text-muted-foreground">
            {row.original.cost !== null ? formatMoney(row.original.cost, bootstrap) : "—"}
          </span>
        ),
        meta: { label: "Costo" },
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        cell: ({ row }) => row.original.userName ?? "—",
        meta: { label: "Usuario" },
      },
      {
        id: "createdAt",
        header: "Fecha",
        cell: ({ row }) => (
          <span className="text-sm text-muted-foreground">
            {row.original.createdAt ? formatDateTime(row.original.createdAt) : "—"}
          </span>
        ),
        meta: { label: "Fecha" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Producción</h1>
          <p className="text-sm text-muted-foreground">
            Órdenes de fabricación y merma de insumos y productos terminados.
          </p>
        </div>
        {canManage && (
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => setWasteDialogOpen(true)}>
              <Trash2 className="size-4" />
              Registrar merma
            </Button>
            <Button onClick={() => setNewOrderOpen(true)}>
              <Plus className="size-4" />
              Nueva producción
            </Button>
          </div>
        )}
      </header>

      <Tabs defaultValue="orders">
        <TabsList>
          <TabsTrigger value="orders" className="gap-1.5">
            <Factory className="size-3.5" />
            Órdenes
          </TabsTrigger>
          <TabsTrigger value="waste" className="gap-1.5">
            <Trash2 className="size-3.5" />
            Mermas
          </TabsTrigger>
        </TabsList>

        <TabsContent value="orders" className="mt-6">
          <DataTable
            tableId="production-orders"
            data={ordersData?.orders ?? []}
            columns={orderColumns}
            getRowId={(row) => row.id}
            onRowClick={(row) => setDetailOrderId(row.id)}
            isLoading={ordersLoading}
            searchPlaceholder="Buscar por producto…"
            exportFileName="ordenes-produccion"
            toolbarSlot={
              <div className="flex items-center gap-2">
                <Select
                  value={status}
                  onValueChange={(v) => setStatus(v as ProductionStatus | typeof ALL_STATUS)}
                >
                  <SelectTrigger className="w-40">
                    <SelectValue placeholder="Estado" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL_STATUS}>Todos los estados</SelectItem>
                    <SelectItem value="draft">Borrador</SelectItem>
                    <SelectItem value="in_progress">En curso</SelectItem>
                    <SelectItem value="completed">Completada</SelectItem>
                    <SelectItem value="cancelled">Cancelada</SelectItem>
                  </SelectContent>
                </Select>
                <DateRangePicker value={range} onChange={setRange} />
              </div>
            }
            emptyMessage={
              <EmptyState
                icon={Factory}
                title="Sin órdenes de producción"
                description={
                  canManage
                    ? "Creá la primera con el botón Nueva producción."
                    : "Todavía no se registró ninguna producción en este rango."
                }
                showMarquee={false}
                className="border-0 py-6"
              />
            }
          />
        </TabsContent>

        <TabsContent value="waste" className="mt-6">
          <DataTable
            tableId="waste-events"
            data={wasteData?.wasteEvents ?? []}
            columns={wasteColumns}
            getRowId={(row) => row.id}
            isLoading={wasteLoading}
            searchPlaceholder="Buscar por producto…"
            exportFileName="mermas"
            toolbarSlot={<DateRangePicker value={range} onChange={setRange} />}
            emptyMessage={
              <EmptyState
                icon={Trash2}
                title="Sin mermas registradas"
                description="Las mermas de órdenes completadas y las registradas a mano aparecen acá."
                showMarquee={false}
                className="border-0 py-6"
              />
            }
          />
        </TabsContent>
      </Tabs>

      <NewProductionDialog open={newOrderOpen} onOpenChange={closeNewOrder} initialItemId={newItemId} />
      <RegisterWasteDialog open={wasteDialogOpen} onOpenChange={setWasteDialogOpen} />
      {detailOrderId && (
        <ProductionDetailDialog
          orderId={detailOrderId}
          open={!!detailOrderId}
          onOpenChange={(open) => !open && setDetailOrderId(null)}
        />
      )}
    </div>
  )
}
