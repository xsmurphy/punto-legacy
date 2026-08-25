"use client"

/**
 * Wrapper único reutilizable para dialogs que capturan UN número.
 * Layout legacy-style: title izquierda + mode label top-right en header,
 * captura numérica en body, Aceptar full-width en footer.
 *
 * El body monta `<NumericField>`, que en teléfono cambia el pad en pantalla por
 * un campo nativo (teclado del sistema) y en tablet/desktop deja el pad igual
 * que siempre. Los call-sites no ven la diferencia.
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
import { NumericField } from "@/components/pos/numeric-field"

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
      <ResponsiveDialogContent sectioned className="sm:max-w-md">
        {/* Header: title izquierda + mode label top-right */}
        <div className="flex items-center justify-between border-b px-6 py-4">
          <h2 className="text-lg font-semibold">{title}</h2>
          {modeLabelTopRight && (
            <span className="text-sm text-muted-foreground tabular-nums">
              {modeLabelTopRight}
            </span>
          )}
        </div>

        {/* Body: la captura numérica — pad en tablet/desktop, campo nativo en
            teléfono (`NumericField` resuelve la rama). */}
        <div className="px-6 py-6">
          <NumericField
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
