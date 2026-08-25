"use client"

/**
 * NumericField — la ÚNICA superficie de captura numérica de los modales del POS.
 *
 * Decisión del owner (2026-08-25): en un TELÉFONO el pad en pantalla "no queda
 * bien" — ocupa media pantalla, compite con el teclado del sistema y es peor
 * que el nativo. En tablet y desktop el pad se queda EXACTAMENTE como estaba.
 *
 *   < 768px  → campo nativo, teclado del sistema.
 *   ≥ 768px  → <NumericPad> (cero cambios visibles).
 *
 * Por qué vive acá y no en cada dialog: los seis modales que capturan un número
 * (precio de línea, descuento de línea, descuento global, cantidad, apertura de
 * caja, movimiento de caja) ya comparten `NumericPad`. La rama de teléfono es
 * una propiedad de la SUPERFICIE de captura, no de cada modal — se resuelve una
 * vez acá y los call-sites solo cambian el import.
 *
 * El contrato de props es el de `NumericPad` a propósito: `value` sigue siendo
 * el mismo string ("0", "1.5", "35000") que los dialogs parsean con `Number()`
 * al confirmar, así que ninguna rama cambia la semántica del valor.
 *
 * El corte es `useIsMobile()` (768px) — el MISMO breakpoint con el que
 * `ResponsiveDialog` decide dialog vs bottom drawer, así que la rama nativa y
 * la rama drawer entran y salen juntas. Se hace en JS y no con `max-sm:`/`sm:`
 * porque montar las dos superficies a la vez duplicaría el input de captura
 * (dos targets de foco, dos entradas en el árbol de accesibilidad) y dejaría el
 * listener global de teclado del pad vivo debajo del campo visible.
 */

import * as React from "react"

import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { MoneyInput } from "@/components/ui/money-input"
import { useIsMobile } from "@/hooks/use-mobile"
import { useCatalogStore } from "@/lib/catalog/store"
import { resolveCurrencyLabel } from "@/lib/format-money"
import { cn } from "@/lib/utils"
import {
  NumericModeToggle,
  NumericPad,
  type NumericPadProps,
} from "@/components/pos/numeric-pad"

export type NumericFieldProps = NumericPadProps

export function NumericField(props: NumericFieldProps) {
  const isPhone = useIsMobile()
  if (!isPhone) return <NumericPad {...props} />
  return <NativeNumericField {...props} />
}

/**
 * Rama teléfono: un campo y el teclado del sistema.
 *
 * `autoFocus` en vez de un `focus()` diferido: el teclado tiene que subir solo
 * al abrir el modal, y Radix/vaul mueven el foco al contenido al montarlo — un
 * `focus()` nuestro en un `useEffect` competiría con ese traspaso.
 *
 * Enter / Escape se resuelven acá: sin el pad montado no existe el listener
 * global de `NumericPad`, y un teclado físico conectado a una tablet chica (o a
 * un teléfono en dock) tiene que poder confirmar igual.
 */
function NativeNumericField({
  mode,
  value,
  onChange,
  onShiftToggle,
  onConfirm,
  onCancel,
}: NumericFieldProps) {
  const config = useCatalogStore((s) => s.config)
  const fieldId = React.useId()
  const currencyLabel = resolveCurrencyLabel(config)

  // Mismo criterio que el modo `money` del pad: los decimales de la moneda del
  // tenant salen de su config, no del default del componente.
  const moneyDecimals = config?.decimal === "yes" ? 2 : 0

  const label =
    mode === "money"
      ? currencyLabel
        ? `Monto (${currencyLabel})`
        : "Monto"
      : mode === "percent"
        ? "Porcentaje (%)"
        : "Cantidad"

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Enter") {
      e.preventDefault()
      onConfirm()
    } else if (e.key === "Escape") {
      e.preventDefault()
      onCancel?.()
    }
  }

  // `h-14 text-xl`: override de tamaño con razón documentada (§14 R2). Este
  // campo reemplaza al visor de 5xl del pad y es el ÚNICO target táctil de la
  // captura en teléfono — el `h-8` del Input de formulario sería un retroceso
  // de área táctil en un POS touch-first. El tipo se mantiene ≥ 16px para que
  // iOS no haga zoom al enfocar.
  const inputClassName = "h-14 text-xl tabular-nums"

  return (
    <div className="flex flex-col gap-3">
      {/* Mismo bloque, misma posición que en el pad: el modo se elige arriba
          del campo y su presencia depende de `onShiftToggle` (constante por
          dialog), no de estado — sin desplazamiento condicional (§14 R10). */}
      {onShiftToggle && (
        <NumericModeToggle mode={mode} onShiftToggle={onShiftToggle} />
      )}

      <div className="flex flex-col gap-2">
        <Label htmlFor={fieldId}>{label}</Label>
        {mode === "money" ? (
          <MoneyInput
            id={fieldId}
            // El formato sale del catálogo offline del POS, no del bootstrap
            // del panel — ver el prop `format` en money-input.tsx.
            format={config ?? undefined}
            decimals={moneyDecimals}
            value={parseAmount(value)}
            onChange={(next) => onChange(next === null ? "0" : String(next))}
            onKeyDown={handleKeyDown}
            autoFocus
            className={inputClassName}
          />
        ) : (
          <Input
            id={fieldId}
            type="text"
            // `numeric` para enteros (el teclado no ofrece separador, que acá
            // no significa nada) y `decimal` para los modos fraccionables.
            inputMode={mode === "int" ? "numeric" : "decimal"}
            autoComplete="off"
            autoCorrect="off"
            autoCapitalize="off"
            spellCheck={false}
            value={value === "0" ? "" : value}
            placeholder="0"
            onChange={(e) => onChange(sanitize(e.target.value, mode) || "0")}
            onKeyDown={handleKeyDown}
            // Selección al enfocar: el pad reemplaza el valor precargado con la
            // primera tecla (`isFirstRef`). Sin esto, tipear "3" sobre una
            // cantidad de 1 daría "13" en vez de 3.
            onFocus={(e) => e.currentTarget.select()}
            autoFocus
            className={cn(inputClassName, "text-right")}
          />
        )}
      </div>
    </div>
  )
}

// ── helpers ──────────────────────────────────────────────────────────────────

function parseAmount(value: string): number | null {
  if (value === "") return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

/**
 * Deja pasar solo lo que el modo admite. `int` no acepta separador; `decimal` y
 * `percent` aceptan uno solo y normalizan la coma a punto (el valor viaja a los
 * dialogs como string para `Number()`, que no entiende comas).
 *
 * `percent` se corta en 3 caracteres, igual que el pad: entra "100" y entra
 * "9.5", pero no un porcentaje de cuatro dígitos.
 */
function sanitize(raw: string, mode: NumericFieldProps["mode"]): string {
  if (mode === "int") return raw.replace(/[^0-9]/g, "").slice(0, MAX_DIGITS)

  const cleaned = raw.replace(/,/g, ".").replace(/[^0-9.]/g, "")
  const [head, ...tail] = cleaned.split(".")
  const single = tail.length > 0 ? `${head}.${tail.join("")}` : head
  return mode === "percent" ? single.slice(0, 3) : single.slice(0, MAX_DIGITS + 1)
}

const MAX_DIGITS = 10
