"use client"

/**
 * ContactTransactionsTab — tab "Transacciones" del detalle de contacto (panel).
 *
 * Lista las ventas del cliente (Factura contado/crédito, Devolución, Anulada)
 * vía GET /v1/reports/transactions?view=detail&cusId=<id> — el endpoint ya
 * soportaba el filtro `cusId` (TransactionsService::detail), sin cambios de
 * backend necesarios acá. El rango de fechas no aplica: cuando hay `cusId` el
 * backend ignora from/to y devuelve hasta 5000 filas del cliente.
 *
 * El click de fila navega a `/transactions/{id}` (F2,
 * context/39-detalle-transaccion.md) — la misma página dedicada que usa el
 * listado de `/reports/transacciones`. Antes abría un Dialog embebido
 * (`PanelDetailView`, eliminado junto con esta migración) con una vista
 * mucho más limitada que la página completa.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { Receipt } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type TransactionRow,
  type TransactionsReportResponse,
} from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"
import { txTypeLabel } from "@/components/domain/transactions/transactions-list"

interface Props {
  customerId: string
}

export function ContactTransactionsTab({ customerId }: Props) {
  const { data: bootstrap } = useBootstrap()
  const router = useRouter()
  const opts = React.useMemo(
    () => ({ params: { view: "detail", cusId: customerId } }),
    [customerId],
  )
  const { data, isLoading, error } = useReport<TransactionsReportResponse>("transactions", opts)
  const rows = data?.rows ?? []

  const columns = React.useMemo<ColumnDef<TransactionRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums">
            {formatDateTime((getValue() as string) ?? "")}
          </span>
        ),
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "docNo",
        header: "Documento",
        cell: ({ row }) => {
          const r = row.original
          return <span className="font-medium tabular-nums">{r.docNo || "—"}</span>
        },
        meta: { label: "Documento" },
      },
      {
        accessorKey: "transactionType",
        header: "Tipo",
        cell: ({ getValue }) => (
          <Badge variant="outline">{txTypeLabel(String(getValue()))}</Badge>
        ),
        meta: { label: "Tipo" },
      },
      {
        accessorKey: "total",
        header: "Total",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Total", className: "tabular-nums text-right" },
      },
      {
        id: "status",
        header: "Estado",
        cell: ({ row }) => {
          const r = row.original
          if (r.transactionType === 3 && r.transactionComplete !== 1) {
            return (
              <div className="flex flex-col gap-0.5">
                <Badge variant="secondary">Crédito pendiente</Badge>
                <span className="text-[10px] tabular-nums text-muted-foreground">
                  Saldo {formatMoney(r.topay, bootstrap)}
                </span>
              </div>
            )
          }
          return r.transactionComplete === 1 ? (
            <Badge variant="default">Pagada</Badge>
          ) : (
            <Badge variant="secondary">Pendiente</Badge>
          )
        },
        meta: { label: "Estado" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-3">
      {error && (
        <p className="text-sm text-destructive">No se pudieron cargar las transacciones.</p>
      )}
      <DataTable
        tableId="contact-transactions"
        data={rows}
        columns={columns}
        getRowId={(r) => r.transactionId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por documento…"
        exportFileName="transacciones-cliente"
        onRowClick={(row) => router.push(`/transactions/${row.transactionId}`)}
        emptyMessage={
          <EmptyState
            icon={Receipt}
            title="Sin transacciones"
            description="Este cliente todavía no tiene ventas registradas."
          />
        }
      />
    </div>
  )
}
