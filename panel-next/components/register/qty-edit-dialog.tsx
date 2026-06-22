"use client"

/**
 * Diálogo para editar la cantidad de una línea del carrito.
 *
 * Usa NumericPad para soporte touch + teclado físico.
 * Shift alterna entre modo entero y decimal (persiste en usePosUIStore).
 * Si la cantidad inicial tiene decimales, fuerza modo decimal al abrir.
 * Enter confirma, ESC cierra. Confirmar con 0 elimina la línea.
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
import { NumericPad } from "@/components/pos/numeric-pad"
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
  }, [open, initialQty, storedMode])

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
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>Cantidad</DialogTitle>
          <DialogDescription className="truncate">{itemName}</DialogDescription>
        </DialogHeader>

        <div className="my-2">
          <NumericPad
            mode={localMode}
            value={draft}
            onChange={setDraft}
            onShiftToggle={handleShiftToggle}
            onConfirm={confirm}
            onCancel={onClose}
          />
        </div>

        <div className="mt-2 flex gap-2">
          <Button variant="outline" className="flex-1" onClick={onClose}>
            Cancelar
          </Button>
          <Button className="flex-1" onClick={confirm}>
            Aceptar
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
