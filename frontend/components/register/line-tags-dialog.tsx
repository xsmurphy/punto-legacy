"use client"

/**
 * Diálogo para editar las etiquetas de una línea del carrito.
 *
 * Mismo patrón de props que LineNoteDialog/LinePriceDialog/LineDiscountDialog
 * (line-note-dialog.tsx): open/line/onConfirm(lineId, ...)/onClose,
 * controlado desde cart-panel.tsx con un state `tagsLine` análogo a
 * `noteLine`/`priceLine`/`discountLine`.
 *
 * Pedido del owner (2026-08-14): "son etiquetas generales así como los
 * comentarios, salen en comandas etc pero no se imprimen en facturas, se
 * usan internamente" — MISMO catálogo y comportamiento que las etiquetas de
 * VENTA (sale-options-drawer.tsx → TagsDialog): "lista del comercio, igual
 * que las de venta". El picker (chips + sugerencias del catálogo `useTags()`)
 * es el componente compartido `TagsChipsField` — no se duplica esa UI.
 *
 * Igual que Comentario: no depende de isVoucher/qtyLocked (esos bloqueos son
 * por precio/cantidad congelados al emitir un vale, context/36 — una
 * etiqueta es metadata de texto libre sin efecto en el total ni el stock).
 *
 * Portal/click-afuera: ResponsiveDialog, mismo mecanismo que LineNoteDialog
 * — Radix marca su contenido con role="dialog", que `useOutsidePointerDown`
 * ya reconoce como "capa flotante" y excluye del cierre por click-afuera de
 * la línea (bug T9, fix 4c0158d0). Nada que registrar acá.
 */

import * as React from "react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
} from "@/components/ui/responsive-dialog"
import { Button } from "@/components/ui/button"
import { useTags } from "@/hooks/use-tags"
import { TagsChipsField, type TagsChipsFieldHandle } from "@/components/register/tags-chips-field"
import type { CartLine } from "@/lib/cart/store"

interface LineTagsDialogProps {
  open: boolean
  line: CartLine | null
  onConfirm: (lineId: string, tags: string[]) => void
  onClose: () => void
}

export function LineTagsDialog({
  open,
  line,
  onConfirm,
  onClose,
}: LineTagsDialogProps) {
  const { data } = useTags()
  const suggestions = (data?.tags ?? []).map((t) => t.name)
  const fieldRef = React.useRef<TagsChipsFieldHandle>(null)
  const value = line?.tags ?? []

  function confirm() {
    if (!line) return
    onConfirm(line.lineId, fieldRef.current?.flush() ?? value)
  }

  return (
    <ResponsiveDialog open={open} onOpenChange={(v) => !v && onClose()}>
      <ResponsiveDialogContent sectioned className="sm:max-w-md">
        {/* Header — mismo chrome que LineNoteDialog (línea hermana). */}
        <div className="border-b px-6 py-4">
          <h2 className="text-lg font-semibold">Etiquetas</h2>
          {line && (
            <p className="mt-0.5 truncate text-sm text-muted-foreground">
              {line.name}
            </p>
          )}
        </div>

        {/* Body */}
        <div className="flex flex-col gap-3 px-6 py-6">
          <TagsChipsField
            ref={fieldRef}
            open={open}
            value={value}
            suggestions={suggestions}
            onEscape={onClose}
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
