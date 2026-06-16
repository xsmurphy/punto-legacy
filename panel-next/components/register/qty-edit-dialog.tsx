"use client"

/**
 * Diálogo para editar la cantidad de una línea del carrito.
 *
 * UX POS (touch + teclado): input grande autofocused — el cajero puede
 * tipear desde el teclado real, o usar el numpad on-screen para touch.
 * Enter confirma, ESC cierra. Aceptar con 0 elimina la línea (consistente
 * con decQty del store).
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
import { cn } from "@/lib/utils"

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
  // Trabajamos con string para permitir borrar todos los dígitos sin commitear
  // 0 al modelo. Al confirmar parseamos a número.
  const [draft, setDraft] = React.useState<string>(String(initialQty))
  const inputRef = React.useRef<HTMLInputElement>(null)

  React.useEffect(() => {
    if (open) {
      setDraft(String(initialQty))
      const id = setTimeout(() => {
        inputRef.current?.focus()
        inputRef.current?.select()
      }, 50)
      return () => clearTimeout(id)
    }
  }, [open, initialQty])

  function confirm() {
    const n = Math.max(0, Math.floor(Number(draft) || 0))
    onConfirm(n)
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
          <DialogTitle>Cantidad</DialogTitle>
          <DialogDescription className="truncate">{itemName}</DialogDescription>
        </DialogHeader>

        {/* Visor: sin bordes, look nativo (no parece input).
            El softkeyboard on-screen para touch llegará como feature global
            opt-in que aplica a todos los campos numéricos del POS. */}
        <div className="my-2">
          <input
            ref={inputRef}
            type="text"
            inputMode="numeric"
            pattern="[0-9]*"
            value={draft}
            onChange={(e) => {
              const v = e.target.value.replace(/[^\d]/g, "")
              setDraft(v)
            }}
            className={cn(
              "h-20 w-full border-none bg-transparent",
              "text-center text-5xl font-bold tabular-nums",
              "outline-none focus:outline-none focus:ring-0",
            )}
            aria-label="Cantidad"
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
