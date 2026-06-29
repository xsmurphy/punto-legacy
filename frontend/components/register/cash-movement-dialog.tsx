"use client"

/**
 * Dialog para movimientos de caja con nota: extracción e ingreso de efectivo.
 *
 * Combina NumericPad raw (inline) + Textarea para la nota, dentro de un Dialog.
 * Reutilizable para ambos modos (expense / income) vía prop mode.
 *
 * Nota es opcional — se envía vacía si el cajero no la ingresa.
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { NumericPad } from "@/components/pos/numeric-pad"

interface CashMovementDialogProps {
  open: boolean
  onClose: () => void
  mode: "expense" | "income"
  title: string
  isPending?: boolean
  onConfirm: (amount: number, note: string) => void
}

export function CashMovementDialog({
  open,
  onClose,
  title,
  isPending = false,
  onConfirm,
}: CashMovementDialogProps) {
  const [draft, setDraft] = React.useState<string>("0")
  const [nota, setNota] = React.useState("")

  React.useEffect(() => {
    if (open) {
      setDraft("0")
      setNota("")
    }
  }, [open])

  function handleConfirm() {
    const amount = Number(draft)
    if (isNaN(amount) || amount <= 0) return
    onConfirm(amount, nota.trim())
  }

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-md p-0 gap-0">
        {/* Header */}
        <div className="border-b px-6 py-4">
          <h2 className="text-lg font-semibold">{title}</h2>
        </div>

        {/* Body: pad + nota */}
        <div className="px-6 py-6 space-y-4">
          <NumericPad
            mode="money"
            value={draft}
            onChange={setDraft}
            onConfirm={handleConfirm}
            onCancel={onClose}
          />
          <Textarea
            placeholder="Concepto (opcional)"
            value={nota}
            onChange={(e) => setNota(e.target.value)}
            rows={2}
            className="resize-none"
          />
        </div>

        {/* Footer */}
        <div className="border-t px-6 py-4">
          <Button
            onClick={handleConfirm}
            className="w-full"
            size="lg"
            disabled={isPending || !draft || draft === "0"}
          >
            {isPending ? "Guardando…" : "Confirmar"}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
