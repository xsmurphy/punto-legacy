"use client"

/**
 * Campo compartido de chips de etiquetas — extraído del `TagsDialog` de
 * venta (sale-options-drawer.tsx) para que la Etiquetas por LÍNEA del
 * carrito (line-tags-dialog.tsx) tenga el mismo comportamiento exacto:
 * catálogo de sugerencias, cómo se agrega un chip (Enter/coma, click en
 * sugerencia) y cómo se quita (botón X, Backspace sobre campo vacío).
 *
 * Duplicar este picker en los dos lugares garantizaba que en tres meses se
 * comportaran distinto (mismo patrón de bug que ya costó caro en este repo:
 * dos definiciones del saldo de cuentas por cobrar, dos motores de
 * impuestos) — de ahí la extracción.
 *
 * Diseño: el campo mantiene su propio estado (chips + texto en edición) para
 * poder mostrar en vivo lo que el cajero está tipeando, pero NO llama a
 * ningún setter del store — eso es responsabilidad exclusiva del caller
 * (venta vs. línea escriben a lugares distintos). `flush()` (vía ref)
 * devuelve el valor final INCLUYENDO el texto tipeado y todavía no
 * confirmado con Enter/coma — mismo comportamiento que tenía el
 * `handleConfirm` original del TagsDialog de venta.
 */

import * as React from "react"
import { X } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"

export interface TagsChipsFieldHandle {
  /** Valor final: chips confirmados + el texto tipeado y no confirmado (si hay). */
  flush: () => string[]
}

interface TagsChipsFieldProps {
  /** Controla cuándo se re-siembra el estado interno desde `value` (al abrir el diálogo). */
  open: boolean
  value: string[]
  suggestions: string[]
  /** Escape dentro del input — el caller decide si eso cierra el diálogo. */
  onEscape?: () => void
  autoFocus?: boolean
}

export const TagsChipsField = React.forwardRef<TagsChipsFieldHandle, TagsChipsFieldProps>(
  function TagsChipsField({ open, value, suggestions, onEscape, autoFocus = true }, ref) {
    const [chips, setChips] = React.useState<string[]>(value)
    const [inputValue, setInputValue] = React.useState("")
    const inputRef = React.useRef<HTMLInputElement>(null)

    React.useEffect(() => {
      if (open) {
        setChips(value)
        setInputValue("")
      }
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, value])

    React.useImperativeHandle(ref, () => ({
      flush: () => {
        const pending = inputValue.trim()
        return pending && !chips.includes(pending) ? [...chips, pending] : chips
      },
    }), [chips, inputValue])

    const addChip = (raw: string) => {
      const val = raw.trim()
      if (!val) return
      if (!chips.includes(val)) {
        setChips((prev) => [...prev, val])
      }
      setInputValue("")
    }

    const removeChip = (chip: string) => {
      setChips((prev) => prev.filter((c) => c !== chip))
    }

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === "Enter" || e.key === ",") {
        e.preventDefault()
        addChip(inputValue)
      } else if (e.key === "Backspace" && inputValue === "") {
        setChips((prev) => prev.slice(0, -1))
      } else if (e.key === "Escape") {
        onEscape?.()
      }
    }

    const filteredSuggestions = inputValue.trim().length > 0
      ? suggestions.filter(
          (s) =>
            s.toLowerCase().includes(inputValue.toLowerCase()) &&
            !chips.includes(s),
        )
      : []

    return (
      <>
        <div
          className="flex min-h-[2.5rem] flex-wrap gap-1.5 rounded-md border border-input bg-background px-3 py-2 cursor-text"
          onClick={() => inputRef.current?.focus()}
        >
          {chips.map((chip) => (
            <Badge
              key={chip}
              variant="secondary"
              className="flex items-center gap-1 pr-1"
            >
              {chip}
              <button
                type="button"
                onClick={(e) => { e.stopPropagation(); removeChip(chip) }}
                aria-label={`Quitar ${chip}`}
                className="ml-0.5 flex size-3.5 items-center justify-center rounded-full hover:bg-muted-foreground/20"
              >
                <X className="size-2.5" />
              </button>
            </Badge>
          ))}
          <Input
            ref={inputRef}
            value={inputValue}
            onChange={(e) => setInputValue(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={chips.length === 0 ? "Escribí una etiqueta y presioná Enter..." : ""}
            autoFocus={autoFocus}
            className="h-auto min-w-[8rem] flex-1 border-0 bg-transparent p-0 text-sm shadow-none focus-visible:ring-0"
          />
        </div>

        {filteredSuggestions.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {filteredSuggestions.slice(0, 8).map((s) => (
              <button
                type="button"
                key={s}
                onClick={() => addChip(s)}
                className="rounded-full border border-border px-2.5 py-0.5 text-xs text-foreground transition-colors hover:bg-muted"
              >
                {s}
              </button>
            ))}
          </div>
        )}

        <p className="text-xs text-muted-foreground">
          Enter o coma para agregar. Backspace sobre campo vacío elimina la última.
        </p>
      </>
    )
  },
)
