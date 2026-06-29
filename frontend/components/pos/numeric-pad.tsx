"use client"

import * as React from "react"
import { Delete } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useCatalogStore } from "@/lib/catalog/store"
import { usePosUIStore } from "@/lib/ui/store"
import { formatAmount } from "@/lib/format-money"

export interface NumericPadProps {
  mode: "int" | "decimal" | "money" | "percent"
  value: string
  onChange: (v: string) => void
  onShiftToggle?: () => void
  onConfirm: () => void
  onCancel?: () => void
}

function appendDigit(current: string, digit: string, mode?: string): string {
  // Porcentaje: máximo 3 dígitos para que quepa "100" pero no "1001"
  if (mode === "percent" && current.length >= 3) return current
  if (current.length >= 10) return current
  // Reemplazar "0" solitario con el dígito (excepto si es otro 0)
  if (current === "0" && digit !== "0") return digit
  if (current === "0" && digit === "0") return current
  return current + digit
}

function appendDot(current: string): string {
  if (current.includes(".")) return current
  return current + "."
}

function backspace(current: string): string {
  if (current.length <= 1) return "0"
  return current.slice(0, -1)
}

export function NumericPad({
  mode,
  value,
  onChange,
  onShiftToggle,
  onConfirm,
  onCancel,
}: NumericPadProps) {
  const config = useCatalogStore((s) => s.config)
  const showSoftKeyboard = usePosUIStore((s) => s.showSoftKeyboard)
  const allowDot = mode !== "int"

  const isFirstRef = React.useRef(true)
  const ourChangeRef = React.useRef(false)

  React.useEffect(() => {
    if (!ourChangeRef.current) {
      isFirstRef.current = true
    }
    ourChangeRef.current = false
  }, [value])

  const displayWithUnit = React.useMemo(() => {
    const formatted =
      mode === "money" ? formatAmount(parseFloat(value) || 0, config) : value
    if (mode === "money") return `Gs${formatted}` // "Gs55.000"
    if (mode === "percent") return `${value}%` // "20%"
    return formatted // "1" o "1.5"
  }, [mode, value, config])

  const handleDigit = React.useCallback(
    (d: string) => {
      ourChangeRef.current = true
      if (isFirstRef.current) {
        isFirstRef.current = false
        onChange(d === "0" ? "0" : d)
      } else {
        onChange(appendDigit(value, d, mode))
      }
    },
    [value, onChange, mode],
  )

  const handleDot = React.useCallback(() => {
    if (!allowDot) return
    ourChangeRef.current = true
    onChange(appendDot(value))
  }, [allowDot, value, onChange])

  const handleBackspace = React.useCallback(() => {
    ourChangeRef.current = true
    onChange(backspace(value))
  }, [value, onChange])

  // Captura teclado físico mientras el pad está montado. No usamos autofocus
  // en un input oculto porque queremos que el foco quede libre para el Dialog.
  React.useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      // Si el foco está en un campo editable (textarea para notas en
      // cash-movement-dialog, inputs en otros dialogs), dejar pasar la tecla
      // al elemento — sino el numpad "se come" lo que el usuario tipea.
      const t = e.target as HTMLElement | null
      if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.isContentEditable)) {
        return
      }
      if (e.key >= "0" && e.key <= "9") {
        e.preventDefault()
        handleDigit(e.key)
      } else if (e.key === "." || e.key === ",") {
        e.preventDefault()
        handleDot()
      } else if (e.key === "Backspace" || e.key === "Delete") {
        e.preventDefault()
        handleBackspace()
      } else if (e.key === "Enter") {
        e.preventDefault()
        onConfirm()
      } else if (e.key === "Escape") {
        e.preventDefault()
        onCancel?.()
      } else if (e.key === "Shift") {
        // Shift sin combinación — toggle de modo (int<->decimal / money<->percent)
        e.preventDefault()
        onShiftToggle?.()
      }
    }

    window.addEventListener("keydown", onKeyDown)
    return () => window.removeEventListener("keydown", onKeyDown)
  }, [handleDigit, handleDot, handleBackspace, onConfirm, onCancel, onShiftToggle])

  return (
    <div className="flex flex-col gap-3">
      {/* Display con unidad inline */}
      <div className="flex flex-col items-center gap-3">
        <div className="h-20 flex items-center justify-center">
          <span className="text-5xl font-bold tabular-nums">{displayWithUnit}</span>
        </div>
        <p className="text-xs italic text-muted-foreground text-center">
          *Utilice las teclas del teclado{onShiftToggle ? " · Shift cambia el modo" : ""}
        </p>
      </div>

      {/* Grid 3x4 */}
      {showSoftKeyboard && (
        <div className="grid grid-cols-3 gap-2">
          {(["7", "8", "9", "4", "5", "6", "1", "2", "3"] as const).map((d) => (
            <Button
              key={d}
              type="button"
              variant="outline"
              className="h-12 text-xl"
              onClick={() => handleDigit(d)}
            >
              {d}
            </Button>
          ))}

          {/* Fila 4 */}
          <Button
            type="button"
            variant="outline"
            className="h-12 text-xl"
            disabled={!allowDot}
            onClick={handleDot}
          >
            .
          </Button>
          <Button
            type="button"
            variant="outline"
            className="h-12 text-xl"
            onClick={() => handleDigit("0")}
          >
            0
          </Button>
          <Button
            type="button"
            variant="outline"
            className="h-12"
            onClick={handleBackspace}
            aria-label="Borrar"
          >
            <Delete className="h-5 w-5" />
          </Button>
        </div>
      )}
    </div>
  )
}
