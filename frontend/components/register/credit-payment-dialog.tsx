"use client"

import * as React from "react"
import { toast } from "sonner"
import { useCreateCreditPayment } from "@/hooks/use-credit-payment"
import { formatAmount, formatMoney } from "@/lib/format-money"
import { MoneyVisor, parseDisplay, formatDisplayInput } from "@/components/register/money-visor"
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { PaymentIdentifierDialog } from "./payment-identifier-dialog"

interface CreditPaymentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  parentTransactionId: string
  debt: number
  customerName: string
  /**
   * Métodos de pago del tenant y config de formato de moneda — inyectados
   * por el caller (no leídos de un store global) para que el dialog sea
   * reusable fuera del POS: el llamador POS pasa `useCatalogStore` (bootstrap
   * del device), el llamador panel pasa `usePaymentMethods()` + `useBootstrap()`
   * (`Bootstrap` es estructuralmente compatible con `PosConfig` — mismos campos
   * de currency/thousand/decimal). Antes leía de `useCatalogStore` directo,
   * que solo se hidrata con el bootstrap del POS — en panel quedaba vacío y
   * el selector de método de pago no renderizaba nada (botón "Confirmar pago"
   * deshabilitado para siempre).
   */
  paymentMethods: PaymentMethodConfig[]
  config: PosConfig | null
  onSuccess?: (result: { id: string; parentComplete: boolean; paid: number; debtRemaining: number }) => void
}

export function CreditPaymentDialog({
  open,
  onOpenChange,
  parentTransactionId,
  debt,
  customerName,
  paymentMethods,
  config,
  onSuccess,
}: CreditPaymentDialogProps) {
  const mutation = useCreateCreditPayment()

  const defaultMethod = paymentMethods.find((m) => m.isDefault) ?? paymentMethods[0]

  // display es el string formateado del visor (vacío = cajero no tipeó nada).
  // Si está vacío, el monto efectivo es `debt` (cobra el saldo completo).
  const [display, setDisplay] = React.useState("")
  const [pmKey, setPmKey] = React.useState<string>(defaultMethod?.id ?? "")
  const [note, setNote] = React.useState("")
  const [identifierOpen, setIdentifierOpen] = React.useState(false)

  const visorRef = React.useRef<HTMLInputElement>(null)

  React.useEffect(() => {
    if (open) {
      setDisplay("")
      setPmKey(defaultMethod?.id ?? "")
      setNote("")
      // autofocus al visor
      setTimeout(() => visorRef.current?.focus(), 50)
    }
  }, [open, defaultMethod?.id])

  // Si el cajero no tipeó nada, cobra el saldo completo
  const amount = display === "" ? debt : parseDisplay(display)
  const amountValid = amount > 0 && amount <= debt + 0.001

  const selectedMethod = paymentMethods.find((m) => m.id === pmKey)

  function handleVisorChange(raw: number) {
    // Sincronizamos el display string formateado a partir del raw
    const formatted = raw === 0 ? "" : formatDisplayInput(String(raw))
    setDisplay(formatted)
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Backspace" && display === "") {
      // visor vacío: no hacer nada especial, ya muestra placeholder
      return
    }
  }

  function runPayment(identifier?: string) {
    if (!amountValid || !selectedMethod) return
    mutation.mutate(
      {
        parentTransactionId,
        amount,
        paymentMethodKey: selectedMethod.id,
        note: note.trim() || undefined,
        identifier: identifier || undefined,
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

  function handleConfirm() {
    if (!amountValid || !selectedMethod) return
    // Métodos que exigen identificador (tarjeta, etc.): pedirlo antes de cobrar.
    if (selectedMethod.requiresIdentifier) {
      setIdentifierOpen(true)
      return
    }
    runPayment()
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
          {/* Visor grande estándar POS — igual que PayDialog */}
          <div className="flex flex-col gap-1.5">
            <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Monto a cobrar
            </Label>
            <MoneyVisor
              ref={visorRef}
              value={display}
              onValueChange={handleVisorChange}
              placeholder={formatMoney(debt, config)}
              ariaLabel="Monto a cobrar"
              onKeyDown={handleKeyDown}
            />
            {!amountValid && display !== "" && (
              <p className="text-center text-xs text-destructive">
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

      <PaymentIdentifierDialog
        open={identifierOpen}
        method={selectedMethod?.requiresIdentifier ? selectedMethod : null}
        amount={amount}
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
