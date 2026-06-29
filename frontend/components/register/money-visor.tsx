"use client"

/**
 * MoneyVisor — visor de cobro borderless, número grande centrado, as-you-type
 * con separador de miles es-PY. Compartido entre PayDialog y CreditPaymentDialog.
 *
 * El input es visualmente un display: sin borde, sin fondo, caret oculto.
 * El placeholder formateado actúa como valor por defecto visible cuando el
 * cajero aún no tipeó nada.
 */

import * as React from "react"
import { cn } from "@/lib/utils"

// ── Helpers de display numérico ──────────────────────────────────────────────

/**
 * Extrae el valor numérico del string del visor (descarta separadores de miles).
 */
export function parseDisplay(s: string): number {
  if (!s) return 0
  const digits = s.replace(/\D/g, "")
  return digits ? Number(digits) : 0
}

/**
 * Formatea el raw string del visor como número con separador de miles (PY: punto).
 * Solo opera sobre los dígitos — descarta cualquier otro carácter previo.
 */
export function formatDisplayInput(raw: string): string {
  const digits = raw.replace(/\D/g, "")
  if (!digits) return ""
  return Number(digits).toLocaleString("es-PY")
}

// ── Props ────────────────────────────────────────────────────────────────────

interface MoneyVisorProps {
  /** String de display (ya formateado con separadores de miles). */
  value: string
  /**
   * Callback con el raw numérico parseado cada vez que cambia.
   * Si el cajero borra todo, emite 0.
   */
  onValueChange: (raw: number) => void
  /** Placeholder formateado que muestra cuando value está vacío. */
  placeholder?: string
  /** Autofocus al montar. */
  autoFocus?: boolean
  /** aria-label para accesibilidad. */
  ariaLabel?: string
  className?: string
  onKeyDown?: (e: React.KeyboardEvent<HTMLInputElement>) => void
}

export const MoneyVisor = React.forwardRef<HTMLInputElement, MoneyVisorProps>(
  function MoneyVisor(
    { value, onValueChange, placeholder, autoFocus, ariaLabel, className, onKeyDown },
    ref,
  ) {
    function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
      const formatted = formatDisplayInput(e.target.value)
      onValueChange(parseDisplay(formatted))
      // Notificamos el raw pero para sincronizar el display string en el padre
      // usamos el mismo formatDisplayInput. El padre debe guardar el string, no el raw.
      // Por eso exponemos también formatDisplayInput como export helper.
    }

    return (
      <input
        ref={ref}
        type="text"
        inputMode="numeric"
        value={value}
        onChange={handleChange}
        onKeyDown={onKeyDown}
        placeholder={placeholder}
        autoFocus={autoFocus}
        className={cn(
          "w-full bg-transparent text-center tabular-nums outline-none caret-transparent",
          "text-5xl font-black text-foreground",
          // Placeholder con mismo estilo que el texto tipeado — visualmente un solo visor
          "placeholder:text-foreground",
          className,
        )}
        aria-label={ariaLabel ?? "Monto"}
      />
    )
  },
)
