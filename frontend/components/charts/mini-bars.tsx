"use client"

import * as React from "react"

import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { cn } from "@/lib/utils"

/**
 * MiniBars — barras verticales chicas, sin ejes, sin grilla y sin números.
 *
 * Para bloques compactos (horas pico): barras grises con una etiqueta corta
 * debajo de cada una; el valor se lee en el tooltip, que abre al pasar el
 * mouse, al enfocar con teclado y al TOCAR en mobile (un tooltip de Radix no
 * abre con tap, por eso el `open` es controlado). Cada barra es un botón con
 * su `aria-label`: el gráfico se lee entero sin ver las alturas.
 *
 * `highlight` pinta una barra con el color del dato (`--chart-1`) — el pico,
 * lo que el bloque quiere que se mire. El valor llega YA formateado en
 * `detail` con los helpers del tenant: el componente solo sabe de proporciones.
 */
export interface MiniBarItem {
  key: string
  /** Etiqueta corta debajo de la barra ("8", "12"). */
  label: string
  /** Magnitud cruda para la altura. Negativos cuentan como 0. */
  value: number
  /** Lo que muestra el tooltip. */
  detail: React.ReactNode
  /** Lectura completa para lectores de pantalla. */
  ariaLabel: string
  highlight?: boolean
}

export function MiniBars({
  items,
  className,
  barsClassName = "h-14",
}: {
  items: MiniBarItem[]
  className?: string
  /** Altura del área de barras. */
  barsClassName?: string
}) {
  const [active, setActive] = React.useState<string | null>(null)
  const rootRef = React.useRef<HTMLDivElement>(null)
  const max = Math.max(0, ...items.map((i) => (Number.isFinite(i.value) ? i.value : 0)))

  // Un tap fuera del gráfico cierra el tooltip abierto por tap.
  React.useEffect(() => {
    if (active === null) return
    const onDown = (e: PointerEvent) => {
      if (!rootRef.current?.contains(e.target as Node)) setActive(null)
    }
    document.addEventListener("pointerdown", onDown)
    return () => document.removeEventListener("pointerdown", onDown)
  }, [active])

  return (
    <div ref={rootRef} role="group" className={cn("flex w-full items-end gap-1.5", className)}>
      {items.map((item) => {
        const v = Number.isFinite(item.value) ? Math.max(0, item.value) : 0
        const pct = max > 0 ? (v / max) * 100 : 0
        const isActive = active === item.key
        return (
          <div key={item.key} className="flex min-w-0 flex-1 flex-col items-center gap-1">
            <Tooltip open={isActive}>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  aria-label={item.ariaLabel}
                  className={cn("flex w-full items-end rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring", barsClassName)}
                  onPointerEnter={(e) => e.pointerType === "mouse" && setActive(item.key)}
                  onPointerLeave={(e) =>
                    e.pointerType === "mouse" && setActive((a) => (a === item.key ? null : a))
                  }
                  onFocus={() => setActive(item.key)}
                  onBlur={() => setActive((a) => (a === item.key ? null : a))}
                  onClick={() => setActive((a) => (a === item.key ? null : item.key))}
                >
                  <span
                    className={cn(
                      "block w-full rounded-sm transition-colors",
                      item.highlight
                        ? "bg-[var(--chart-1)]"
                        : isActive
                          ? "bg-muted-foreground/40"
                          : "bg-muted-foreground/20",
                    )}
                    // Una barra en cero se ve igual: 2px, para que la hora exista.
                    style={{ height: `max(2px, ${pct}%)` }}
                  />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top">{item.detail}</TooltipContent>
            </Tooltip>
            <span aria-hidden className="text-[10px] leading-none text-muted-foreground tabular-nums">
              {item.label}
            </span>
          </div>
        )
      })}
    </div>
  )
}
