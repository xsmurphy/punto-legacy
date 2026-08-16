"use client"

// ── AccountStatementSection ────────────────────────────────────────────────
//
// Estado de cuenta de UN contacto: facturas a crédito ABIERTAS
// (transactionComplete = false) con su saldo, y qué recibos se le aplicaron
// a cada una (un recibo puede repartirse entre varias facturas).
//
// Generalizado para clientes (contactType=1, `state=income`) Y proveedores
// (contactType=2, `state=outcome`) — antes vivía embebido y hardcodeado a
// cliente dentro de `contact-detail-view.tsx` (tab Financiero). Se extrajo acá
// para reusarlo tal cual también desde el detalle por contacto del reporte
// `/reports/open-invoices` (cuentas por cobrar/pagar) en vez de duplicar la
// tabla — mismo componente, misma fuente de datos
// (`OpenInvoicesService::contactStatement()`), en los dos lugares que
// necesitan "las facturas abiertas de ESTE contacto".
//
// Navegación por fila: la factura ORIGEN (venta o compra a crédito) navega a
// `/transactions/{id}` si es cliente, `/purchase/{id}` si es proveedor — son
// documentos de naturaleza distinta. El recibo de pago (type=5, tanto
// `credit_payment` como `purchase_payment`) es siempre una `transaction`
// genérica y navega a `/transactions/{id}` en los dos casos.
//
// Acción de pago: el botón "Cobrar crédito" / "Pagar a proveedor" abre
// `MultiInvoicePaymentDialog` sin factura pre-seleccionada (el operador elige
// qué saldar desde ahí — una factura puntual, todas, o un monto libre que el
// backend reparte de la más vieja a la más nueva). Gateado por permiso:
// `pos.sale.creditPayment` para clientes (mismo que ya gatea el cobro desde
// `/transactions/[id]`), `finance.manage` para proveedores (un pago a
// proveedor mueve caja hacia afuera — mismo permiso que
// `finance/movements.php`).

import * as React from "react"
import { useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { Wallet } from "lucide-react"

import { useContactStatement, type ContactType } from "@/hooks/use-contacts"
import { usePermission } from "@/hooks/use-permissions"
import { usePaymentMethods } from "@/hooks/use-payment-methods"
import { useBootstrap } from "@/hooks/use-bootstrap"
import type { ContactStatement } from "@/lib/types/contact"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { KpiCard } from "@/components/domain/contacts/kpi-card"
import { MultiInvoicePaymentDialog } from "@/components/domain/transactions/multi-invoice-payment-dialog"

export function AccountStatementSection({
  contactId,
  contactType,
  contactName,
}: {
  contactId: string
  contactType: ContactType
  /** Necesario para el título del diálogo de pago. */
  contactName: string
}) {
  const router = useRouter()
  const { data: bootstrap } = useBootstrap()
  const isCustomer = contactType === 1
  const { data, isLoading } = useContactStatement(contactId, contactType)
  const invoices = data?.invoices ?? []
  const summary = data?.summary

  const [payOpen, setPayOpen] = React.useState(false)
  const canRegisterPayment = usePermission(isCustomer ? "pos.sale.creditPayment" : "finance.manage")
  const { data: pmData } = usePaymentMethods()
  const paymentMethods = pmData?.paymentMethods ?? []

  // La factura ORIGEN (venta/compra a crédito) es un documento distinto según
  // el tipo de contacto; el recibo (type=5) es siempre `/transactions/{id}`.
  const docBase = isCustomer ? "/transactions" : "/purchase"

  const invoiceColumns = React.useMemo<ColumnDef<ContactStatement["invoices"][number]>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return <span className="tabular-nums">{v ? formatDate(v) : "—"}</span>
        },
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "invoiceNo",
        header: "Documento",
        cell: ({ getValue }) => (
          <span className="font-medium tabular-nums">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Documento" },
      },
      {
        accessorKey: "total",
        header: "Total",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatMoney(Number(getValue()) || 0, bootstrap)}</span>
        ),
        meta: { label: "Total", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "paid",
        header: isCustomer ? "Cobrado" : "Pagado",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatMoney(Number(getValue()) || 0, bootstrap)}</span>
        ),
        meta: { label: isCustomer ? "Cobrado" : "Pagado", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "balance",
        header: "Saldo",
        cell: ({ getValue }) => (
          <span className="font-semibold tabular-nums">{formatMoney(Number(getValue()) || 0, bootstrap)}</span>
        ),
        meta: { label: "Saldo", className: "tabular-nums text-right" },
      },
      {
        id: "dueStatus",
        header: "Vencimiento",
        cell: ({ row }) => {
          const inv = row.original
          const due = inv.dueDate ? formatDate(inv.dueDate) : "—"
          if (inv.dueStatus === "paid") {
            return <Badge variant="secondary">Saldada</Badge>
          }
          return (
            <div className="flex flex-col gap-0.5">
              <Badge variant={inv.dueStatus === "expired" ? "destructive" : "outline"}>
                {inv.dueStatus === "expired" ? "Vencida" : "Por vencer"}
              </Badge>
              <span className="text-[10px] tabular-nums text-muted-foreground">{due}</span>
            </div>
          )
        },
        meta: { label: "Vencimiento" },
      },
    ],
    [bootstrap, isCustomer],
  )

  const payments = React.useMemo(
    () =>
      invoices.flatMap((inv) =>
        inv.payments.map((p) => ({
          key: `${p.transactionId}-${inv.saleId}`,
          transactionId: p.transactionId,
          invoiceNo: p.invoiceNo,
          date: p.date,
          amount: p.amount,
          appliedToInvoiceNo: inv.invoiceNo,
        })),
      ),
    [invoices],
  )

  const paymentColumns = React.useMemo<ColumnDef<(typeof payments)[number]>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return <span className="tabular-nums">{v ? formatDate(v) : "—"}</span>
        },
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "invoiceNo",
        header: "Recibo",
        cell: ({ getValue }) => (
          <span className="font-medium tabular-nums">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Recibo" },
      },
      {
        accessorKey: "appliedToInvoiceNo",
        header: "Aplicado a factura",
        cell: ({ getValue }) => <span className="tabular-nums">{(getValue() as string) || "—"}</span>,
        meta: { label: "Aplicado a factura" },
      },
      {
        accessorKey: "amount",
        header: "Monto",
        cell: ({ getValue }) => (
          <span className="font-semibold tabular-nums">{formatMoney(Number(getValue()) || 0, bootstrap)}</span>
        ),
        meta: { label: "Monto", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

  const payLabel = isCustomer ? "Cobrar crédito" : "Pagar a proveedor"
  const payAction = canRegisterPayment && (summary?.totalDebt ?? 0) > 0 && (
    <Button size="sm" onClick={() => setPayOpen(true)}>
      {payLabel}
    </Button>
  )

  if (!isLoading && invoices.length === 0) {
    return (
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">Estado de cuenta</CardTitle>
        </CardHeader>
        <CardContent>
          <EmptyState
            icon={Wallet}
            title="Sin cuentas pendientes"
            description={
              isCustomer
                ? "Este cliente no tiene facturas a crédito abiertas."
                : "Este proveedor no tiene facturas a crédito abiertas."
            }
          />
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between pb-2">
        <CardTitle className="text-base font-semibold tracking-tight">Estado de cuenta</CardTitle>
        {payAction}
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid grid-cols-3 gap-2">
          <KpiCard label="Deuda total" value={isLoading ? null : formatMoney(summary?.totalDebt, bootstrap)} />
          <KpiCard
            label={isCustomer ? "Facturado a crédito" : "Comprado a crédito"}
            value={isLoading ? null : formatMoney(summary?.totalCredited, bootstrap)}
          />
          <KpiCard label={isCustomer ? "Cobrado" : "Pagado"} value={isLoading ? null : formatMoney(summary?.totalPaid, bootstrap)} />
        </div>

        <div className="flex flex-col gap-2">
          <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Facturas a crédito
          </p>
          <DataTable
            tableId={`contact-statement-invoices-${contactType}`}
            data={invoices}
            columns={invoiceColumns}
            getRowId={(r) => r.saleId}
            isLoading={isLoading}
            searchPlaceholder="Buscar por documento…"
            exportFileName="estado-de-cuenta"
            onRowClick={(row) => router.push(`${docBase}/${row.saleId}`)}
            emptyMessage={
              <EmptyState icon={Wallet} title="Sin facturas" description="Sin facturas a crédito abiertas." />
            }
          />
        </div>

        {payments.length > 0 && (
          <div className="flex flex-col gap-2">
            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              {isCustomer ? "Cobros aplicados" : "Pagos aplicados"}
            </p>
            <DataTable
              tableId={`contact-statement-payments-${contactType}`}
              data={payments}
              columns={paymentColumns}
              getRowId={(r) => r.key}
              isLoading={isLoading}
              searchPlaceholder="Buscar por recibo…"
              exportFileName={isCustomer ? "cobros-aplicados" : "pagos-aplicados"}
              onRowClick={(row) => router.push(`/transactions/${row.transactionId}`)}
            />
          </div>
        )}
      </CardContent>

      {canRegisterPayment && (
        <MultiInvoicePaymentDialog
          open={payOpen}
          onOpenChange={setPayOpen}
          contactId={contactId}
          contactType={contactType}
          contactName={contactName}
          paymentMethods={paymentMethods}
          config={bootstrap ?? null}
          onSuccess={() => setPayOpen(false)}
        />
      )}
    </Card>
  )
}
