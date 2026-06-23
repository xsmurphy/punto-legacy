"use client"

import * as React from "react"
import { toast } from "sonner"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCreateCreditPayment } from "@/hooks/use-credit-payment"
import { MoneyInput } from "@/components/ui/money-input"
import { formatAmount } from "@/lib/format-money"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
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

interface CreditPaymentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  parentTransactionId: string
  debt: number
  customerName: string
  onSuccess?: (result: { parentComplete: boolean; debtRemaining: number }) => void
}

export function CreditPaymentDialog({
  open,
  onOpenChange,
  parentTransactionId,
  debt,
  customerName,
  onSuccess,
}: CreditPaymentDialogProps) {
  const paymentMethods = useCatalogStore((s) => s.paymentMethods)
  const config = useCatalogStore((s) => s.config)
  const mutation = useCreateCreditPayment()

  const defaultMethod = paymentMethods.find((m) => m.isDefault) ?? paymentMethods[0]

  const [amount, setAmount] = React.useState<number | null>(debt)
  const [pmKey, setPmKey] = React.useState<string>(defaultMethod?.id ?? "")
  const [note, setNote] = React.useState("")

  React.useEffect(() => {
    if (open) {
      setAmount(debt)
      setPmKey(defaultMethod?.id ?? "")
      setNote("")
    }
  }, [open, debt, defaultMethod?.id])

  const selectedMethod = paymentMethods.find((m) => m.id === pmKey)
  const amountValid = (amount ?? 0) > 0 && (amount ?? 0) <= debt + 0.001

  function handleConfirm() {
    if (!amountValid || !selectedMethod) return
    mutation.mutate(
      {
        parentTransactionId,
        amount: amount!,
        paymentMethodKey: selectedMethod.id,
        paymentMethodName: selectedMethod.name,
        note: note.trim() || undefined,
      },
      {
        onSuccess: (data) => {
          const debtMsg =
            data.debtRemaining > 0
              ? `Saldo restante: ${formatAmount(data.debtRemaining, config)}`
              : "Factura saldada"
          toast.success(`Pago registrado — ${debtMsg}`)
          onOpenChange(false)
          onSuccess?.(data)
        },
        onError: (err) => {
          const msg = err instanceof Error ? err.message : "Error al registrar pago"
          toast.error(msg)
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Cobrar crédito</DialogTitle>
          <DialogDescription>
            Cliente: {customerName} — Saldo pendiente: {formatAmount(debt, config)}
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 py-2">
          <div className="flex flex-col gap-1.5">
            <Label>Monto a cobrar</Label>
            <MoneyInput
              value={amount}
              onChange={setAmount}
              autoFocus
            />
            {!amountValid && amount !== null && (
              <p className="text-xs text-destructive">
                El monto debe ser mayor a 0 y no superar {formatAmount(debt, config)}
              </p>
            )}
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
            disabled={mutation.isPending}
          >
            Cancelar
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={!amountValid || !selectedMethod || mutation.isPending}
          >
            {mutation.isPending ? "Procesando..." : "Confirmar pago"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
