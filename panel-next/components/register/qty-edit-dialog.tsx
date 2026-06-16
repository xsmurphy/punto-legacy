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
import { Delete } from "lucide-react"

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

  function pressDigit(d: string) {
    setDraft((prev) => {
      // Si el draft es "0" o vacío y se aprieta un dígito → reemplaza.
      if (prev === "0" || prev === "") return d
      return prev + d
    })
  }

  function pressBack() {
    setDraft((prev) => (prev.length <= 1 ? "" : prev.slice(0, -1)))
  }

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

        {/* Display + input invisible para teclado físico */}
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
              "h-20 w-full rounded-xl border border-border bg-muted/40",
              "text-center text-5xl font-bold tabular-nums",
              "focus:outline-none focus:ring-2 focus:ring-ring",
            )}
            aria-label="Cantidad"
          />
        </div>

        {/* Numpad on-screen (touch). El input mantiene foco; preventDefault
            en mousedown evita perder foco. */}
        <div className="grid grid-cols-3 gap-2">
          {["1", "2", "3", "4", "5", "6", "7", "8", "9"].map((d) => (
            <NumPadButton key={d} onClick={() => pressDigit(d)}>
              {d}
            </NumPadButton>
          ))}
          <NumPadButton onClick={() => setDraft("")} aria-label="Limpiar">
            C
          </NumPadButton>
          <NumPadButton onClick={() => pressDigit("0")}>0</NumPadButton>
          <NumPadButton onClick={pressBack} aria-label="Borrar último dígito">
            <Delete className="size-5" />
          </NumPadButton>
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

function NumPadButton({
  children,
  onClick,
  ...rest
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      className={cn(
        "h-14 rounded-xl border border-border bg-muted/40",
        "text-xl font-semibold transition-colors",
        "hover:bg-muted active:scale-[0.97]",
        "flex items-center justify-center",
      )}
      {...rest}
    >
      {children}
    </button>
  )
}
