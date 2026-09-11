"use client"

/**
 * Listado de Cuentas por cobrar / por pagar — un contacto por fila con su
 * deuda agregada, y el detalle de sus facturas en un Dialog.
 *
 * Es EL MISMO listado que vivía en `app/(panel)/reports/open-invoices/page.tsx`
 * antes de que la página ganara pestañas (2026-09-11): se movió tal cual y lo
 * único que cambió es que el lado (`state`) entra por prop en vez de leerse de
 * la URL acá adentro. La página es ahora la que decide qué pestaña está activa,
 * y dos componentes leyendo el mismo query param se pisan — mismo criterio que
 * `SalesDashboardTab` con el rango.
 *
 * Detalle por contacto: Dialog (no panel lateral — contenido denso, ver
 * context/14 §2.2) reusando `AccountStatementSection`, el mismo componente que
 * el tab "Financiero" de la ficha de contacto: la tabla de facturas no se
 * duplica.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, AlertTriangle, Clock, Wallet } from "lucide-react"

import { formatPhone } from "@/lib/phone"
import { Badge } from "@/components/ui/badge"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog"
import { DataTable } from "@/components/data-table/data-table"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type OpenInvoiceContactRow,
  type OpenInvoicesReportResponse,
} from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { AccountStatementSection } from "@/components/domain/contacts/account-statement-section"

export type OpenInvoicesState = "income" | "outcome"

export function OpenInvoicesListTab({ state }: { state: OpenInvoicesState }) {
  const { data: bootstrap } = useBootstrap()

  const opts = React.useMemo(() => ({ params: { state } }), [state])
  const { data, isLoading, error } = useReport<OpenInvoicesReportResponse>("open_invoices", opts)

  const rows = data?.rows ?? []
  const kpi = data?.kpi

  const isOutcome = state === "outcome"
  const subjectPlural = isOutcome ? "proveedores" : "clientes"
  const subjectSingular = isOutcome ? "proveedor" : "cliente"

  const columns = React.useMemo<ColumnDef<OpenInvoiceContactRow>[]>(
    () => [
      {
        accessorKey: "name",
        header: subjectSingular[0].toUpperCase() + subjectSingular.slice(1),
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span className="font-medium truncate">
                {r.name || "(sin nombre)"}
              </span>
              {r.tin && r.tin !== "-" && (
                <span className="text-[10px] text-muted-foreground tabular-nums">
                  {r.tin}
                </span>
              )}
            </div>
          )
        },
        meta: { label: subjectSingular },
      },
      {
        accessorKey: "phone",
        header: "Contacto",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col text-xs text-muted-foreground tabular-nums">
              {r.phone && <span>{formatPhone(r.phone)}</span>}
              {r.email && <span className="truncate">{r.email}</span>}
              {!r.phone && !r.email && "—"}
            </div>
          )
        },
        meta: { label: "Contacto" },
      },
      {
        accessorKey: "invoices",
        header: "Facturas",
        cell: ({ row }) => (
          <span className="tabular-nums">{row.original.invoices.length}</span>
        ),
        meta: { label: "Facturas", className: "tabular-nums text-right" },
      },
      {
        id: "dueStatus",
        header: "Estado",
        // Semántica de color del design system (context/14): destructive =
        // vencida (alcanza con UNA factura vencida para que el contacto
        // cuente como tal), outline = todavía por vencer. Sin hex colors.
        cell: ({ row }) => {
          const hasExpired = row.original.invoices.some((i) => i.dueStatus === "expired")
          return (
            <Badge variant={hasExpired ? "destructive" : "outline"}>
              {hasExpired ? "Vencida" : "Por vencer"}
            </Badge>
          )
        },
        meta: { label: "Estado" },
      },
      {
        accessorKey: "totalSales",
        header: "Total emitido",
        cell: ({ getValue }) => (
          <span className="tabular-nums">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Total emitido", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "totalPaid",
        header: "Cobrado",
        cell: ({ getValue }) => {
          const v = Number(getValue()) || 0
          if (v <= 0) {
            return <span className="text-muted-foreground">—</span>
          }
          return (
            <span className="tabular-nums text-emerald-600">
              {formatMoney(v, bootstrap)}
            </span>
          )
        },
        meta: { label: isOutcome ? "Pagado" : "Cobrado", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "totalDebt",
        header: "Saldo",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-semibold">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Saldo", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap, isOutcome, subjectSingular],
  )

  const [selectedContact, setSelectedContact] = React.useState<{ id: string; name: string } | null>(null)

  return (
    <div className="flex flex-col gap-6">
      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {!isLoading && kpi && rows.length > 0 && (
        <StatsRow>
          <StatTile
            icon={<Wallet className="size-3.5 text-muted-foreground" />}
            label={isOutcome ? "Total a pagar" : "Total a cobrar"}
            value={formatMoney(kpi.totalDebt, bootstrap)}
            emphasis
          />
          <StatTile
            label={subjectPlural}
            value={kpi.accounts.toString()}
          />
          <StatTile
            icon={<AlertTriangle className="size-3.5 text-destructive" />}
            label="Vencidas"
            value={kpi.expired.toString()}
            tone="negative"
          />
          <StatTile
            icon={<Clock className="size-3.5 text-amber-500" />}
            label="Por vencer (7 días)"
            value={kpi.toExpire.toString()}
          />
        </StatsRow>
      )}

      <DataTable
        tableId={`report-open-invoices-${state}`}
        data={rows}
        columns={columns}
        getRowId={(r) => r.contactId}
        isLoading={isLoading}
        searchPlaceholder={`Buscar por ${subjectSingular}, RUC, teléfono…`}
        exportFileName={
          isOutcome ? "cuentas_por_pagar" : "cuentas_por_cobrar"
        }
        onRowClick={(row) => setSelectedContact({ id: row.contactId, name: row.name || "(sin nombre)" })}
        emptyMessage={
          <EmptyState
            icon={Wallet}
            title={isOutcome ? "Sin cuentas pendientes de pago" : "Sin cuentas pendientes de cobro"}
            description="Cuando se registre una venta o compra a crédito sin saldar, aparece acá."
          />
        }
      />

      <Dialog open={selectedContact !== null} onOpenChange={(o) => !o && setSelectedContact(null)}>
        <DialogContent className="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{selectedContact?.name}</DialogTitle>
            <DialogDescription>
              {isOutcome ? "Facturas a crédito pendientes de pago." : "Facturas a crédito pendientes de cobro."}
            </DialogDescription>
          </DialogHeader>
          {selectedContact && (
            <AccountStatementSection
              contactId={selectedContact.id}
              contactType={isOutcome ? 2 : 1}
              contactName={selectedContact.name}
            />
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
