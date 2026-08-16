"use client"

import * as React from "react"
import { toast } from "sonner"
import {
  useCreateCreditPayment,
  useCreateDistributedPayment,
  type CreditPaymentAllocation,
} from "@/hooks/use-credit-payment"
import { useReport, type OpenInvoicesReportResponse } from "@/hooks/use-reports"
import type { ContactType } from "@/hooks/use-contacts"
import { formatAmount, formatMoney } from "@/lib/format-money"
import { formatDateTime } from "@/lib/format-date"
import { MoneyInput } from "@/components/ui/money-input"
import type { PaymentMethodConfig, PosConfig } from "@/lib/types/pos-bootstrap"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Loader2 } from "lucide-react"
import { PaymentIdentifierDialog } from "@/components/register/payment-identifier-dialog"

interface PaymentResult {
  id: string
  encId: string
  debtRemaining: number
  allocations?: Array<{ parentTransactionId: string; amount: number; parentComplete: boolean; debtRemaining: number }>
}

interface MultiInvoicePaymentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Contacto dueño de todas las facturas — el backend exige que sea el mismo en todo el recibo. */
  contactId: string
  contactName: string
  /** 1=cliente (default, cobro) | 2=proveedor (pago). */
  contactType?: ContactType
  /** Factura desde la que se abrió el diálogo — llega precargada con su deuda
   *  completa en la pestaña "Por factura". Sin ella (ej. desde el detalle por
   *  contacto del reporte de cuentas por cobrar/pagar) el operador arranca
   *  sin nada seleccionado. */
  primaryTransactionId?: string
  primaryDebt?: number
  paymentMethods: PaymentMethodConfig[]
  config: PosConfig | null
  onSuccess?: (result: PaymentResult) => void
}

/**
 * Cobro/pago de crédito — diálogo único para clientes (cuentas por cobrar) y
 * proveedores (cuentas por pagar), con soporte multi-factura (mig 123 /
 * `CreditPaymentService`, generalizado 2026-08 para pagos a proveedor —
 * `kind='purchase_payment'`, mismo mecanismo que `credit_payment`).
 *
 * Tres modos, en dos pestañas:
 *   - "Por factura": el operador tipea cuánto imputar a cada factura — cubre
 *     tanto "pagar una puntual" como "pagar todas" (botón de atajo que
 *     precarga cada monto = su deuda). Los montos los decide el operador,
 *     factura por factura.
 *   - "Monto libre": el operador entrega un monto total y el BACKEND decide
 *     cómo se reparte entre las facturas abiertas (la más vieja primero,
 *     saldando completas hasta donde alcance) — `CreditPaymentService::
 *     createDistributed()`. La tabla de acá abajo es una vista previa
 *     calculada en el cliente con los mismos datos ya cargados, NO lo que se
 *     persiste — el server recalcula con lock antes de confirmar.
 *
 * A diferencia de `CreditPaymentDialog` (components/register/), que el POS
 * sigue usando para cobrar UNA factura de cliente con el visor táctil, este
 * diálogo lista TODAS las facturas a crédito pendientes del contacto.
 *
 * Reusa `GET /v1/reports/open_invoices?state=income|outcome&contactId=`
 * (mismo reporte de Cuentas por Cobrar/Pagar, filtrado a un contacto — ver
 * OpenInvoicesService::general()) en vez de crear un endpoint nuevo.
 */
export function MultiInvoicePaymentDialog({
  open,
  onOpenChange,
  contactId,
  contactName,
  contactType = 1,
  primaryTransactionId,
  primaryDebt,
  paymentMethods,
  config,
  onSuccess,
}: MultiInvoicePaymentDialogProps) {
  const isCustomer = contactType === 1
  const byInvoiceMutation = useCreateCreditPayment()
  const distributedMutation = useCreateDistributedPayment()
  const isPending = byInvoiceMutation.isPending || distributedMutation.isPending

  const [tab, setTab] = React.useState<"byInvoice" | "free">("byInvoice")
  const defaultMethod = paymentMethods.find((m) => m.isDefault) ?? paymentMethods[0]
  const [pmKey, setPmKey] = React.useState<string>(defaultMethod?.id ?? "")
  const [note, setNote] = React.useState("")
  const [identifierOpen, setIdentifierOpen] = React.useState(false)
  // Monto a imputar por factura (pestaña "Por factura"), keyed por saleId.
  const [amounts, setAmounts] = React.useState<Record<string, number>>({})
  const [freeAmount, setFreeAmount] = React.useState<number>(0)

  const { data, isLoading } = useReport<OpenInvoicesReportResponse>("open_invoices", {
    params: { state: isCustomer ? "income" : "outcome", contactId },
    enabled: open && !!contactId,
  })

  // El reporte agrupa por contacto — al filtrar por contactId queda un único
  // row (o ninguno si ya no tiene más facturas abiertas).
  const invoices = data?.rows?.[0]?.invoices ?? []

  React.useEffect(() => {
    if (!open) return
    setTab("byInvoice")
    setPmKey(defaultMethod?.id ?? "")
    setNote("")
    setFreeAmount(0)
    // Precarga: la factura de origen (si vino) con su deuda completa, el resto en 0.
    setAmounts((prev) => {
      const next: Record<string, number> = primaryTransactionId
        ? { [primaryTransactionId]: primaryDebt ?? 0 }
        : {}
      for (const inv of invoices) {
        if (inv.saleId !== primaryTransactionId && prev[inv.saleId] === undefined) {
          next[inv.saleId] = 0
        }
      }
      return next
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, primaryTransactionId, primaryDebt, defaultMethod?.id, invoices.length])

  const selectedMethod = paymentMethods.find((m) => m.id === pmKey)
  const totalDebt = invoices.reduce((sum, inv) => sum + inv.topay, 0)

  // ── Pestaña "Por factura" ────────────────────────────────────────────────
  const rowErrors: Record<string, string> = {}
  let byInvoiceTotal = 0
  for (const inv of invoices) {
    const amt = amounts[inv.saleId] ?? 0
    byInvoiceTotal += amt
    if (amt > inv.topay + 0.001) {
      rowErrors[inv.saleId] = `Supera la deuda (${formatAmount(inv.topay, config)})`
    }
  }
  const hasRowErrors = Object.keys(rowErrors).length > 0
  const byInvoiceValid = byInvoiceTotal > 0.001 && !hasRowErrors

  function setAmount(saleId: string, value: number | null) {
    setAmounts((prev) => ({ ...prev, [saleId]: value ?? 0 }))
  }

  function payAll() {
    const next: Record<string, number> = {}
    for (const inv of invoices) next[inv.saleId] = inv.topay
    setAmounts(next)
  }

  function buildAllocations(): CreditPaymentAllocation[] {
    return invoices
      .map((inv) => ({ parentTransactionId: inv.saleId, amount: amounts[inv.saleId] ?? 0 }))
      .filter((a) => a.amount > 0)
  }

  // ── Pestaña "Monto libre" ────────────────────────────────────────────────
  // Vista previa cliente-side, SOLO para mostrar cómo quedaría — el server
  // recalcula con lock al confirmar (ver docblock del componente).
  const freeAmountValid = freeAmount > 0.001 && freeAmount <= totalDebt + 0.001
  const freePreview = React.useMemo(() => {
    const sorted = [...invoices].sort((a, b) => {
      if (!a.dueDate) return 1
      if (!b.dueDate) return -1
      return a.dueDate.localeCompare(b.dueDate)
    })
    let remaining = freeAmount
    return sorted.map((inv) => {
      const applied = Math.max(0, Math.min(remaining, inv.topay))
      remaining -= applied
      return { ...inv, applied }
    })
  }, [invoices, freeAmount])

  // ── Submit ────────────────────────────────────────────────────────────────
  function runPayment(identifier?: string) {
    if (!selectedMethod) return
    if (tab === "byInvoice") {
      const allocations = buildAllocations()
      if (!byInvoiceValid || allocations.length === 0) return
      byInvoiceMutation.mutate(
        { allocations, paymentMethodKey: selectedMethod.id, note: note.trim() || undefined, identifier, contactType },
        {
          onSuccess: (result) => {
            const list = result.allocations ?? []
            const settledCount = list.filter((a) => a.parentComplete).length
            const verb = isCustomer ? "cobradas" : "pagadas"
            const msg =
              list.length > 1
                ? settledCount === list.length
                  ? `Recibo registrado — ${list.length} facturas ${verb}`
                  : `Recibo registrado — ${settledCount} de ${list.length} facturas ${verb}`
                : result.debtRemaining > 0
                  ? `Pago registrado — saldo restante: ${formatAmount(result.debtRemaining, config)}`
                  : "Pago registrado — factura saldada"
            toast.success(msg)
            onOpenChange(false)
            onSuccess?.(result)
          },
          onError: (err) => toast.error(err instanceof Error ? err.message : "Error al registrar pago"),
        },
      )
    } else {
      if (!freeAmountValid) return
      distributedMutation.mutate(
        { contactId, contactType, amount: freeAmount, paymentMethodKey: selectedMethod.id, note: note.trim() || undefined, identifier },
        {
          onSuccess: (result) => {
            const list = result.allocations ?? []
            const settledCount = list.filter((a) => a.parentComplete).length
            toast.success(
              settledCount > 0
                ? `Recibo registrado — ${settledCount} de ${list.length} facturas saldadas`
                : "Recibo registrado",
            )
            onOpenChange(false)
            onSuccess?.(result)
          },
          onError: (err) => toast.error(err instanceof Error ? err.message : "Error al registrar pago"),
        },
      )
    }
  }

  const currentValid = tab === "byInvoice" ? byInvoiceValid : freeAmountValid
  const currentTotal = tab === "byInvoice" ? byInvoiceTotal : freeAmount

  function handleConfirm() {
    if (!currentValid || !selectedMethod) return
    if (selectedMethod.requiresIdentifier) {
      setIdentifierOpen(true)
      return
    }
    runPayment()
  }

  const title = isCustomer ? "Cobrar crédito" : "Pagar a proveedor"
  const subjectLabel = isCustomer ? "Cliente" : "Proveedor"

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>
            {subjectLabel}: {contactName} — un recibo puede repartirse entre varias facturas pendientes.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 py-2">
          {isLoading ? (
            <div className="flex h-24 items-center justify-center text-sm text-muted-foreground">
              <Loader2 className="mr-2 size-4 animate-spin" />
              Cargando facturas pendientes...
            </div>
          ) : (
            <Tabs value={tab} onValueChange={(v) => setTab(v as "byInvoice" | "free")}>
              <TabsList className="grid w-full grid-cols-2">
                <TabsTrigger value="byInvoice">Por factura</TabsTrigger>
                <TabsTrigger value="free">Monto libre</TabsTrigger>
              </TabsList>

              <TabsContent value="byInvoice" className="flex flex-col gap-3 pt-3">
                <div className="flex justify-end">
                  <Button type="button" variant="outline" size="sm" onClick={payAll}>
                    Pagar todo ({formatMoney(totalDebt, config)})
                  </Button>
                </div>
                <div className="rounded-lg border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Documento</TableHead>
                        <TableHead>Fecha</TableHead>
                        <TableHead>Vencimiento</TableHead>
                        <TableHead className="text-right">Deuda</TableHead>
                        <TableHead className="w-40 text-right">A imputar</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {invoices.map((inv) => (
                        <TableRow key={inv.saleId}>
                          <TableCell className="font-medium">
                            {inv.invoiceNo || "—"}
                            {inv.saleId === primaryTransactionId && (
                              <span className="ml-1.5 text-xs text-muted-foreground">(esta factura)</span>
                            )}
                          </TableCell>
                          <TableCell className="text-xs text-muted-foreground">
                            {inv.date ? formatDateTime(inv.date, "d MMM yyyy") : "—"}
                          </TableCell>
                          <TableCell className="text-xs text-muted-foreground">
                            {inv.dueDate ? formatDateTime(inv.dueDate, "d MMM yyyy") : "—"}
                            {inv.dueStatus === "expired" && (
                              <span className="ml-1.5 text-destructive">vencida</span>
                            )}
                          </TableCell>
                          <TableCell className="text-right tabular-nums">
                            {formatMoney(inv.topay, config)}
                          </TableCell>
                          <TableCell>
                            <MoneyInput
                              value={amounts[inv.saleId] ?? 0}
                              onChange={(v) => setAmount(inv.saleId, v)}
                              className="h-8"
                              aria-label={`Monto a imputar a ${inv.invoiceNo || inv.saleId}`}
                            />
                            {rowErrors[inv.saleId] && (
                              <p className="mt-1 text-right text-xs text-destructive">{rowErrors[inv.saleId]}</p>
                            )}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
                {!byInvoiceValid && byInvoiceTotal > 0.001 && (
                  <p className="text-xs text-destructive">Revisá los montos marcados — superan la deuda de su factura.</p>
                )}
              </TabsContent>

              <TabsContent value="free" className="flex flex-col gap-3 pt-3">
                <div className="flex flex-col gap-1.5">
                  <Label>Monto a entregar</Label>
                  <MoneyInput value={freeAmount} onChange={(v) => setFreeAmount(v ?? 0)} className="h-8" />
                  <p className="text-xs text-muted-foreground">
                    Se reparte automáticamente entre las facturas pendientes, de la más vieja a la más nueva —
                    lo calcula el servidor al confirmar, esto es solo una vista previa.
                  </p>
                  {freeAmount > totalDebt + 0.001 && (
                    <p className="text-xs text-destructive">
                      Supera la deuda total ({formatMoney(totalDebt, config)}).
                    </p>
                  )}
                </div>
                <div className="rounded-lg border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Documento</TableHead>
                        <TableHead>Vencimiento</TableHead>
                        <TableHead className="text-right">Deuda</TableHead>
                        <TableHead className="text-right">Vista previa</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {freePreview.map((inv) => (
                        <TableRow key={inv.saleId}>
                          <TableCell className="font-medium">{inv.invoiceNo || "—"}</TableCell>
                          <TableCell className="text-xs text-muted-foreground">
                            {inv.dueDate ? formatDateTime(inv.dueDate, "d MMM yyyy") : "—"}
                            {inv.dueStatus === "expired" && (
                              <span className="ml-1.5 text-destructive">vencida</span>
                            )}
                          </TableCell>
                          <TableCell className="text-right tabular-nums">{formatMoney(inv.topay, config)}</TableCell>
                          <TableCell className="text-right tabular-nums font-medium">
                            {inv.applied > 0 ? formatMoney(inv.applied, config) : "—"}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </TabsContent>
            </Tabs>
          )}

          <div className="flex items-center justify-between rounded-lg bg-muted/40 px-4 py-3">
            <span className="text-sm font-medium text-muted-foreground">Total del recibo</span>
            <span className="text-lg font-semibold tabular-nums">{formatMoney(currentTotal, config)}</span>
          </div>

          {paymentMethods.length > 0 && (
            <div className="flex flex-col gap-1.5">
              <Label>Método de pago</Label>
              <Select value={pmKey} onValueChange={setPmKey}>
                <SelectTrigger>
                  <SelectValue placeholder="Seleccionar método..." />
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
          )}

          <div className="flex flex-col gap-1.5">
            <Label>Nota (opcional)</Label>
            <Textarea
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={2}
              placeholder="Observaciones..."
              className="resize-none"
            />
          </div>
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isPending}
          >
            Cancelar
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={!currentValid || !selectedMethod || isPending}
          >
            {isPending ? "Procesando..." : "Confirmar pago"}
          </Button>
        </DialogFooter>
      </DialogContent>

      <PaymentIdentifierDialog
        open={identifierOpen}
        method={selectedMethod?.requiresIdentifier ? selectedMethod : null}
        amount={currentTotal}
        config={config}
        onConfirm={(identifier) => {
          setIdentifierOpen(false)
          runPayment(identifier)
        }}
        onCancel={() => setIdentifierOpen(false)}
      />
    </Dialog>
  )
}
