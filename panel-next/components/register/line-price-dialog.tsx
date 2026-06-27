"use client"

/**
 * Diálogo para editar el precio unitario de una línea del carrito.
 *
 * Usa NumericPadDialog (patrón canónico) — mismo que qty-edit-dialog.tsx.
 * Enter confirma, ESC cierra. Operación sin mouse para cajero touch/tablet.
 *
 * Ref. legacy: app/scripts/app.js L3820–3855 (type == 'price' en ncmItems.manage).
 * El legacy modifica `item.uniPrice` y llama `updateItemFinalPrice(index)`.
 * Acá mapeamos a `line.unitPrice` del CartStore (idéntica semántica).
 */

import * as React from "react"
import { NumericPadDialog } from "@/components/pos/numeric-pad-dialog"
import type { CartLine } from "@/lib/cart/store"

interface LinePriceDialogProps {
  open: boolean
  line: CartLine | null
  onConfirm: (lineId: string, newPrice: number) => void
  onClose: () => void
}

export function LinePriceDialog({
  open,
  line,
  onConfirm,
  onClose,
}: LinePriceDialogProps) {
  const [draft, setDraft] = React.useState<string>("0")

  React.useEffect(() => {
    if (open && line) {
      setDraft(String(line.unitPrice))
    }
  }, [open, line])

  function confirm() {
    if (!line) return
    const price = Number(draft)
    if (isNaN(price) || price < 0) return
    onConfirm(line.lineId, price)
  }

  return (
    <NumericPadDialog
      open={open}
      onClose={onClose}
      title="Modificar precio"
      subtitle={line?.name ?? ""}
      mode="money"
      value={draft}
      onValueChange={setDraft}
      onConfirm={confirm}
      confirmLabel="Aceptar"
    />
  )
}
