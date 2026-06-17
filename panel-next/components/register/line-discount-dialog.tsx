"use client"

/**
 * Diálogo para aplicar descuento por línea del carrito.
 *
 * Dos modos: porcentaje (%) y monto fijo. Toggle entre modos con un chip.
 * Enter confirma, ESC cierra.
 *
 * Modelo de descuento:
 *   - CartLine.discount = número entre 0 y 100 (porcentaje aplicado).
 *   - El lineSubtotal respeta el descuento: qty * unitPrice * (1 - discount/100).
 *   - "Monto fijo" se convierte a porcentaje: (monto / total_linea) * 100,
 *     donde total_linea = qty * unitPrice (consistente con el legacy:
 *     insertIndividualDiscount L22281–22316 — store percent siempre).
 *
 * Ref. legacy: app/scripts/app.js L3666–3684 (type == 'discount' en ncmItems.manage)
 * y L22281–22316 (insertIndividualDiscount).
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { MoneyInput } from "@/components/ui/money-input"
import { cn } from "@/lib/utils"
import type { CartLine } from "@/lib/cart/store"

type DiscountMode = "percent" | "amount"

interface LineDiscountDialogProps {
  open: boolean
  line: CartLine | null
  onConfirm: (lineId: string, discountPercent: number) => void
  onClose: () => void
}

export function LineDiscountDialog({
  open,
  line,
  onConfirm,
  onClose,
}: LineDiscountDialogProps) {
  const [mode, setMode] = React.useState<DiscountMode>("percent")
  const [percentDraft, setPercentDraft] = React.useState("")
  const [amountDraft, setAmountDraft] = React.useState<number | null>(null)

  React.useEffect(() => {
    if (open && line) {
      setMode("percent")
      // Pre-cargar el descuento existente si lo hay
      const existingDiscount = line.discount ?? 0
      setPercentDraft(existingDiscount > 0 ? String(existingDiscount) : "")
      setAmountDraft(null)
    }
  }, [open, line])

  function confirm() {
    if (!line) return

    const lineTotal = line.qty * line.unitPrice
    let discountPercent = 0

    if (mode === "percent") {
      const pct = parseFloat(percentDraft)
      if (isNaN(pct) || pct < 0 || pct > 100) return
      discountPercent = pct
    } else {
      // Monto fijo → convertir a porcentaje (mismo que legacy insertIndividualDiscount)
      if (amountDraft === null || amountDraft < 0) return
      if (lineTotal <= 0) return
      discountPercent = Math.min(100, (amountDraft / lineTotal) * 100)
    }

    onConfirm(line.lineId, discountPercent)
  }

  const isValid = React.useMemo(() => {
    if (!line) return false
    if (mode === "percent") {
      const pct = parseFloat(percentDraft)
      return !isNaN(pct) && pct >= 0 && pct <= 100
    } else {
      return amountDraft !== null && amountDraft >= 0
    }
  }, [line, mode, percentDraft, amountDraft])

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent
        className="sm:max-w-sm"
        onKeyDown={(e) => {
          if (e.key === "Enter") {
            e.preventDefault()
            if (isValid) confirm()
          }
        }}
      >
        <DialogHeader>
          <DialogTitle>Aplicar descuento</DialogTitle>
          <DialogDescription className="truncate">
            {line?.name ?? ""}
          </DialogDescription>
        </DialogHeader>

        {/* Toggle % / monto */}
        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => setMode("percent")}
            className={cn(
              "flex-1 rounded-full border px-3 py-1.5 text-sm font-semibold transition-colors",
              mode === "percent"
                ? "border-brand bg-brand/20 text-brand"
                : "border-border text-muted-foreground hover:border-muted-foreground",
            )}
          >
            Porcentaje (%)
          </button>
          <button
            type="button"
            onClick={() => setMode("amount")}
            className={cn(
              "flex-1 rounded-full border px-3 py-1.5 text-sm font-semibold transition-colors",
              mode === "amount"
                ? "border-brand bg-brand/20 text-brand"
                : "border-border text-muted-foreground hover:border-muted-foreground",
            )}
          >
            Monto fijo
          </button>
        </div>

        <div className="my-2">
          {mode === "percent" ? (
            <div className="relative">
              <input
                type="text"
                inputMode="decimal"
                value={percentDraft}
                onChange={(e) => {
                  const v = e.target.value.replace(/[^\d.]/g, "")
                  setPercentDraft(v)
                }}
                placeholder="0"
                autoFocus
                className={cn(
                  "h-20 w-full border-none bg-transparent pr-10",
                  "text-center text-5xl font-bold tabular-nums",
                  "outline-none focus:outline-none focus:ring-0",
                )}
                aria-label="Porcentaje de descuento"
              />
              <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-3xl font-bold text-muted-foreground">
                %
              </span>
            </div>
          ) : (
            <MoneyInput
              value={amountDraft}
              onChange={setAmountDraft}
              autoFocus
              className="h-20 text-center text-5xl font-bold"
            />
          )}
        </div>

        <div className="mt-2 flex gap-2">
          <Button variant="outline" className="flex-1" onClick={onClose}>
            Cancelar
          </Button>
          <Button className="flex-1" onClick={confirm} disabled={!isValid}>
            Aceptar
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
