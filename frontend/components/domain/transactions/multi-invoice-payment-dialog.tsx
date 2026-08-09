"use client"

import * as React from "react"
import { toast } from "sonner"
import { useCreateCreditPayment, type CreditPaymentAllocation } from "@/hooks/use-credit-payment"
import { useReport, type OpenInvoicesReportResponse } from "@/hooks/use-reports"
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

interface MultiInvoicePaymentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Cliente dueño de todas las facturas — el backend exige que sea el mismo en todo el recibo. */
  customerId: string
  customerName: string
  /** Factura desde la que se abrió el diálogo — llega precargada con su deuda completa. */
  primaryTransactionId: string
  primaryDebt: number
  paymentMethods: PaymentMethodConfig[]
  config: PosConfig | null
  onSuccess?: (result: {
    id: string
    encId: string
    allocations?: Array<{ parentTransactionId: string; amount: number; parentComplete: boolean; debtRemaining: number }>
  }) => void
}

/**
 * Cobro de crédito — versión panel del diálogo, con soporte multi-factura
 * (mig 123 / CreditPaymentService::create con `allocations`). A diferencia
 * de `CreditPaymentDialog` (components/register/), que el POS sigue usando
 * para cobrar UNA factura con el visor táctil, este diálogo lista TODAS las
 * facturas a crédito pendientes del cliente y permite repartir un único
 * recibo entre varias — un solo `invoiceNo` para N facturas saldadas.
 *
 * Reusa `GET /v1/reports/open_invoices?state=income&contactId=` (mismo
 * reporte de Cuentas por Cobrar, filtrado a un contacto — ver
 * OpenInvoicesService::general()) en vez de crear un endpoint nuevo.
 */
export function MultiInvoicePaymentDialog({
  open,
  onOpenChange,
  customerId,
  customerName,
  primaryTransactionId,
  primaryDebt,
  paymentMethods,
  config,
  onSuccess,
}: MultiInvoicePaymentDialogProps) {
  const mutation = useCreateCreditPayment()

  const defaultMethod = paymentMethods.find((m) => m.isDefault) ?? paymentMethods[0]
  const [pmKey, setPmKey] = React.useState<string>(defaultMethod?.id ?? "")
  const [note, setNote] = React.useState("")
  const [identifierOpen, setIdentifierOpen] = React.useState(false)
  // Monto a imputar por factura, keyed por saleId (transactionId).
  const [amounts, setAmounts] = React.useState<Record<string, number>>({})

  const { data, isLoading } = useReport<OpenInvoicesReportResponse>("open_invoices", {
    params: { state: "income", contactId: customerId },
    enabled: open && !!customerId,
  })

  // El reporte agrupa por contacto — al filtrar por contactId queda un único
  // row (o ninguno si el cliente no tiene más facturas abiertas).
  const invoices = data?.rows?.[0]?.invoices ?? []

  React.useEffect(() => {
    if (!open) return
    setPmKey(defaultMethod?.id ?? "")
    setNote("")
    // Precarga: la factura de origen con su deuda completa, el resto en 0.
    // Solo corre cuando el reporte ya trajo la lista (si tarda, arranca con
    // la factura de origen sola para no bloquear la UI).
    setAmounts((prev) => {
      const next: Record<string, number> = { [primaryTransactionId]: primaryDebt }
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

  // Validación: ningún monto puede superar la deuda de SU factura (topay) y
  // el total tiene que ser > 0. El backend revalida todo esto de nuevo
  // adentro de la TX con lock — esto es solo para no llegar al submit con un
  // error evitable.
  const rowErrors: Record<string, string> = {}
  let total = 0
  for (const inv of invoices) {
    const amt = amounts[inv.saleId] ?? 0
    total += amt
    if (amt > inv.topay + 0.001) {
      rowErrors[inv.saleId] = `Supera la deuda (${formatAmount(inv.topay, config)})`
    }
  }
  const hasRowErrors = Object.keys(rowErrors).length > 0
  const totalValid = total > 0.001 && !hasRowErrors

  function setAmount(saleId: string, value: number | null) {
    setAmounts((prev) => ({ ...prev, [saleId]: value ?? 0 }))
  }

  function buildAllocations(): CreditPaymentAllocation[] {
    return invoices
      .map((inv) => ({ parentTransactionId: inv.saleId, amount: amounts[inv.saleId] ?? 0 }))
      .filter((a) => a.amount > 0)
  }

  function runPayment(identifier?: string) {
    const allocations = buildAllocations()
    if (!totalValid || !selectedMethod || allocations.length === 0) return
    mutation.mutate(
      {
        allocations,
        paymentMethodKey: selectedMethod.id,
        note: note.trim() || undefined,
        identifier: identifier || undefined,
      },
      {
        onSuccess: (result) => {
          const list = result.allocations ?? []
          const settledCount = list.filter((a) => a.parentComplete).length
          const msg =
            list.length > 1
              ? settledCount === list.length
                ? `Recibo registrado — ${list.length} facturas saldadas`
                : `Recibo registrado — ${settledCount} de ${list.length} facturas saldadas`
              : result.debtRemaining > 0
                ? `Pago registrado — saldo restante: ${formatAmount(result.debtRemaining, config)}`
                : "Pago registrado — factura saldada"
          toast.success(msg)
          onOpenChange(false)
          onSuccess?.(result)
        },
        onError: (err) => {
          const msg = err instanceof Error ? err.message : "Error al registrar pago"
          toast.error(msg)
        },
      },
    )
  }

  function handleConfirm() {
    if (!totalValid || !selectedMethod) return
    if (selectedMethod.requiresIdentifier) {
      setIdentifierOpen(true)
      return
    }
    runPayment()
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Cobrar crédito</DialogTitle>
          <DialogDescription>
            Cliente: {customerName} — un recibo puede repartirse entre varias facturas pendientes.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 py-2">
          {isLoading ? (
            <div className="flex h-24 items-center justify-center text-sm text-muted-foreground">
              <Loader2 className="mr-2 size-4 animate-spin" />
              Cargando facturas pendientes...
            </div>
          ) : (
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
          )}

          <div className="flex items-center justify-between rounded-lg bg-muted/40 px-4 py-3">
            <span className="text-sm font-medium text-muted-foreground">Total del recibo</span>
            <span className="text-lg font-semibold tabular-nums">{formatMoney(total, config)}</span>
          </div>
          {!totalValid && total > 0.001 && (
            <p className="text-xs text-destructive">Revisá los montos marcados — superan la deuda de su factura.</p>
          )}

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
            disabled={mutation.isPending}
          >
            Cancelar
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={!totalValid || !selectedMethod || mutation.isPending}
          >
            {mutation.isPending ? "Procesando..." : "Confirmar pago"}
          </Button>
        </DialogFooter>
      </DialogContent>

      <PaymentIdentifierDialog
        open={identifierOpen}
        method={selectedMethod?.requiresIdentifier ? selectedMethod : null}
        amount={total}
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
