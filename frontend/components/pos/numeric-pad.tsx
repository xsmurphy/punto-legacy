"use client"

import * as React from "react"
import { Delete } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useCatalogStore } from "@/lib/catalog/store"
import { usePosUIStore } from "@/lib/ui/store"
import { useIsCoarsePointer } from "@/hooks/use-mobile"
import { formatAmount, resolveCurrencyLabel } from "@/lib/format-money"

export interface NumericModeToggleProps {
  mode: NumericPadProps["mode"]
  onShiftToggle: () => void
}

/**
 * Selector de modo táctil (money↔percent para descuentos, int↔decimal para
 * cantidades). El toggle existía SOLO por la tecla física Shift —
 * inalcanzable en un teléfono o tablet sin teclado (reporte del owner
 * 2026-08-01).
 *
 * Se exporta aparte de `NumericPad` porque el modo es una decisión del dialog,
 * no del pad: `drawer-count-dialog` monta el toggle sobre su propia lista de
 * medios y el pad abajo. El pad lo renderiza igual, en la misma posición.
 *
 * No usa ToggleChip: ese chip es el patrón cerrado de la fila de atributos del
 * carrito (text-[10px], py-0.5) y como target táctil es demasiado chico.
 */
export function NumericModeToggle({ mode, onShiftToggle }: NumericModeToggleProps) {
  const config = useCatalogStore((s) => s.config)
  // `resolveCurrencyLabel` y no `config?.currency ?? "Gs"`: el bootstrap manda
  // "" cuando el tenant no configuró moneda y `??` no lo cubre — el botón salía
  // sin texto (bug reportado en el modal de descuento).
  const currencyLabel = resolveCurrencyLabel(config)

  // El pad no conoce al caller: deriva el par a partir del modo activo y pinta
  // cuál está activo.
  const modeChoices = React.useMemo(() => {
    if (mode === "money" || mode === "percent") {
      return [
        { key: "percent", label: "%" },
        { key: "money", label: currencyLabel },
      ] as const
    }
    return [
      { key: "int", label: "1" },
      { key: "decimal", label: "1.00" },
    ] as const
  }, [mode, currencyLabel])

  return (
    <div className="grid grid-cols-2 gap-2">
      {modeChoices.map((choice) => {
        const active = choice.key === mode
        return (
          <Button
            key={choice.key}
            type="button"
            variant={active ? "default" : "outline"}
            size="lg"
            aria-pressed={active}
            onClick={() => {
              if (!active) onShiftToggle()
            }}
          >
            {choice.label}
          </Button>
        )
      })}
    </div>
  )
}

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

// Entrada estilo calculadora (money/decimal): cada dígito entra por la
// derecha y empuja los anteriores — mismo algoritmo que
// formatMoneyAsYouType en components/ui/money-input.tsx (la convención
// canónica del proyecto para montos as-you-type). El pad literal
// (appendDigit + tecla ".") nunca lo implementó para estos modos: el punto
// requería tipearse a mano y "decimal" mode no shifteaba nada — bug
// reportado por el owner en el modal de cantidad (2026-08-03). Se arregla
// acá, en el wrapper compartido, no en cada caller.
const MAX_RAW_DIGITS = 10

function digitsOnly(value: string): string {
  return value.replace(/[^0-9]/g, "")
}

function formatShifted(raw: string, decimals: number): string {
  if (decimals <= 0) {
    const trimmed = raw.replace(/^0+(?=\d)/, "")
    return trimmed === "" ? "0" : trimmed
  }
  const padded = raw.padStart(decimals + 1, "0")
  const intPart = padded.slice(0, padded.length - decimals).replace(/^0+(?=\d)/, "") || "0"
  const decPart = padded.slice(-decimals)
  return `${intPart}.${decPart}`
}

function shiftDigit(current: string, digit: string, decimals: number, isFirst: boolean): string {
  const raw = isFirst ? "" : digitsOnly(current)
  const nextRaw = raw.length >= MAX_RAW_DIGITS ? raw : raw + digit
  return formatShifted(nextRaw, decimals)
}

function shiftBackspace(current: string, decimals: number): string {
  return formatShifted(digitsOnly(current).slice(0, -1), decimals)
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
  // Las teclas en pantalla se muestran SOLO si el toggle "Mostrar teclado
  // virtual en numpads" (Ajustes) está habilitado — el toggle MANDA, en
  // cualquier dispositivo (decisión del owner 2026-08-25; antes un heurístico
  // por pointer:coarse las forzaba en táctiles ignorando la config). Sin
  // teclas, el numpad no queda roto: el visor sigue capturando el teclado
  // físico, y en táctil tocarlo abre el teclado del OS (mismo gesto que el
  // PIN del lock screen).
  const coarsePointer = useIsCoarsePointer()
  const padVisible = showSoftKeyboard
  const allowDot = mode !== "int"
  // money/decimal usan entrada estilo calculadora (shift desde la derecha) —
  // el punto se posiciona solo, no se tipea. "decimal" (cantidades
  // fraccionables) fija 2 decimales; "money" respeta la config del tenant
  // (mismo criterio que formatMoneyAsYouType en money-input.tsx).
  const isCalcStyle = mode === "money" || mode === "decimal"
  const calcDecimals = mode === "money" ? (config?.decimal === "yes" ? 2 : 0) : mode === "decimal" ? 2 : 0

  const isFirstRef = React.useRef(true)
  const ourChangeRef = React.useRef(false)
  const hiddenInputRef = React.useRef<HTMLInputElement>(null)

  React.useEffect(() => {
    if (!ourChangeRef.current) {
      isFirstRef.current = true
    }
    ourChangeRef.current = false
  }, [value])

  // Etiqueta de la moneda del tenant — misma fuente que el botón de modo de
  // abajo y que `formatMoney`. Antes el visor hardcodeaba "Gs": un tenant con
  // otra moneda veía su monto rotulado en guaraníes.
  const currencyLabel = resolveCurrencyLabel(config)

  const displayWithUnit = React.useMemo(() => {
    const formatted =
      mode === "money" ? formatAmount(parseFloat(value) || 0, config) : value
    if (mode === "money") return `${currencyLabel}${formatted}` // "Gs55.000"
    if (mode === "percent") return `${value}%` // "20%"
    return formatted // "1" o "1.5"
  }, [mode, value, config, currencyLabel])

  const handleDigit = React.useCallback(
    (d: string) => {
      ourChangeRef.current = true
      if (isCalcStyle) {
        const isFirst = isFirstRef.current
        isFirstRef.current = false
        onChange(shiftDigit(value, d, calcDecimals, isFirst))
        return
      }
      if (isFirstRef.current) {
        isFirstRef.current = false
        onChange(d === "0" ? "0" : d)
      } else {
        onChange(appendDigit(value, d, mode))
      }
    },
    [value, onChange, mode, isCalcStyle, calcDecimals],
  )

  const handleDot = React.useCallback(() => {
    // money/decimal: el punto se posiciona solo (ver isCalcStyle) — la tecla
    // queda de adorno, ignorarla en vez de insertar un "." literal que
    // rompería formatShifted.
    if (!allowDot || isCalcStyle) return
    ourChangeRef.current = true
    if (isFirstRef.current) {
      // Igual que handleDigit: la primera pulsación reemplaza el valor
      // precargado (p.ej. la cantidad actual de la línea) en vez de
      // agregarse a él. Sin esto, tipear "." como primera tecla NO bajaba
      // isFirstRef — el próximo dígito seguía en "modo reemplazo" y pisaba
      // TODO el draft, incluido el punto recién tipeado (bug: no se podían
      // cargar decimales). Wrapper compartido por percent — se arregla acá,
      // no en cada caller.
      isFirstRef.current = false
      onChange("0.")
      return
    }
    onChange(appendDot(value))
  }, [allowDot, isCalcStyle, value, onChange])

  const handleBackspace = React.useCallback(() => {
    ourChangeRef.current = true
    if (isCalcStyle) {
      onChange(shiftBackspace(value, calcDecimals))
      return
    }
    onChange(backspace(value))
  }, [value, onChange, isCalcStyle, calcDecimals])

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
      {/* Selector de modo táctil — ver `NumericModeToggle` abajo. */}
      {onShiftToggle && (
        <NumericModeToggle mode={mode} onShiftToggle={onShiftToggle} />
      )}

      {/*
       * Input invisible — abre el teclado del OS en mobile cuando el usuario
       * toca el monto, igual que el PIN del lock-screen (pedido del owner
       * 2026-08-01). SUMA una vía de entrada: el pad en pantalla y el teclado
       * físico siguen intactos.
       *
       * `sr-only` y no display:none/hidden: un input realmente oculto no acepta
       * focus y el teclado virtual no sube. No se condiciona por viewport — en
       * desktop un input sr-only enfocado no molesta y evita una rama más.
       */}
      <input
        ref={hiddenInputRef}
        className="sr-only"
        // tabIndex=-1: Radix Dialog auto-enfoca el PRIMER elemento tabbable al
        // abrir, y este input era el primero — cada numpad abría con el
        // teclado del OS encima sin que nadie lo pidiera, e iOS scrolleaba el
        // documento entero para "revelar" el campo (la app quedaba corrida
        // hacia arriba aun después de cerrarse el teclado — reporte del owner
        // 2026-08-25). Fuera del orden de tabs, el teclado solo sube cuando el
        // cajero TOCA el visor (focus() programático funciona igual).
        tabIndex={-1}
        type="text"
        inputMode={allowDot ? "decimal" : "numeric"}
        pattern="[0-9]*"
        aria-label="Ingrese el monto con el teclado"
        autoComplete="off"
        autoCorrect="off"
        autoCapitalize="off"
        spellCheck={false}
        onChange={(e) => {
          // Los teclados virtuales no siempre disparan keydown: procesamos el
          // último carácter tipeado y vaciamos el input para el siguiente.
          const last = e.target.value.slice(-1)
          if (/^[0-9]$/.test(last)) {
            handleDigit(last)
          } else if ((last === "." || last === ",") && allowDot) {
            handleDot()
          }
          e.target.value = ""
        }}
        onKeyDown={(e) => {
          // Backspace con el input vacío: Android no siempre lo emite, por eso
          // la tecla de borrar del pad sigue siendo el camino garantizado.
          if (e.key === "Backspace") {
            e.preventDefault()
            handleBackspace()
            return
          }
          // El listener global ignora los eventos cuyo target es un INPUT (si
          // no, se comería lo que se tipea en otros campos del dialog). Con el
          // foco acá, Enter/Escape tienen que resolverse en el input o el pad
          // dejaría de confirmarse por teclado.
          if (e.key === "Enter") {
            e.preventDefault()
            onConfirm()
          } else if (e.key === "Escape") {
            e.preventDefault()
            onCancel?.()
          }
        }}
      />

      {/* Display con unidad inline */}
      <div className="flex flex-col items-center gap-3">
        {/* Sin `tabIndex`: es un atajo táctil, no un control nuevo. Enfocarlo
            por teclado no aportaría nada (el teclado físico ya escribe en el
            pad vía el listener global) y agregaría una parada de tab que
            colisiona con ese mismo listener en Enter/Espacio. */}
        {/* Alto y cuerpo del visor por breakpoint: en un teléfono el pad
            entero (toggle + visor + 4 filas de teclas) vive dentro del bottom
            drawer, y los 80px + `text-5xl` de la tablet empujaban las teclas
            de abajo contra el borde. `sm:` restituye el tamaño de siempre —
            tablet y desktop quedan idénticos. La captura NUNCA cambia de
            superficie (nada de input nativo en móvil): lo que se ajusta es el
            pad. */}
        <div
          role="button"
          aria-label="Editar el monto con el teclado"
          // Toggle, no solo focus: con el teclado del OS ya abierto, el segundo
          // toque sobre el monto lo BAJA — mismo gesto que el PIN del lock
          // screen. Sin el blur, el teclado tapaba el pad y no había forma de
          // sacarlo sin cerrar el modal.
          onClick={() => {
            const el = hiddenInputRef.current
            if (!el) return
            if (document.activeElement === el) el.blur()
            else el.focus()
          }}
          className="flex h-14 cursor-text items-center justify-center sm:h-20"
        >
          <span className="text-4xl font-bold tabular-nums sm:text-5xl">
            {displayWithUnit}
          </span>
        </div>
        {/* Hint solo cuando NO hay pad en pantalla, según el dispositivo: en
            táctil la vía es tocar el visor (teclado del OS); con teclado
            físico, tipear directo. */}
        {!padVisible && (
          <p className="text-xs italic text-muted-foreground text-center">
            {coarsePointer
              ? "*Tocá el monto para escribir"
              : `*Utilice las teclas del teclado${onShiftToggle ? " · Shift cambia el modo" : ""}`}
          </p>
        )}
      </div>

      {/* Grid 3x4 */}
      {padVisible && (
        <div className="grid grid-cols-3 gap-2">
          {(["7", "8", "9", "4", "5", "6", "1", "2", "3"] as const).map((d) => (
            <Button
              key={d}
              type="button"
              variant="outline"
              className="h-11 text-xl sm:h-12"
              onClick={() => handleDigit(d)}
            >
              {d}
            </Button>
          ))}

          {/* Fila 4 */}
          <Button
            type="button"
            variant="outline"
            className="h-11 text-xl sm:h-12"
            disabled={!allowDot || isCalcStyle}
            onClick={handleDot}
          >
            .
          </Button>
          <Button
            type="button"
            variant="outline"
            className="h-11 text-xl sm:h-12"
            onClick={() => handleDigit("0")}
          >
            0
          </Button>
          <Button
            type="button"
            variant="outline"
            className="h-11 sm:h-12"
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
