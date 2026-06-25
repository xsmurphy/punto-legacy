"use client"

/**
 * Diálogo para aplicar descuento por línea del carrito.
 *
 * Usa NumericPad con dos modos: porcentaje (%) y monto fijo (money).
 * Shift alterna entre modos (persiste en usePosUIStore). Al alternar se
 * resetea el draft para evitar conversiones confusas en el display.
 *
 * Modelo de descuento:
 *   - CartLine.discount = número entre 0 y 100 (porcentaje aplicado).
 *   - "Monto fijo" → (monto / total_linea) * 100, clampeado a 100.
 *     Ref. legacy: insertIndividualDiscount L22281-22316 — store percent siempre.
 *
 * Ref. legacy: app/scripts/app.js L3666-3684 (type == 'discount' en ncmItems.manage)
 */

import * as React from "react"
import { NumericPadDialog } from "@/components/pos/numeric-pad-dialog"
import { usePosUIStore } from "@/lib/ui/store"
import type { CartLine } from "@/lib/cart/store"

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
  const storedMode = usePosUIStore((s) => s.discountPadMode)
  const setDiscountPadMode = usePosUIStore((s) => s.setDiscountPadMode)

  const [localMode, setLocalMode] = React.useState<"money" | "percent">(storedMode)
  const [draft, setDraft] = React.useState<string>("0")

  React.useEffect(() => {
    if (open && line) {
      setLocalMode(storedMode)
      // Pre-cargar solo si el modo inicial es percent y hay descuento
      const existing = line.discount ?? 0
      if (storedMode === "percent" && existing > 0) {
        setDraft(String(existing))
      } else {
        setDraft("0")
      }
    }
  }, [open, line, storedMode])

  function handleShiftToggle() {
    const next = localMode === "money" ? "percent" : "money"
    setLocalMode(next)
    setDiscountPadMode(next)
    // Resetear draft al cambiar modo — la conversión inversa introduce error
    setDraft("0")
  }

  function confirm() {
    if (!line) return

    const value = parseFloat(draft)
    // Draft vacío o cero → limpiar descuento
    if (!draft || draft === "0" || isNaN(value)) {
      onConfirm(line.lineId, 0)
      return
    }

    const lineTotal = line.qty * line.unitPrice
    let discountPercent = 0

    if (localMode === "percent") {
      // Clampear 0-100 para no pasar un porcentaje inválido al store
      discountPercent = Math.min(100, Math.max(0, value))
    } else {
      // Monto fijo → convertir a porcentaje (mismo que legacy insertIndividualDiscount)
      if (lineTotal <= 0) return
      discountPercent = Math.min(100, (value / lineTotal) * 100)
    }

    onConfirm(line.lineId, discountPercent)
  }

  const padMode = localMode === "percent" ? "percent" : "money"

  return (
    <NumericPadDialog
      open={open}
      onClose={onClose}
      title="Descuento individual"
      subtitle={line?.name ?? ""}
      mode={padMode}
      value={draft}
      onValueChange={setDraft}
      onShiftToggle={handleShiftToggle}
      onConfirm={confirm}
      confirmLabel="Aceptar"
    />
  )
}
