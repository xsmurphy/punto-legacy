"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import { ArrowLeft, CheckCircle2, Loader2, Pencil, Printer, Receipt, Wallet, XCircle } from "lucide-react"
import { toast } from "sonner"

import { Alert, AlertDescription } from "@/components/ui/alert"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Skeleton } from "@/components/ui/skeleton"
import { Textarea } from "@/components/ui/textarea"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  SupplierDocumentFields,
  type SupplierDocumentValue,
} from "@/components/domain/purchases/supplier-document-fields"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePaymentMethods } from "@/hooks/use-payment-methods"
import { usePermission } from "@/hooks/use-permissions"
import {
  PAYMENT_ORDER_STATUS_META,
  useApprovePaymentOrder,
  useCancelPaymentOrder,
  useExecutePaymentOrder,
  usePaymentOrder,
} from "@/hooks/use-payment-orders"
import { formatMoney } from "@/lib/format"
import { formatDate, formatDateTime } from "@/lib/format-date"
import { printPaymentOrderSheet } from "@/lib/hardware/printers/print-payment-order"

/**
 * Detalle de una ORDEN DE PAGO — es acá donde se aprueba y se ejecuta.
 *
 * Los gates de permiso de esta pantalla son UX: esconden lo que el usuario no
 * va a poder hacer para no ofrecerle un 403. El boundary real es el servidor
 * (`api/v1/payment-orders.php`), que chequea las mismas claves y además el
 * ajuste de segundo aprobador — que desde el cliente NO se puede anticipar
 * (depende de quién creó la orden y de la config del comercio), así que si
 * salta, llega como el error de la mutación y se muestra tal cual.
 */

function attribution(name: string, id: string | null): string {
  if (name.trim() !== "") return name
  return id ?? "—"
}

export default function PaymentOrderDetailPage() {
  const params = useParams<{ id: string }>()
  const router = useRouter()
  const id = params.id

  const { data, isLoading } = usePaymentOrder(id)
  const { data: bootstrap } = useBootstrap()
  const { data: pmData } = usePaymentMethods()

  const canCreate = usePermission("purchases.paymentorder.create")
  const canApprove = usePermission("purchases.paymentorder.approve")
  const canFinance = usePermission("finance.manage")

  const approve = useApprovePaymentOrder()
  const execute = useExecutePaymentOrder()
  const cancel = useCancelPaymentOrder()

  const [approveOpen, setApproveOpen] = React.useState(false)
  const [executeOpen, setExecuteOpen] = React.useState(false)
  const [cancelOpen, setCancelOpen] = React.useState(false)
  const [cancelReason, setCancelReason] = React.useState("")
  const [pmKey, setPmKey] = React.useState("")
  const [payNote, setPayNote] = React.useState("")
  const [identifier, setIdentifier] = React.useState("")
  const [supplierDoc, setSupplierDoc] = React.useState<SupplierDocumentValue>({
    prefix: "",
    no: "",
    authNo: "",
    authNoDueDate: "",
    docDate: "",
  })

  const paymentMethods = React.useMemo(() => pmData?.paymentMethods ?? [], [pmData])
  const selectedMethod = paymentMethods.find((m) => m.id === pmKey) ?? null

  if (isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-4 w-48" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  if (!data) {
    return (
      <div className="flex flex-col gap-4">
        <p className="text-muted-foreground">Orden de pago no encontrada.</p>
        <Button variant="ghost" className="w-fit" onClick={() => router.push("/ordenes-pago")}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Volver
        </Button>
      </div>
    )
  }

  const { order, lines } = data
  const statusMeta = PAYMENT_ORDER_STATUS_META[order.status]
  const isDraft = order.status === "draft"
  const isApproved = order.status === "approved"
  const isPaid = order.status === "paid"

  // Una línea cuyo saldo VIVO quedó por debajo de lo que la orden imputa ya no
  // se puede pagar: el servidor la va a rechazar al aprobar o al ejecutar. Se
  // avisa acá para que quien tiene que aprobar entienda por qué, en vez de
  // toparse con un error después de apretar el botón.
  const staleLines = lines.filter((l) => l.voided || l.amount > l.debt + 0.001)
  const canApproveNow = isDraft && canApprove && staleLines.length === 0
  const canExecuteNow = isApproved && canApprove && canFinance && staleLines.length === 0
  const canCancelNow = (isDraft && canCreate) || (isApproved && canApprove)

  function handlePrint() {
    printPaymentOrderSheet({
      companyName: bootstrap?.companyName ?? "",
      docNumber: order.docNumber,
      statusLabel: statusMeta?.label ?? order.status,
      supplierName: order.supplierName,
      outletName: order.outletName,
      paymentDate: order.paymentDate ? formatDate(order.paymentDate) : null,
      createdAt: `${formatDateTime(order.createdAt)} — ${attribution(order.createdByName, order.createdBy)}`,
      approvedAt: order.approvedAt
        ? `${formatDateTime(order.approvedAt)} — ${attribution(order.approvedByName, order.approvedBy)}`
        : null,
      paidAt: order.paidAt
        ? `${formatDateTime(order.paidAt)} — ${attribution(order.paidByName, order.paidBy)}`
        : null,
      printedAt: formatDateTime(new Date().toISOString()),
      notes: order.notes,
      total: formatMoney(order.total, bootstrap),
      lines: lines.map((l) => ({
        invoiceNo: l.invoiceNo,
        date: l.date ? formatDate(l.date) : "—",
        dueDate: l.dueDate ? formatDate(l.dueDate) : "—",
        total: formatMoney(l.total, bootstrap),
        debt: formatMoney(l.debt, bootstrap),
        amount: formatMoney(l.amount, bootstrap),
      })),
    })
  }

  async function handleApprove() {
    try {
      await approve.mutateAsync({ id })
      setApproveOpen(false)
      toast.success("Orden aprobada")
    } catch (err) {
      toast.error("No se pudo aprobar la orden", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  async function handleExecute() {
    if (!selectedMethod) {
      toast.error("Elegí el medio de pago")
      return
    }
    if (selectedMethod.requiresIdentifier && identifier.trim() === "") {
      toast.error(`${selectedMethod.identifierLabel || "Identificador"} requerido`)
      return
    }
    try {
      const result = await execute.mutateAsync({
        id,
        paymentMethodKey: selectedMethod.id,
        note: payNote.trim() || null,
        identifier: identifier.trim() || null,
        supplierDoc: {
          docPrefix: supplierDoc.prefix || null,
          docNo: supplierDoc.no || null,
          docDate: supplierDoc.docDate || null,
          authNo: supplierDoc.authNo || null,
          authNoDueDate: supplierDoc.authNo.trim() !== "" ? supplierDoc.authNoDueDate || null : null,
        },
      })
      setExecuteOpen(false)
      toast.success("Pago registrado", {
        description: `Recibo por ${formatMoney(result.amount, bootstrap)}`,
      })
    } catch (err) {
      toast.error("No se pudo ejecutar la orden", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  async function handleCancel() {
    if (cancelReason.trim() === "") {
      toast.error("Cancelar una orden de pago exige un motivo")
      return
    }
    try {
      await cancel.mutateAsync({ id, reason: cancelReason.trim() })
      setCancelOpen(false)
      setCancelReason("")
      toast.success("Orden cancelada")
    } catch (err) {
      toast.error("No se pudo cancelar la orden", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-1">
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="icon" onClick={() => router.push("/ordenes-pago")}>
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <h1 className="text-2xl font-semibold">
              Orden de pago{order.docNumber !== null ? ` N.º ${order.docNumber}` : ""}
            </h1>
            <Badge variant={statusMeta?.variant ?? "outline"}>
              {statusMeta?.label ?? order.status}
            </Badge>
          </div>
          <div className="space-y-0.5 pl-10 text-sm text-muted-foreground">
            <p>
              Proveedor:{" "}
              <span className="font-medium text-foreground">{order.supplierName || "—"}</span>
            </p>
            <p>
              Sucursal: <span className="font-medium text-foreground">{order.outletName || "—"}</span>
            </p>
            <p>
              Total:{" "}
              <span className="font-medium text-foreground tabular-nums">
                {formatMoney(order.total, bootstrap)}
              </span>
            </p>
            {order.paymentDate ? <p>Pagar el {formatDate(order.paymentDate)}</p> : null}
            <p>
              Creada el {formatDateTime(order.createdAt)} por{" "}
              {attribution(order.createdByName, order.createdBy)}
            </p>
            {order.approvedAt ? (
              <p>
                Aprobada el {formatDateTime(order.approvedAt)} por{" "}
                {attribution(order.approvedByName, order.approvedBy)}
              </p>
            ) : null}
            {order.paidAt ? (
              <p>
                Ejecutada el {formatDateTime(order.paidAt)} por{" "}
                {attribution(order.paidByName, order.paidBy)}
              </p>
            ) : null}
            {order.notes ? <p>Nota: {order.notes}</p> : null}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 pl-10 sm:pl-0">
          <Button variant="outline" size="sm" onClick={handlePrint}>
            <Printer className="mr-2 h-4 w-4" />
            Imprimir
          </Button>

          {isDraft && canCreate ? (
            <Button
              variant="outline"
              size="sm"
              onClick={() => router.push(`/ordenes-pago/${id}/editar`)}
            >
              <Pencil className="mr-2 h-4 w-4" />
              Editar
            </Button>
          ) : null}

          {isDraft && canApprove ? (
            <Button size="sm" onClick={() => setApproveOpen(true)} disabled={!canApproveNow}>
              <CheckCircle2 className="mr-2 h-4 w-4" />
              Aprobar
            </Button>
          ) : null}

          {isApproved && canApprove && canFinance ? (
            <Button size="sm" onClick={() => setExecuteOpen(true)} disabled={!canExecuteNow}>
              <Wallet className="mr-2 h-4 w-4" />
              Ejecutar pago
            </Button>
          ) : null}

          {canCancelNow ? (
            <Button variant="outline" size="sm" onClick={() => setCancelOpen(true)}>
              <XCircle className="mr-2 h-4 w-4" />
              Cancelar orden
            </Button>
          ) : null}

          {isPaid && order.paymentTransactionId ? (
            <Button
              variant="outline"
              size="sm"
              onClick={() => router.push(`/transactions/${order.paymentTransactionId}`)}
            >
              <Receipt className="mr-2 h-4 w-4" />
              Ver recibo
            </Button>
          ) : null}
        </div>
      </header>

      {order.status === "cancelled" ? (
        <Alert variant="destructive">
          <AlertDescription>
            Cancelada el {order.cancelledAt ? formatDateTime(order.cancelledAt) : "—"} por{" "}
            {attribution(order.cancelledByName, order.cancelledBy)}
            {order.cancelReason ? `. Motivo: ${order.cancelReason}` : ""}
          </AlertDescription>
        </Alert>
      ) : null}

      {staleLines.length > 0 && !isPaid && order.status !== "cancelled" ? (
        <Alert variant="destructive">
          <AlertDescription>
            El saldo de {staleLines.length === 1 ? "una factura" : `${staleLines.length} facturas`}{" "}
            cambió desde que se armó la orden (se pagó por otro lado, entró una nota de crédito o se
            anuló la compra). Esta orden no se puede aprobar ni ejecutar así: cancelala y armá una
            nueva con los montos de hoy.
          </AlertDescription>
        </Alert>
      ) : null}

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Comprobante</TableHead>
              <TableHead>Fecha</TableHead>
              <TableHead>Vence</TableHead>
              <TableHead className="text-right">Total</TableHead>
              <TableHead className="text-right">Saldo hoy</TableHead>
              <TableHead className="text-right">A pagar</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {lines.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                  Sin facturas
                </TableCell>
              </TableRow>
            ) : null}
            {lines.map((l) => (
              <TableRow key={l.lineId}>
                <TableCell className="font-medium">
                  {l.invoiceNo || "—"}
                  {l.voided ? (
                    <span className="block text-sm text-muted-foreground">Compra anulada</span>
                  ) : null}
                </TableCell>
                <TableCell>{l.date ? formatDate(l.date) : "—"}</TableCell>
                <TableCell>{l.dueDate ? formatDate(l.dueDate) : "—"}</TableCell>
                <TableCell className="text-right tabular-nums">
                  {formatMoney(l.total, bootstrap)}
                </TableCell>
                <TableCell className="text-right tabular-nums">
                  {formatMoney(l.debt, bootstrap)}
                </TableCell>
                <TableCell className="text-right font-medium tabular-nums">
                  {formatMoney(l.amount, bootstrap)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      {/* ── Aprobar ─────────────────────────────────────────────────────── */}
      <Dialog open={approveOpen} onOpenChange={setApproveOpen}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Aprobar la orden de pago</DialogTitle>
            <DialogDescription>
              Aprobar autoriza el desembolso de {formatMoney(order.total, bootstrap)} a{" "}
              {order.supplierName || "el proveedor"}. Todavía no mueve plata: el pago se ejecuta en
              un segundo paso.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setApproveOpen(false)}>
              Volver
            </Button>
            <Button onClick={handleApprove} disabled={approve.isPending}>
              {approve.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
              Aprobar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Ejecutar ────────────────────────────────────────────────────── */}
      <Dialog open={executeOpen} onOpenChange={setExecuteOpen}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Ejecutar el pago</DialogTitle>
            <DialogDescription>
              Se emite UN recibo por {formatMoney(order.total, bootstrap)} imputado a las{" "}
              {lines.length} factura{lines.length === 1 ? "" : "s"} de la orden. El medio de pago se
              elige recién ahora: la orden autoriza el monto, no el instrumento.
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
              <Label htmlFor="po-pm">Medio de pago</Label>
              <Select value={pmKey} onValueChange={setPmKey}>
                <SelectTrigger id="po-pm">
                  <SelectValue placeholder="Elegí el medio de pago" />
                </SelectTrigger>
                <SelectContent>
                  {paymentMethods.map((m) => (
                    <SelectItem key={m.id} value={m.id}>
                      {m.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {selectedMethod?.requiresIdentifier ? (
              <div className="flex flex-col gap-2">
                <Label htmlFor="po-identifier">
                  {selectedMethod.identifierLabel || "Identificador"}
                </Label>
                <Input
                  id="po-identifier"
                  value={identifier}
                  onChange={(e) => setIdentifier(e.target.value)}
                  placeholder={selectedMethod.identifierPlaceholder || ""}
                />
              </div>
            ) : null}

            <SupplierDocumentFields
              value={supplierDoc}
              onChange={(patch) => setSupplierDoc((prev) => ({ ...prev, ...patch }))}
              title="Comprobante del proveedor"
              showDocDate
            />

            <div className="flex flex-col gap-2">
              <Label htmlFor="po-paynote">Nota del pago</Label>
              <Textarea
                id="po-paynote"
                value={payNote}
                onChange={(e) => setPayNote(e.target.value)}
                placeholder="Opcional"
                rows={2}
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setExecuteOpen(false)}>
              Volver
            </Button>
            <Button onClick={handleExecute} disabled={execute.isPending || !selectedMethod}>
              {execute.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
              Pagar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Cancelar ────────────────────────────────────────────────────── */}
      <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Cancelar la orden de pago</DialogTitle>
            <DialogDescription>
              Las facturas vuelven a quedar disponibles para otra orden. El motivo queda registrado
              con tu nombre.
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Label htmlFor="po-cancel-reason">Motivo</Label>
            <Textarea
              id="po-cancel-reason"
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
              placeholder="Por qué se cancela"
              rows={3}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancelOpen(false)}>
              Volver
            </Button>
            <Button
              variant="destructive"
              onClick={handleCancel}
              disabled={cancel.isPending || cancelReason.trim() === ""}
            >
              {cancel.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
              Cancelar orden
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
