"use client"

import * as React from "react"
import { Delete } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useCatalogStore } from "@/lib/catalog/store"
import { usePosUIStore } from "@/lib/ui/store"
import { useIsCoarsePointer } from "@/hooks/use-mobile"
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
  // En un dispositivo táctil (pointer: coarse) el pad en pantalla es la ÚNICA
  // forma de tipear — la captura de teclado físico de abajo no existe en un
  // smartphone. El toggle "Mostrar teclado virtual" queda como override para
  // FORZARLO en desktops (ej. all-in-one táctil que además tiene teclado);
  // en táctil no puede apagarlo: un numpad sin teclas es un modal roto.
  const coarsePointer = useIsCoarsePointer()
  const padVisible = showSoftKeyboard || coarsePointer
  const allowDot = mode !== "int"

  const isFirstRef = React.useRef(true)
  const ourChangeRef = React.useRef(false)
  const hiddenInputRef = React.useRef<HTMLInputElement>(null)

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
    if (isFirstRef.current) {
      // Igual que handleDigit: la primera pulsación reemplaza el valor
      // precargado (p.ej. la cantidad actual de la línea) en vez de
      // agregarse a él. Sin esto, tipear "." como primera tecla NO bajaba
      // isFirstRef — el próximo dígito seguía en "modo reemplazo" y pisaba
      // TODO el draft, incluido el punto recién tipeado (bug: no se podían
      // cargar decimales). Wrapper compartido por money/percent/decimal —
      // se arregla acá, no en cada caller (qty-edit-dialog, discount, etc).
      isFirstRef.current = false
      onChange("0.")
      return
    }
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

  // Par de modos alternables por `onShiftToggle`. El pad no conoce al caller:
  // deriva el par a partir del modo activo (money↔percent para descuentos,
  // int↔decimal para cantidades) y pinta cuál está activo.
  const modeChoices = React.useMemo(() => {
    if (mode === "money" || mode === "percent") {
      return [
        { key: "percent", label: "%" },
        { key: "money", label: config?.currency ?? "Gs" },
      ] as const
    }
    return [
      { key: "int", label: "1" },
      { key: "decimal", label: "1.00" },
    ] as const
  }, [mode, config])

  return (
    <div className="flex flex-col gap-3">
      {/* Selector de modo táctil. El toggle existía SOLO por la tecla física
          Shift — inalcanzable en un teléfono o tablet sin teclado (reporte del
          owner 2026-08-01). Bloque nuevo ARRIBA del display: no desplaza
          ninguna tecla del grid, y su presencia depende de `onShiftToggle`
          (constante por dialog), no de estado — sin shift condicional (§14 R10).
          No usa ToggleChip: ese chip es el patrón cerrado de la fila de
          atributos del carrito (text-[10px], py-0.5) y como target táctil de un
          pad numérico es demasiado chico. */}
      {onShiftToggle && (
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
        <div
          role="button"
          aria-label="Editar el monto con el teclado"
          onClick={() => hiddenInputRef.current?.focus()}
          className="h-20 flex cursor-text items-center justify-center"
        >
          <span className="text-5xl font-bold tabular-nums">{displayWithUnit}</span>
        </div>
        {/* Hint de teclado físico solo cuando NO hay pad en pantalla — en un
            táctil "utilice las teclas del teclado" es una instrucción imposible. */}
        {!padVisible && (
          <p className="text-xs italic text-muted-foreground text-center">
            *Utilice las teclas del teclado{onShiftToggle ? " · Shift cambia el modo" : ""}
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
