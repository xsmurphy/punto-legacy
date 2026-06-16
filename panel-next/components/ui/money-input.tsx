"use client"

import * as React from "react"
import { Input } from "@/components/ui/input"
import { useBootstrap } from "@/hooks/use-bootstrap"
import type { Bootstrap } from "@/lib/types/bootstrap"
import { cn } from "@/lib/utils"

/**
 * Input de moneda con formato as-you-type estilo calculadora.
 *
 * - Lee la config del tenant del bootstrap: thousand (',' | '.') y decimal
 *   ('yes' = 2 dígitos | 'no' = 0). Cada país tiene su default; el usuario
 *   no necesita pensar en el formato — el input se encarga.
 * - As-you-type: el usuario solo escribe dígitos. La parte entera y los
 *   decimales se separan por el divisor decimal (',' o '.') automáticamente
 *   desde la DERECHA (modo calculadora). Tipear '1' '2' '0' '0' '0' con
 *   decimales encendido + thousand='.' produce: 1 → 12 → 1,20 → 12,00 → 120,00
 * - Backend siempre recibe un número crudo (Number en JS, decimal en SQL).
 *
 * Props:
 *   value: número o null (null = vacío)
 *   onChange: callback con el número parseado (o null)
 *   maxIntDigits: tope opcional del entero (default 12 = trillones)
 */
interface MoneyInputProps
  extends Omit<React.ComponentProps<typeof Input>, "value" | "onChange" | "type"> {
  value: number | null
  onChange: (next: number | null) => void
  /** Cantidad máxima de dígitos en la parte entera. Default 12. */
  maxIntDigits?: number
}

export function MoneyInput({
  value,
  onChange,
  maxIntDigits = 12,
  className,
  onFocus,
  onBlur,
  ...rest
}: MoneyInputProps) {
  const { data: bootstrap } = useBootstrap()
  const fmt = getFormatConfig(bootstrap)

  // Internamente trabajamos con un string de SOLO dígitos (sin separadores).
  // Cuando llegamos a 'decimals' dígitos finales, esos son la fracción;
  // el resto es la parte entera. Esto es lo que da el comportamiento de
  // calculadora ('20000' con decimales=2 + dot-thousand → '200,00').
  const formatted = formatMoneyAsYouType(value, fmt, maxIntDigits)

  // Focus/blur behavior pedido por el owner:
  // - Al focus, el contenido se "limpia" (display vacío) para que el usuario
  //   pueda tipear el nuevo valor sin tener que seleccionar/borrar el anterior.
  //   En el modelo NO commiteamos el null — guardamos el original en un ref
  //   por si el usuario hace blur sin escribir nada.
  // - Si en blur el contenido sigue vacío (no tocó nada o borró todo sin
  //   reescribir) y el valor original NO era null, restauramos el original.
  //   Si el usuario tipeó algo, queda lo que tipeó.
  const [focused, setFocused] = React.useState(false)
  const [draft, setDraft] = React.useState("")
  const valueAtFocusRef = React.useRef<number | null>(value)

  // Si el usuario está tipeando (hay dígitos en el draft), mostramos el valor
  // formateado (20.000) en lugar del draft crudo (20000). Sin dígitos → vacío.
  const hasDraftInput = (draft.match(/\d/g) ?? []).length > 0
  const display = focused ? (hasDraftInput ? formatted : "") : formatted

  return (
    <Input
      {...rest}
      type="text"
      inputMode="numeric"
      autoComplete="off"
      value={display}
      onFocus={(e) => {
        valueAtFocusRef.current = value
        setDraft("")
        setFocused(true)
        // No commiteamos `null` — el modelo conserva el valor previo hasta
        // que el usuario tipee algo (onChange) o haga blur sin tipear.
        onFocus?.(e)
      }}
      onBlur={(e) => {
        setFocused(false)
        // Si el draft está vacío (no tipeó nada) y el valor original era
        // distinto de null, restaurar — para que "click + click afuera" no
        // borre el monto que ya estaba.
        const noInput = (draft.match(/\d/g) ?? []).length === 0
        if (noInput && valueAtFocusRef.current !== null && value === null) {
          onChange(valueAtFocusRef.current)
        }
        setDraft("")
        onBlur?.(e)
      }}
      onChange={(e) => {
        setDraft(e.target.value)
        const digits = (e.target.value.match(/\d/g) ?? []).join("")
        const trimmed = digits.slice(0, maxIntDigits + fmt.decimals)
        if (trimmed === "") {
          onChange(null)
          return
        }
        // Convertir string-de-dígitos a número: si decimals > 0, los últimos
        // N dígitos son la fracción. Padding a la izquierda si todavía no
        // hay suficientes (ej '5' con decimals=2 → 0.05).
        const padded = trimmed.padStart(fmt.decimals + 1, "0")
        const intPart = padded.slice(0, padded.length - fmt.decimals) || "0"
        const decPart = fmt.decimals > 0 ? padded.slice(-fmt.decimals) : ""
        const n = decPart === ""
          ? Number(intPart)
          : Number(`${intPart}.${decPart}`)
        onChange(Number.isFinite(n) ? n : null)
      }}
      className={cn("tabular-nums text-right", className)}
    />
  )
}

// ── helpers ──────────────────────────────────────────────────────────────

interface FmtConfig {
  decimals: number       // 0 o 2
  thousand: string       // ',' | '.'
  decimal: string        // separador decimal opuesto al thousand
}

function getFormatConfig(
  bootstrap: Pick<Bootstrap, "thousand" | "decimal"> | undefined,
): FmtConfig {
  // bootstrap.thousand es 'comma' o 'dot'. Si comma → 1,234.56 (anglo).
  // Si dot → 1.234,56 (es-PY/europeo).
  const thousand = bootstrap?.thousand === "comma" ? "," : "."
  const decimal = thousand === "," ? "." : ","
  const decimals = bootstrap?.decimal === "yes" ? 2 : 0
  return { decimals, thousand, decimal }
}

function formatMoneyAsYouType(
  value: number | null,
  fmt: FmtConfig,
  maxIntDigits: number,
): string {
  if (value === null || value === undefined) return ""
  // Convertir el número a string-de-dígitos (sin separadores ni signo).
  // Multiplicamos por 10^decimals para evitar drift de punto flotante.
  const scaled = Math.round((value || 0) * Math.pow(10, fmt.decimals))
  const sign = scaled < 0 ? "-" : ""
  const abs = Math.abs(scaled).toString()
  const padded = abs.padStart(fmt.decimals + 1, "0")
  const intPart = padded.slice(0, padded.length - fmt.decimals) || "0"
  const decPart = fmt.decimals > 0 ? padded.slice(-fmt.decimals) : ""

  // Aplicar separador de miles a la parte entera.
  const trimmedInt = intPart.slice(0, maxIntDigits)
  const withThousand = trimmedInt.replace(/\B(?=(\d{3})+(?!\d))/g, fmt.thousand)

  return decPart === ""
    ? `${sign}${withThousand}`
    : `${sign}${withThousand}${fmt.decimal}${decPart}`
}
