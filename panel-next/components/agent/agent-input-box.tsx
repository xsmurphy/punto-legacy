"use client"

import * as React from "react"
import { Plus, Mic, ArrowUp } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"

/**
 * Input box estilo ChatGPT reusable. Lo usan el thread (Sheet del FAB) y la
 * página `/chat` para mantener consistencia visual. Recibe el estado controlado
 * por el padre (input + setInput) para que el padre pueda inyectar texto
 * (ej. clicks en chips de sugerencias) sin romper la edición.
 */
export const AgentInputBox = React.forwardRef<
  HTMLTextAreaElement,
  {
    value: string
    onChange: (v: string) => void
    onSend: () => void
    disabled?: boolean
    placeholder?: string
    /** Permite estirar la altura máxima del textarea — en la página /chat tiene
     *  más aire (200px), en el Sheet conviene más chico (160px). */
    maxHeight?: number
  }
>(function AgentInputBox(
  { value, onChange, onSend, disabled, placeholder = "Preguntale al asistente…", maxHeight = 160 },
  ref,
) {
  const innerRef = React.useRef<HTMLTextAreaElement>(null)
  React.useImperativeHandle(ref, () => innerRef.current as HTMLTextAreaElement)

  function autoResize(el: HTMLTextAreaElement) {
    el.style.height = "auto"
    el.style.height = Math.min(el.scrollHeight, maxHeight) + "px"
  }

  // Auto-resize cuando el valor cambia desde afuera (ej. al pickear una sugerencia).
  React.useEffect(() => {
    if (innerRef.current) autoResize(innerRef.current)
  }, [value])

  return (
    <div className="rounded-3xl border bg-card shadow-sm transition-shadow focus-within:shadow-md">
      <Textarea
        ref={innerRef}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onInput={(e) => autoResize(e.currentTarget)}
        onKeyDown={(e) => {
          if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault()
            onSend()
          }
        }}
        placeholder={placeholder}
        disabled={disabled}
        rows={1}
        className="min-h-0 resize-none border-0 bg-transparent shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 px-4 pt-3 pb-1 text-base placeholder:text-muted-foreground/70"
      />
      <div className="flex items-center justify-between px-3 pb-2">
        <Tooltip>
          <TooltipTrigger asChild>
            <span tabIndex={0}>
              <Button
                variant="ghost"
                size="icon"
                disabled
                className="size-9 rounded-full text-muted-foreground/70 pointer-events-none"
              >
                <Plus className="size-4" />
              </Button>
            </span>
          </TooltipTrigger>
          <TooltipContent>Adjuntar (próximamente)</TooltipContent>
        </Tooltip>
        <div className="flex items-center gap-1">
          <Tooltip>
            <TooltipTrigger asChild>
              <span tabIndex={0}>
                <Button
                  variant="ghost"
                  size="icon"
                  disabled
                  className="size-9 rounded-full text-muted-foreground/70 pointer-events-none"
                >
                  <Mic className="size-4" />
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>Voz (próximamente)</TooltipContent>
          </Tooltip>
          <Button
            onClick={onSend}
            disabled={disabled || !value.trim()}
            size="icon"
            className="size-9 rounded-full bg-foreground text-background hover:bg-foreground/90 disabled:bg-muted disabled:text-muted-foreground"
            aria-label="Enviar"
          >
            <ArrowUp className="size-4" />
          </Button>
        </div>
      </div>
    </div>
  )
})
