"use client"

import * as React from "react"
import { Ban } from "lucide-react"

import { cn } from "@/lib/utils"
import { PALETTE_COLORS, resolveColorBg } from "@/lib/ui/color-palette"

/**
 * Selector de color canónico del panel — 6 swatches circulares de la paleta
 * unificada (lib/ui/color-palette.ts). Usado en Hotkeys, Usuarios, Impresoras
 * y Medios de pago.
 *
 * Convención de valor: emite el `key` del color (ej. "amber") vía onChange.
 * `value` puede ser un key nuevo o un hex legacy; el swatch seleccionado se
 * resuelve comparando el hex efectivo (resolveColorBg), así los datos viejos
 * (hex) siguen marcándose como activos.
 *
 * Con `allowNone`, agrega una opción "sin color" que emite "" por onChange.
 */
export interface ColorPickerProps {
  value: string | null
  onChange: (key: string) => void
  /** Incluye una opción "sin color" (emite ""). */
  allowNone?: boolean
  className?: string
  /**
   * Variante visual. "default" = swatches size-7 con ring-foreground (forms del
   * panel). "overlay" = swatches size-3.5 con ring blanco, para el pill flotante
   * sobre un tile oscuro en el modo edición de Hotkeys.
   */
  variant?: "default" | "overlay"
  /** Se propaga el stopPropagation del click (útil dentro de tiles clickeables). */
  stopPropagation?: boolean
}

export function ColorPicker({
  value,
  onChange,
  allowNone,
  className,
  variant = "default",
  stopPropagation,
}: ColorPickerProps) {
  const selectedHex = resolveColorBg(value)
  const noneSelected = !selectedHex
  const overlay = variant === "overlay"

  const swatch = overlay ? "size-3.5" : "size-7"
  const selectedRing = overlay
    ? "ring-2 ring-white ring-offset-1 ring-offset-black/60"
    : "ring-2 ring-foreground ring-offset-2 ring-offset-background"
  const hover = overlay ? "hover:scale-125" : "hover:scale-110"

  const handle = (key: string) => (e: React.MouseEvent) => {
    if (stopPropagation) e.stopPropagation()
    onChange(key)
  }

  return (
    <div className={cn("flex items-center", overlay ? "gap-1" : "gap-2", className)}>
      {allowNone && (
        <button
          type="button"
          onClick={handle("")}
          aria-label="Sin color"
          className={cn(
            "flex items-center justify-center rounded-full border border-input text-muted-foreground transition-all",
            swatch,
            noneSelected ? selectedRing : hover,
          )}
        >
          <Ban className={overlay ? "size-2.5" : "size-3.5"} />
        </button>
      )}
      {PALETTE_COLORS.map((c) => {
        const selected = selectedHex === c.bg
        return (
          <button
            key={c.key}
            type="button"
            onClick={handle(c.key)}
            aria-label={`Color ${c.key}`}
            aria-pressed={selected}
            className={cn(
              "rounded-full transition-all",
              swatch,
              selected ? selectedRing : hover,
            )}
            style={{ backgroundColor: c.bg }}
          />
        )
      })}
    </div>
  )
}
