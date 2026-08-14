"use client"

/**
 * Diálogo para escribir/editar el comentario de una línea del carrito.
 *
 * Mismo patrón de props que LinePriceDialog/LineDiscountDialog (line-price-
 * dialog.tsx, line-discount-dialog.tsx): open/line/onConfirm(lineId, ...)/
 * onClose, controlado desde cart-panel.tsx con un state `noteLine` análogo a
 * `priceLine`/`discountLine`.
 *
 * El campo es texto libre, no numérico — NO reusa NumericPadDialog. Interacción
 * de teclado (autoFocus, Ctrl+Enter confirma, Escape cierra) calcada de
 * NoteDialog en sale-options-drawer.tsx (nota de VENTA, no de línea) — mismo
 * problema (Textarea dentro de un modal touch-first), misma solución ya
 * validada en este codebase.
 *
 * Guardar vacío = sin comentario: `confirm()` manda el string trimeado tal
 * cual, incluida la cadena vacía, y el caller (cart-panel.tsx → setLineNote)
 * lo persiste así. `hasNote` en cart-panel ya trata "" como "sin nota".
 *
 * Portal/click-afuera: usa ResponsiveDialog (Dialog en desktop / bottom
 * drawer en mobile), igual que NumericPadDialog. Radix marca su contenido
 * con role="dialog", que es justo lo que `useOutsidePointerDown`
 * (hooks/use-outside-pointerdown.ts) reconoce como "capa flotante" y excluye
 * del cierre por click-afuera de la línea — no hace falta registrar nada acá.
 */

import * as React from "react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
} from "@/components/ui/responsive-dialog"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import type { CartLine } from "@/lib/cart/store"

interface LineNoteDialogProps {
  open: boolean
  line: CartLine | null
  onConfirm: (lineId: string, note: string) => void
  onClose: () => void
}

export function LineNoteDialog({
  open,
  line,
  onConfirm,
  onClose,
}: LineNoteDialogProps) {
  const [draft, setDraft] = React.useState("")

  React.useEffect(() => {
    if (open && line) {
      setDraft(line.note ?? "")
    }
  }, [open, line])

  function confirm() {
    if (!line) return
    onConfirm(line.lineId, draft.trim())
  }

  return (
    <ResponsiveDialog open={open} onOpenChange={(v) => !v && onClose()}>
      <ResponsiveDialogContent className="sm:max-w-md p-0 gap-0">
        {/* Header — mismo chrome que NumericPadDialog (línea hermana). */}
        <div className="border-b px-6 py-4">
          <h2 className="text-lg font-semibold">Comentario</h2>
          {line && (
            <p className="mt-0.5 truncate text-sm text-muted-foreground">
              {line.name}
            </p>
          )}
        </div>

        {/* Body */}
        <div className="px-6 py-6">
          <Textarea
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            placeholder="Ej. sin cebolla, para llevar..."
            rows={4}
            autoFocus
            className="resize-none"
            onKeyDown={(e) => {
              if (e.key === "Enter" && e.ctrlKey) confirm()
              if (e.key === "Escape") onClose()
            }}
          />
        </div>

        {/* Footer */}
        <div className="border-t px-6 py-4">
          <Button onClick={confirm} className="w-full" size="lg">
            Guardar
          </Button>
        </div>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
