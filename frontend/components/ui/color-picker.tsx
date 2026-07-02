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
  /** Tamaño del swatch. Default 7 (size-7). */
  className?: string
}

export function ColorPicker({ value, onChange, allowNone, className }: ColorPickerProps) {
  const selectedHex = resolveColorBg(value)
  const noneSelected = !selectedHex

  return (
    <div className={cn("flex items-center gap-2", className)}>
      {allowNone && (
        <button
          type="button"
          onClick={() => onChange("")}
          aria-label="Sin color"
          className={cn(
            "flex size-7 items-center justify-center rounded-full border border-input text-muted-foreground transition-all",
            noneSelected
              ? "ring-2 ring-foreground ring-offset-2 ring-offset-background"
              : "hover:scale-110",
          )}
        >
          <Ban className="size-3.5" />
        </button>
      )}
      {PALETTE_COLORS.map((c) => {
        const selected = selectedHex === c.bg
        return (
          <button
            key={c.key}
            type="button"
            onClick={() => onChange(c.key)}
            aria-label={`Color ${c.key}`}
            aria-pressed={selected}
            className={cn(
              "size-7 rounded-full transition-all",
              selected
                ? "ring-2 ring-foreground ring-offset-2 ring-offset-background"
                : "hover:scale-110",
            )}
            style={{ backgroundColor: c.bg }}
          />
        )
      })}
    </div>
  )
}
