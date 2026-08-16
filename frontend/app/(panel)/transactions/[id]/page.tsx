"use client"

import * as React from "react"
import Link from "next/link"
import { useParams } from "next/navigation"
import { ArrowLeft, Banknote, Loader2, Printer, Receipt } from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Separator } from "@/components/ui/separator"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"
import { usePaymentMethods } from "@/hooks/use-payment-methods"
import {
  useTransactionDetail,
  type TxDetailFull,
  type TxDetailItem,
  type TxRelatedDoc,
  type TxRelatedOrder,
} from "@/hooks/use-reports"
import { api } from "@/lib/api-client"
import { formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"
import { buildTicketDataFromTxDetail } from "@/lib/hardware/printers/build-ticket-data"
import { printTicketInBrowser } from "@/lib/hardware/printers/print-in-browser"
import { MultiInvoicePaymentDialog } from "@/components/domain/transactions/multi-invoice-payment-dialog"
import { TransactionEditDialog } from "@/components/domain/transactions/transaction-edit-dialog"
import { txTypeLabel } from "@/components/domain/transactions/transactions-list"

/**
 * Detalle completo de una transacción de venta — espejo de `/purchase/{id}`
 * (F2, context/39-detalle-transaccion.md). Reemplaza al Dialog embebido
 * `PanelDetailView` que vivía en transactions-list.tsx: acá cabe TODA la
 * info (líneas con descuento/impuesto/comisión por línea, desglose fiscal
 * por tasa, documentos vinculados) sin la limitación de espacio de un modal.
 *
 * Sirve tanto para ventas (tab Transacciones) como cotizaciones (tab
 * Cotizaciones) y cualquier otro tipo resuelto por el backend (recibo,
 * nota de crédito) — el resolver no filtra por `transactionType`, el
 * contenido se adapta solo a lo que el payload trae.
 */
export default function TransactionDetailPage() {
  const params = useParams()
  const id = typeof params.id === "string" ? params.id : (params.id?.[0] ?? "")

  const { data: bootstrap } = useBootstrap()
  const { data: detail, isLoading, error } = useTransactionDetail(id || null)

  if (isLoading) {
    return (
      <div className="flex h-40 items-center justify-center text-muted-foreground">
        <Loader2 className="mr-2 size-5 animate-spin" />
        Cargando transacción…
      </div>
    )
  }

  if (error || !detail) {
    return (
      <div className="flex flex-col gap-4">
        <BackButton />
        <div className="flex h-40 flex-col items-center justify-center gap-2 text-muted-foreground">
          <Receipt className="size-8" />
          <p className="text-sm">Transacción no encontrada o sin acceso.</p>
        </div>
      </div>
    )
  }

  return <TransactionDetailView id={id} detail={detail} bootstrap={bootstrap} />
}

function TransactionDetailView({
  id,
  detail,
  bootstrap,
}: {
  id: string
  detail: TxDetailFull
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const tx = detail.transaction
  const typeStr = String(tx.transactionType)
  const isVoid = !!tx.void
  const isCredit = tx.transactionType === 3
  const isQuote = tx.transactionType === 9
  const canEdit = !isVoid && (tx.transactionType === 0 || tx.transactionType === 3 || isQuote)

  // Gate cliente — espejo de hasPermission('pos.sale.creditPayment') que
  // enforce api/v1/credit-payments.php. No es el boundary de seguridad (eso
  // es el backend), evita mostrar un botón que va a 403.
  const canRegisterPayment = usePermission("pos.sale.creditPayment")
  const canPay = isCredit && (detail.creditPayments?.debt ?? 0) > 0 && canRegisterPayment

  const { data: pmData } = usePaymentMethods()
  const panelPaymentMethods = pmData?.paymentMethods ?? []

  const [payDialogOpen, setPayDialogOpen] = React.useState(false)
  const [editDialogOpen, setEditDialogOpen] = React.useState(false)
  const [receiptPrompt, setReceiptPrompt] = React.useState<{ paymentId: string } | null>(null)
  const [printingReceipt, setPrintingReceipt] = React.useState(false)

  // El panel no tiene bindings de hardware (impresoras de red/USB por
  // register) — reimprimir va directo a printTicketInBrowser (plantilla del
  // docType, diálogo nativo del browser), sin pasar por usePrintWithPicker
  // (que resuelve un binding físico, solo tiene sentido en el POS).
  async function handleReprint() {
    const docType = isQuote ? "quote" : "factura"
    const data = buildTicketDataFromTxDetail(detail, bootstrap?.companyName ?? "", docType, bootstrap)
    try {
      await printTicketInBrowser({ docType, data })
    } catch {
      toast.error("No se pudo imprimir")
    }
  }

  async function handlePrintReceipt(paymentId: string) {
    setPrintingReceipt(true)
    try {
      const paymentDetail = await api.get<TxDetailFull>(`/v1/reports/transactions?id=${paymentId}`)
      const data = buildTicketDataFromTxDetail(paymentDetail, bootstrap?.companyName ?? "", "receipt", bootstrap)
      await printTicketInBrowser({ docType: "receipt", data })
    } catch {
      toast.error("No se pudo imprimir el recibo")
    } finally {
      setPrintingReceipt(false)
      setReceiptPrompt(null)
    }
  }

  const badgeVariant = isVoid
    ? "destructive"
    : tx.transactionType === 0
      ? "default"
      : tx.transactionType === 3
        ? "secondary"
        : "outline"

  const hasRelated =
    (detail.quotesOrigin?.length ?? 0) > 0 ||
    (detail.quotesDerived?.length ?? 0) > 0 ||
    detail.creditNotes.length > 0 ||
    detail.paymentsReceived.length > 0 ||
    (detail.orders?.length ?? 0) > 0 ||
    detail.appointments.length > 0

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackButton />
          <h1 className="text-2xl font-semibold flex flex-wrap items-center gap-2">
            <Badge variant={badgeVariant}>{isVoid ? "Anulada" : txTypeLabel(typeStr)}</Badge>
            {tx.docNo ? tx.docNo : "Detalle de transacción"}
          </h1>
          <p className="text-sm text-muted-foreground">
            {tx.customerName ? `Cliente: ${tx.customerName}` : "Consumidor final"}
            {tx.customerTIN ? ` · ${tx.customerTIN}` : ""}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={handleReprint} className="gap-1.5">
            <Printer className="size-3.5" />
            Reimprimir
          </Button>
          {canPay && (
            <Button size="sm" onClick={() => setPayDialogOpen(true)} className="gap-1.5">
              <Banknote className="size-3.5" />
              Registrar pago
            </Button>
          )}
          {canEdit && (
            <Button variant="outline" size="sm" onClick={() => setEditDialogOpen(true)}>
              Editar
            </Button>
          )}
        </div>
      </header>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <InfoCard title="Datos generales">
          <InfoRow label="Sucursal" value={tx.outletName || "—"} />
          <InfoRow label="Caja" value={tx.registerName || "—"} />
          <InfoRow label="Cajero" value={tx.userName || "—"} />
          <InfoRow label="Fecha y hora" value={tx.transactionDate ? formatDateTime(tx.transactionDate, "d MMM yyyy, HH:mm") : "—"} />
          {(tx.transactionType === 0 || tx.transactionType === 3) && (
            <InfoRow label="Condición" value={tx.condition === "credit" ? "Crédito" : "Contado"} />
          )}
          {isCredit && tx.transactionDueDate && (
            <InfoRow label="Vencimiento" value={formatDateTime(tx.transactionDueDate, "d MMM yyyy")} />
          )}
          {tx.authNo && <InfoRow label="Timbrado" value={tx.authNo} mono />}
          {tx.docNo && <InfoRow label="Factura" value={tx.docNo} mono />}
        </InfoCard>

        <InfoCard title="Totales">
          <InfoRow label="Subtotal" value={formatMoney(tx.subtotal ?? tx.transactionTotal, bootstrap)} mono />
          {tx.transactionDiscount > 0 && (
            <InfoRow label="Descuento" value={`- ${formatMoney(tx.transactionDiscount, bootstrap)}`} mono />
          )}
          {(detail.taxByRate ?? []).map((t, i) => (
            <InfoRow
              key={i}
              label={t.kind === "exempt" ? "Exento" : `IVA ${t.rate}% (base ${formatMoney(t.base, bootstrap)})`}
              value={formatMoney(t.amount, bootstrap)}
              mono
            />
          ))}
          <Separator className="my-1" />
          <InfoRow label="Total" value={formatMoney(tx.netTotal ?? tx.transactionTotal, bootstrap)} mono bold />
        </InfoCard>

        {tx.transactionNote && (
          <InfoCard title="Nota">
            <p className="text-sm text-muted-foreground">{tx.transactionNote}</p>
          </InfoCard>
        )}

        {detail.creditPayments && (
          <InfoCard title="Crédito">
            <InfoRow label="Total" value={formatMoney(detail.creditPayments.total, bootstrap)} mono />
            <InfoRow label="Pagado" value={formatMoney(detail.creditPayments.paid, bootstrap)} mono />
            <InfoRow label="Deuda" value={formatMoney(detail.creditPayments.debt, bootstrap)} mono bold />
          </InfoCard>
        )}
      </div>

      {detail.items.length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Líneas</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Producto</TableHead>
                    <TableHead className="text-right w-20">Cant.</TableHead>
                    <TableHead className="text-right w-28">P. Unit.</TableHead>
                    <TableHead className="text-right w-28">Descuento</TableHead>
                    <TableHead className="w-24">Tasa</TableHead>
                    <TableHead className="text-right w-28">Impuesto</TableHead>
                    <TableHead className="w-32">Usuario</TableHead>
                    <TableHead className="text-right w-24">Comisión</TableHead>
                    <TableHead className="text-right w-28">Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {detail.items.map((item: TxDetailItem) => (
                    <TableRow key={item.itemSoldId}>
                      <TableCell className="font-medium">
                        {item.itemName || "(sin descripción)"}
                        {item.note && (
                          <p className="text-xs font-normal text-muted-foreground">{item.note}</p>
                        )}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{item.itemSoldUnits}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMoney(item.unitPrice ?? 0, bootstrap)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {item.itemSoldDiscount ? (
                          <>
                            {formatMoney(item.itemSoldDiscount, bootstrap)}
                            {item.discountPercent ? (
                              <p className="text-xs text-muted-foreground">{item.discountPercent}%</p>
                            ) : null}
                          </>
                        ) : (
                          "—"
                        )}
                      </TableCell>
                      <TableCell>
                        {item.taxKind === "exempt"
                          ? "Exento"
                          : item.taxRate !== null && item.taxRate !== undefined
                            ? `${item.taxRate}%`
                            : "—"}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMoney(item.taxAmount ?? 0, bootstrap)}
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">{item.userName || "—"}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {item.itemSoldComission ? formatMoney(item.itemSoldComission, bootstrap) : "—"}
                      </TableCell>
                      <TableCell className="text-right tabular-nums font-medium">
                        {formatMoney(item.itemSoldTotal, bootstrap)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>
      )}

      {tx.transactionPaymentType.length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Pagos</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <div className="divide-y divide-border">
              {tx.transactionPaymentType.map((p, i) => (
                <div key={i} className="flex items-center justify-between px-4 py-2.5 text-sm">
                  <span>{p.name || p.type}</span>
                  <span className="tabular-nums font-medium">{formatMoney(p.total, bootstrap)}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {hasRelated && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Documentos relacionados</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4 pt-0">
            {(detail.quotesOrigin?.length ?? 0) > 0 && (
              <RelatedDocSection title="Cotización de origen" docs={detail.quotesOrigin!} bootstrap={bootstrap} />
            )}
            {(detail.quotesDerived?.length ?? 0) > 0 && (
              <RelatedDocSection title="Venta facturada desde esta cotización" docs={detail.quotesDerived!} bootstrap={bootstrap} />
            )}
            {detail.creditNotes.length > 0 && (
              <RelatedDocSection
                title="Notas de crédito"
                docs={detail.creditNotes.map((cn) => ({
                  id: cn.transactionId,
                  type: 6,
                  date: cn.transactionDate,
                  invoiceNo: cn.invoiceNo,
                  invoicePrefix: null,
                  total: cn.transactionTotal,
                }))}
                bootstrap={bootstrap}
              />
            )}
            {detail.paymentsReceived.length > 0 && (
              <RelatedDocSection
                title="Pagos recibidos"
                docs={detail.paymentsReceived.map((pr) => ({
                  id: pr.transactionId,
                  type: 5,
                  date: pr.date,
                  invoiceNo: pr.invoiceNo,
                  invoicePrefix: null,
                  total: pr.amount,
                }))}
                bootstrap={bootstrap}
              />
            )}
            {(detail.orders?.length ?? 0) > 0 && (
              <section>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Órdenes cobradas
                </p>
                {/* Sin ruta de detalle de orden en el frontend todavía — se
                    lista informativo, no navegable (a diferencia de los demás
                    documentos, que sí son otra `transaction`). */}
                <div className="divide-y divide-border rounded-lg border border-border">
                  {detail.orders!.map((o: TxRelatedOrder) => (
                    <div key={o.id} className="flex items-center justify-between px-4 py-2.5 text-sm">
                      <div className="flex flex-col">
                        <span className="font-medium">{o.orderNumber ? `Orden #${o.orderNumber}` : "Orden"}</span>
                        <span className="text-xs text-muted-foreground">
                          {o.date ? formatDateTime(o.date, "d MMM yyyy, HH:mm") : "—"} · {o.status || "—"}
                        </span>
                      </div>
                      <span className="tabular-nums font-medium">{formatMoney(o.total, bootstrap)}</span>
                    </div>
                  ))}
                </div>
              </section>
            )}
            {detail.appointments.length > 0 && (
              <section>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Agendamientos
                </p>
                <div className="divide-y divide-border rounded-lg border border-border">
                  {detail.appointments.map((a, i) => (
                    <div key={i} className="flex items-center justify-between px-4 py-2.5 text-sm">
                      <span className="tabular-nums text-muted-foreground">
                        {formatDateTime(a.transactionDate, "d MMM yyyy, HH:mm")}
                      </span>
                      <span className="tabular-nums font-medium">{formatMoney(a.transactionTotal, bootstrap)}</span>
                    </div>
                  ))}
                </div>
              </section>
            )}
          </CardContent>
        </Card>
      )}

      {detail.items.length === 0 && !hasRelated && tx.transactionPaymentType.length === 0 && (
        <EmptyState
          icon={Receipt}
          title="Sin más información"
          description="Esta transacción no tiene líneas, pagos ni documentos vinculados."
        />
      )}

      {canPay && tx.customerId && (
        <MultiInvoicePaymentDialog
          open={payDialogOpen}
          onOpenChange={setPayDialogOpen}
          contactId={tx.customerId}
          primaryTransactionId={tx.transactionId}
          primaryDebt={detail.creditPayments!.debt}
          contactName={tx.customerName || "Cliente"}
          paymentMethods={panelPaymentMethods}
          config={bootstrap ?? null}
          onSuccess={(result) => {
            setPayDialogOpen(false)
            setReceiptPrompt({ paymentId: result.id })
          }}
        />
      )}

      <AlertDialog open={receiptPrompt !== null} onOpenChange={(open) => !open && setReceiptPrompt(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Pago registrado</AlertDialogTitle>
            <AlertDialogDescription>¿Querés imprimir el recibo del pago?</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cerrar</AlertDialogCancel>
            <AlertDialogAction
              disabled={printingReceipt}
              onClick={(e) => {
                e.preventDefault()
                if (receiptPrompt) handlePrintReceipt(receiptPrompt.paymentId)
              }}
            >
              {printingReceipt ? "Imprimiendo..." : "Imprimir recibo"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {canEdit && (
        <TransactionEditDialog
          transactionId={id}
          detail={detail}
          open={editDialogOpen}
          onOpenChange={setEditDialogOpen}
        />
      )}
    </div>
  )
}

// ── Sub-componentes ──────────────────────────────────────────────────────────

function RelatedDocSection({
  title,
  docs,
  bootstrap,
}: {
  title: string
  docs: TxRelatedDoc[]
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <section>
      <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{title}</p>
      <div className="divide-y divide-border rounded-lg border border-border">
        {docs.map((doc) => (
          <Link
            key={doc.id}
            href={`/transactions/${doc.id}`}
            className="flex items-center justify-between px-4 py-2.5 text-sm hover:bg-muted/40"
          >
            <div className="flex flex-col">
              <span className="font-medium">
                {doc.invoicePrefix || doc.invoiceNo
                  ? `${doc.invoicePrefix ?? ""}${doc.invoiceNo ?? ""}`
                  : txTypeLabel(String(doc.type))}
              </span>
              <span className="text-xs text-muted-foreground">
                {doc.date ? formatDateTime(doc.date, "d MMM yyyy, HH:mm") : "—"}
              </span>
            </div>
            <span className="tabular-nums font-medium">{formatMoney(doc.total, bootstrap)}</span>
          </Link>
        ))}
      </div>
    </section>
  )
}

function BackButton() {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href="/reports/transactions">
        <ArrowLeft className="size-3.5" />
        Volver a transacciones
      </Link>
    </Button>
  )
}

function InfoCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground uppercase tracking-wide">
          {title}
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-1.5">{children}</CardContent>
    </Card>
  )
}

function InfoRow({
  label,
  value,
  mono,
  bold,
}: {
  label: string
  value: string
  mono?: boolean
  bold?: boolean
}) {
  return (
    <div className="flex items-center justify-between gap-2 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className={`${mono ? "tabular-nums font-mono text-xs" : ""} ${bold ? "font-semibold text-base" : ""}`}>
        {value}
      </span>
    </div>
  )
}
