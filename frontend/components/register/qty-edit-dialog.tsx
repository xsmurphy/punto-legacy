"use client"

/**
 * Diálogo para editar la cantidad de una línea del carrito.
 *
 * Usa NumericPadDialog (wrapper único) para soporte touch + teclado físico.
 * Shift alterna entre modo entero y decimal (persiste en usePosUIStore).
 * Si la cantidad inicial tiene decimales, fuerza modo decimal al abrir.
 * Enter confirma, ESC cierra. Confirmar con 0 elimina la línea.
 */

import * as React from "react"
import { NumericPadDialog } from "@/components/pos/numeric-pad-dialog"
import { usePosUIStore } from "@/lib/ui/store"

interface QtyEditDialogProps {
  open: boolean
  initialQty: number
  itemName: string
  onConfirm: (qty: number) => void
  onClose: () => void
}

export function QtyEditDialog({
  open,
  initialQty,
  itemName,
  onConfirm,
  onClose,
}: QtyEditDialogProps) {
  const storedMode = usePosUIStore((s) => s.qtyPadMode)
  const setQtyPadMode = usePosUIStore((s) => s.setQtyPadMode)

  const [draft, setDraft] = React.useState<string>("0")
  // El modo local puede diferir del store si initialQty tiene decimales
  const [localMode, setLocalMode] = React.useState<"int" | "decimal">(storedMode)

  React.useEffect(() => {
    if (open) {
      // Si el ítem ya tiene decimales, forzar decimal para no perder precisión
      const hasDecimals = initialQty % 1 !== 0
      const mode = hasDecimals ? "decimal" : storedMode
      setLocalMode(mode)
      setDraft(String(initialQty))
    }
    // storedMode INTENCIONALMENTE excluido: solo inicializamos al abrir el dialog,
    // no en cada cambio de modo (SHIFT toggle persiste a storedMode → resetearía draft).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialQty])

  function handleShiftToggle() {
    const next = localMode === "int" ? "decimal" : "int"
    setLocalMode(next)
    setQtyPadMode(next)
    // Truncar el draft si pasamos a int y tenía parte decimal
    if (next === "int" && draft.includes(".")) {
      setDraft(String(Math.round(Number(draft))))
    }
  }

  function confirm() {
    const n = Math.max(
      0,
      localMode === "decimal" ? Number(draft) : Math.round(Number(draft)),
    )
    onConfirm(n)
  }

  return (
    <NumericPadDialog
      open={open}
      onClose={onClose}
      title="Ingrese la cantidad"
      subtitle={itemName}
      mode={localMode}
      value={draft}
      onValueChange={setDraft}
      onShiftToggle={handleShiftToggle}
      onConfirm={confirm}
    />
  )
}
