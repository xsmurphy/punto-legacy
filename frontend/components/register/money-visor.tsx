"use client"

/**
 * MoneyVisor — visor de cobro borderless, número grande centrado, as-you-type
 * con el separador de miles del TENANT. Compartido entre PayDialog y
 * CreditPaymentDialog.
 *
 * El input es visualmente un display: sin borde, sin fondo, caret oculto.
 * El placeholder formateado actúa como valor por defecto visible cuando el
 * cajero aún no tipeó nada.
 */

import * as React from "react"
import { cn } from "@/lib/utils"
import { resolveNumberLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"

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
 * Formatea el raw string del visor agrupando los miles como los agrupa el
 * tenant. Solo opera sobre los dígitos — descarta cualquier otro carácter.
 *
 * Agrupación y NADA más: el visor captura dígitos enteros, no fracción, así
 * que no puede pasar por `formatAmount`. Ese helper aplica los decimales del
 * tenant, y con `decimal: yes` agregaría ",00" en cada tecla — el monto
 * tipeado se correría un orden de magnitud por pulsación. `resolveNumberLocale`
 * resuelve la única dimensión que el visor necesita, el separador de miles,
 * que antes estaba clavado en "es-PY" e ignoraba el ajuste `thousand`.
 *
 * (Entrada de centavos en el visor = cambio aparte: hoy `parseDisplay` lee los
 * dígitos como unidades enteras, así que un tenant con decimales no puede
 * tipear fracción. Ver `<MoneyInput>`, que sí interpreta minor units.)
 */
export function formatDisplayInput(
  raw: string,
  config: TenantLocaleConfig | null | undefined,
): string {
  const digits = raw.replace(/\D/g, "")
  if (!digits) return ""
  return Number(digits).toLocaleString(resolveNumberLocale(config))
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
  /**
   * Config de locale del tenant — de dónde sale el separador de miles del
   * display. Se inyecta (no se lee de un store) porque el visor se usa desde
   * el POS y desde el panel, cada uno con su propio origen de bootstrap.
   */
  config: TenantLocaleConfig | null | undefined
  /** Autofocus al montar. */
  autoFocus?: boolean
  /** aria-label para accesibilidad. */
  ariaLabel?: string
  className?: string
  onKeyDown?: (e: React.KeyboardEvent<HTMLInputElement>) => void
}

export const MoneyVisor = React.forwardRef<HTMLInputElement, MoneyVisorProps>(
  function MoneyVisor(
    { value, onValueChange, placeholder, config, autoFocus, ariaLabel, className, onKeyDown },
    ref,
  ) {
    function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
      const formatted = formatDisplayInput(e.target.value, config)
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
