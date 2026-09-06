"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { FileCheck, Plus } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { DataTable, FilterField } from "@/components/data-table/data-table"
import { DateRangePicker, rangeToBackend } from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useDateRange } from "@/hooks/use-date-range"
import { usePermission } from "@/hooks/use-permissions"
import { useContacts } from "@/hooks/use-contacts"
import {
  PAYMENT_ORDER_STATUS_META,
  PAYMENT_ORDER_STATUS_OPTIONS,
  usePaymentOrders,
  type PaymentOrderListRow,
  type PaymentOrderStatus,
} from "@/hooks/use-payment-orders"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import type { TenantLocaleConfig } from "@/lib/tenant-locale"

/** Valor centinela del `<Select>` para "sin filtro" — Radix no acepta value="". */
const ALL = "__all__"

const buildColumns = (
  config: TenantLocaleConfig | null | undefined,
): ColumnDef<PaymentOrderListRow>[] => [
  {
    id: "docNumber",
    header: "Nº",
    accessorFn: (row) => row.docNumber ?? "",
    meta: { label: "Nº de orden" },
    cell: ({ row }) => (
      <span className="tabular-nums text-sm text-muted-foreground">{row.original.docNumber ?? "—"}</span>
    ),
  },
  {
    accessorKey: "createdAt",
    header: "Creada",
    meta: { label: "Creada" },
    cell: ({ row }) => formatDate(row.original.createdAt),
  },
  {
    accessorKey: "supplierName",
    header: "Proveedor",
    meta: { label: "Proveedor" },
    cell: ({ row }) => row.original.supplierName || "—",
  },
  {
    accessorKey: "outletName",
    header: "Sucursal",
    meta: { label: "Sucursal" },
    cell: ({ row }) => row.original.outletName || "—",
  },
  {
    accessorKey: "lineCount",
    header: () => <div className="text-right">Facturas</div>,
    meta: { label: "Facturas", className: "text-right" },
    cell: ({ row }) => <div className="text-right tabular-nums">{row.original.lineCount}</div>,
  },
  {
    accessorKey: "paymentDate",
    header: "Pagar el",
    meta: { label: "Fecha de pago propuesta" },
    cell: ({ row }) => (row.original.paymentDate ? formatDate(row.original.paymentDate) : "—"),
  },
  {
    accessorKey: "total",
    header: () => <div className="text-right">Total</div>,
    meta: {
      label: "Total",
      className: "text-right",
      footerSum: true,
      footerFormat: (sum) => (
        <div className="text-right font-medium tabular-nums">{formatMoney(sum, config)}</div>
      ),
    },
    cell: ({ row }) => (
      <div className="text-right font-medium tabular-nums">{formatMoney(row.original.total, config)}</div>
    ),
  },
  {
    accessorKey: "status",
    header: "Estado",
    meta: { label: "Estado" },
    cell: ({ row }) => {
      const meta = PAYMENT_ORDER_STATUS_META[row.original.status]
      return <Badge variant={meta?.variant ?? "outline"}>{meta?.label ?? row.original.status}</Badge>
    },
  },
]

export default function PaymentOrdersPage() {
  const router = useRouter()
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const canCreate = usePermission("purchases.paymentorder.create")

  const [status, setStatus] = React.useState<PaymentOrderStatus | typeof ALL>(ALL)
  const [supplierId, setSupplierId] = React.useState<string>(ALL)

  const { from, to } = rangeToBackend(range)
  const { data, isLoading } = usePaymentOrders({
    status: status === ALL ? "" : status,
    supplierId: supplierId === ALL ? "" : supplierId,
    dateFrom: from,
    dateTo: to,
  })
  // type 2 = proveedores. El filtro de proveedor sale del mismo catálogo que
  // usa el alta, así que no hay dos listas que puedan divergir.
  const { data: suppliers } = useContacts({ type: 2 })

  const rows = data?.rows ?? []
  const columns = React.useMemo(() => buildColumns(bootstrap), [bootstrap])

  // Solo los filtros que ACOTAN el universo de filas. El rango de fechas vive
  // en la toolbar (es el control compartido de todo el panel), así que no se
  // cuenta acá — si no, el badge diría "1" siempre.
  const activeFilterCount = (status === ALL ? 0 : 1) + (supplierId === ALL ? 0 : 1)

  function clearFilters() {
    setStatus(ALL)
    setSupplierId(ALL)
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Órdenes de pago</h1>
          <p className="text-sm text-muted-foreground">
            El documento que autoriza pagarle a un proveedor: agrupá sus facturas pendientes, hacela
            aprobar, y recién ahí se ejecuta el pago.
          </p>
        </div>
        {canCreate ? (
          <div className="flex items-center gap-2">
            <Button onClick={() => router.push("/ordenes-pago/new")}>
              <Plus className="mr-2 h-4 w-4" />
              Nueva orden
            </Button>
          </div>
        ) : null}
      </header>

      <DataTable<PaymentOrderListRow>
        tableId="payment-orders"
        columns={columns}
        data={rows}
        getRowId={(r) => r.paymentOrderId}
        isLoading={isLoading}
        onRowClick={(row) => router.push(`/ordenes-pago/${row.paymentOrderId}`)}
        searchPlaceholder="Buscar por proveedor, sucursal, número…"
        exportFileName="ordenes_de_pago"
        toolbarSlot={<DateRangePicker value={range} onChange={setRange} />}
        activeFilterCount={activeFilterCount}
        onClearFilters={clearFilters}
        filtersSlot={
          <>
            <FilterField label="Estado">
              <Select value={status} onValueChange={(v) => setStatus(v as PaymentOrderStatus | typeof ALL)}>
                <SelectTrigger>
                  <SelectValue placeholder="Todos" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL}>Todos</SelectItem>
                  {PAYMENT_ORDER_STATUS_OPTIONS.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                      {o.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </FilterField>
            <FilterField label="Proveedor">
              <Select value={supplierId} onValueChange={setSupplierId}>
                <SelectTrigger>
                  <SelectValue placeholder="Todos" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL}>Todos</SelectItem>
                  {(suppliers?.contacts ?? []).map((c) => (
                    <SelectItem key={c.id} value={c.id}>
                      {c.name || c.fullname || "(sin nombre)"}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </FilterField>
          </>
        }
        emptyMessage={
          <EmptyState
            icon={FileCheck}
            title="Sin órdenes de pago"
            description="Armá una orden agrupando las facturas pendientes de un proveedor. Alguien con autoridad la aprueba y recién ahí se paga."
            actions={
              canCreate ? (
                <Button size="sm" variant="outline" onClick={() => router.push("/ordenes-pago/new")}>
                  <Plus className="mr-2 h-4 w-4" />
                  Nueva orden
                </Button>
              ) : undefined
            }
          />
        }
      />
    </div>
  )
}
