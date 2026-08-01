"use client"

/**
 * Wrapper único reutilizable para dialogs que usan NumericPad.
 * Layout legacy-style: title izquierda + mode label top-right en header,
 * numpad en body, Aceptar full-width en footer.
 *
 * Props:
 * - open, onClose: control del dialog
 * - title: encabezado (ej. "Ingrese la cantidad")
 * - subtitle: DEPRECATED — se mantiene por compat pero NO se renderiza
 * - mode: "int" | "decimal" | "money" | "percent"
 * - value, onValueChange: control del NumericPad
 * - onShiftToggle: handler de cambio de modo (si undefined, no se muestra hint)
 * - onConfirm: callback al presionar "Aceptar"
 * - confirmLabel: texto del botón confirmar (default "Aceptar")
 */

import * as React from "react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
} from "@/components/ui/responsive-dialog"
import { Button } from "@/components/ui/button"
import { NumericPad } from "@/components/pos/numeric-pad"

export interface NumericPadDialogProps {
  open: boolean
  onClose: () => void
  title: string
  subtitle?: string
  mode: "int" | "decimal" | "money" | "percent"
  value: string
  onValueChange: (v: string) => void
  onShiftToggle?: () => void
  onConfirm: () => void
  confirmLabel?: string
}

export function NumericPadDialog({
  open,
  onClose,
  title,
  subtitle: _subtitle, // no se usa — presente por compat
  mode,
  value,
  onValueChange,
  onShiftToggle,
  onConfirm,
  confirmLabel = "Aceptar",
}: NumericPadDialogProps) {
  // Computar mode label top-right según el modo
  const modeLabelTopRight = React.useMemo(() => {
    switch (mode) {
      case "decimal":
        return ".00"
      case "percent":
        return "%"
      default:
        return null
    }
  }, [mode])

  return (
    <ResponsiveDialog open={open} onOpenChange={(v) => !v && onClose()}>
      <ResponsiveDialogContent className="sm:max-w-md p-0 gap-0">
        {/* Header: title izquierda + mode label top-right */}
        <div className="flex items-center justify-between border-b px-6 py-4">
          <h2 className="text-lg font-semibold">{title}</h2>
          {modeLabelTopRight && (
            <span className="text-sm text-muted-foreground tabular-nums">
              {modeLabelTopRight}
            </span>
          )}
        </div>

        {/* Body: numpad */}
        <div className="px-6 py-6">
          <NumericPad
            mode={mode}
            value={value}
            onChange={onValueChange}
            onShiftToggle={onShiftToggle}
            onConfirm={onConfirm}
            onCancel={onClose}
          />
        </div>

        {/* Footer: botón único Aceptar full-width */}
        <div className="border-t px-6 py-4">
          <Button onClick={onConfirm} className="w-full" size="lg">
            {confirmLabel}
          </Button>
        </div>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
