"use client"

/**
 * Diálogo para editar el precio unitario de una línea del carrito.
 *
 * UX: mismo patrón que qty-edit-dialog.tsx — MoneyInput grande autofocused,
 * Enter confirma, ESC cierra. El cajero puede operar sin mouse.
 *
 * Ref. legacy: app/scripts/app.js L3820–3855 (type == 'price' en ncmItems.manage).
 * El legacy modifica `item.uniPrice` y llama `updateItemFinalPrice(index)`.
 * Acá mapeamos a `line.unitPrice` del CartStore (idéntica semántica).
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
import { MoneyInput } from "@/components/ui/money-input"
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
  const [draft, setDraft] = React.useState<number | null>(null)

  React.useEffect(() => {
    if (open && line) {
      setDraft(line.unitPrice)
    }
  }, [open, line])

  function confirm() {
    if (!line || draft === null || draft < 0) return
    onConfirm(line.lineId, draft)
  }

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent
        className="sm:max-w-sm"
        onKeyDown={(e) => {
          if (e.key === "Enter") {
            e.preventDefault()
            confirm()
          }
        }}
      >
        <DialogHeader>
          <DialogTitle>Modificar precio</DialogTitle>
          <DialogDescription className="truncate">
            {line?.name ?? ""}
          </DialogDescription>
        </DialogHeader>

        <div className="my-2">
          <MoneyInput
            value={draft}
            onChange={setDraft}
            autoFocus
            className="h-20 text-center text-5xl font-bold"
          />
        </div>

        <div className="mt-2 flex gap-2">
          <Button variant="outline" className="flex-1" onClick={onClose}>
            Cancelar
          </Button>
          <Button
            className="flex-1"
            onClick={confirm}
            disabled={draft === null || draft < 0}
          >
            Aceptar
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
