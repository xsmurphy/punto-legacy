"use client"

/**
 * Diálogo para editar la cantidad de una línea del carrito.
 *
 * Usa NumericPadDialog (wrapper único) para soporte touch + teclado físico.
 * El pad SIEMPRE abre en modo decimal (el punto nunca debe estar bloqueado
 * acá — items fraccionables como kg/litros lo necesitan desde el primer
 * dígito). Shift sigue permitiendo forzar modo entero si el usuario lo
 * prefiere para esa edición puntual.
 * Enter confirma, ESC cierra. Confirmar con 0 elimina la línea.
 */

import * as React from "react"
import { NumericPadDialog } from "@/components/pos/numeric-pad-dialog"

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
  const [draft, setDraft] = React.useState<string>("0")
  // Siempre arranca en decimal — el punto no debe estar bloqueado al editar
  // cantidad (ver comentario de módulo). Shift permite pasar a int si hace falta.
  const [localMode, setLocalMode] = React.useState<"int" | "decimal">("decimal")

  React.useEffect(() => {
    if (open) {
      setLocalMode("decimal")
      setDraft(String(initialQty))
    }
  }, [open, initialQty])

  function handleShiftToggle() {
    const next = localMode === "int" ? "decimal" : "int"
    setLocalMode(next)
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
