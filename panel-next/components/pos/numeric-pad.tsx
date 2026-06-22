"use client"

import * as React from "react"
import { Delete } from "lucide-react"
import { Button } from "@/components/ui/button"

export interface NumericPadProps {
  mode: "int" | "decimal" | "money" | "percent"
  value: string
  onChange: (v: string) => void
  onShiftToggle?: () => void
  onConfirm: () => void
  onCancel?: () => void
}

const MODE_LABEL: Record<NumericPadProps["mode"], string> = {
  int: "n",
  decimal: "n.n",
  money: "Gs",
  percent: "%",
}

function appendDigit(current: string, digit: string): string {
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
  const allowDot = mode !== "int"

  const handleDigit = React.useCallback(
    (d: string) => onChange(appendDigit(value, d)),
    [value, onChange],
  )

  const handleDot = React.useCallback(() => {
    if (!allowDot) return
    onChange(appendDot(value))
  }, [allowDot, value, onChange])

  const handleBackspace = React.useCallback(() => {
    onChange(backspace(value))
  }, [value, onChange])

  // Captura teclado físico mientras el pad está montado. No usamos autofocus
  // en un input oculto porque queremos que el foco quede libre para el Dialog.
  React.useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
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
      {/* Display */}
      <div className="relative flex h-16 items-center justify-center">
        <span className="text-4xl font-bold tabular-nums">{value}</span>
        <span className="absolute right-0 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">
          {MODE_LABEL[mode]}
        </span>
      </div>

      {/* Grid 3x4 */}
      <div className="grid grid-cols-3 gap-2">
        {(["7", "8", "9", "4", "5", "6", "1", "2", "3"] as const).map((d) => (
          <Button
            key={d}
            variant="outline"
            className="h-12 text-xl"
            onClick={() => handleDigit(d)}
          >
            {d}
          </Button>
        ))}

        {/* Fila 4 */}
        <Button
          variant="outline"
          className="h-12 text-xl"
          disabled={!allowDot}
          onClick={handleDot}
        >
          .
        </Button>
        <Button
          variant="outline"
          className="h-12 text-xl"
          onClick={() => handleDigit("0")}
        >
          0
        </Button>
        <Button
          variant="outline"
          className="h-12"
          onClick={handleBackspace}
          aria-label="Borrar"
        >
          <Delete className="h-5 w-5" />
        </Button>
      </div>
    </div>
  )
}
