"use client"

/**
 * Fecha de entrega de la orden en curso (context/79, F1) — el chip de la fila
 * de atributos del carrito, al lado del selector de fulfillment.
 *
 * POR QUÉ ACÁ Y NO EN UN PASO NUEVO
 * ---------------------------------
 * "Para el viernes" es un atributo de la orden igual que MOSTRADOR/RETIRO/
 * ENVÍO, así que vive en la misma fila y con la misma forma (`CHIP_BASE`, ver
 * `toggle-chip.tsx`): el cajero ya reconoce esa fila por forma. Un diálogo
 * previo al "Ordenar" agregaría un paso a la operación más repetida de la caja
 * para algo que la enorme mayoría de los pedidos no usa.
 *
 * La fila conserva su alto (`min-h-*` en `CartBottom`) y el chip está SIEMPRE
 * presente en modo orden-mostrador, con fecha o sin ella: la señal se pinta
 * sobre el mismo elemento en vez de aparecer y empujar el layout — Regla #10
 * de `context/14-ui-conventions.md`, memoria muscular del cajero.
 *
 * Sin hora, a propósito (D1): lo que el negocio compromete es el día. El tipo
 * de la columna admite hora para el día que un canal la mande, pero la caja no
 * la pide.
 */

import * as React from "react"

import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { CHIP_BASE } from "@/components/register/toggle-chip"
import { cn } from "@/lib/utils"

export function ScheduledForChip({
  value,
  onChange,
}: {
  /** Fecha elegida, `YYYY-MM-DD`. null = para ahora. */
  value: string | null
  onChange: (value: string | null) => void
}) {
  const [open, setOpen] = React.useState(false)
  const selected = parseISO(value)

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          aria-label={selected ? `Entrega ${chipLabel(selected)}` : "Elegir fecha de entrega"}
          className={cn(
            CHIP_BASE,
            selected
              ? "border-brand bg-brand/20 text-brand"
              : "border-border bg-transparent text-muted-foreground hover:border-muted-foreground",
          )}
        >
          {/* "FECHA" y no "ENTREGA" (owner 2026-09-17): al lado viven
              Mostrador/Retiro/Envío, que son formas de ENTREGA — el mismo
              sustantivo para la fecha se leía como un cuarto fulfillment. */}
          {selected ? chipLabel(selected) : "FECHA"}
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="end">
        <Calendar
          mode="single"
          selected={selected}
          onSelect={(d) => {
            onChange(d ? toISO(d) : null)
            setOpen(false)
          }}
          autoFocus
        />
        {/* Quitar la fecha es volver a "para ahora" — el estado por defecto,
            no una acción destructiva. Solo aparece cuando hay algo que quitar,
            y vive DENTRO del popover, así que no mueve nada de la caja.
            `size="lg"` porque el POS se opera con el dedo (§14 R2.2). */}
        {selected && (
          <div className="border-t border-border p-2">
            <Button
              variant="ghost"
              size="lg"
              className="w-full"
              onClick={() => {
                onChange(null)
                setOpen(false)
              }}
            >
              Sin fecha
            </Button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  )
}

/**
 * Etiqueta corta del chip ("19 SEP"). El año se omite mientras no aporta: la
 * fila tiene el ancho que tiene y un pedido se toma para los próximos días.
 *
 * Locale del ENTORNO (primer argumento `undefined`) — misma decisión y mismo
 * motivo que `components/date-picker.tsx`: este componente vive en la caja
 * (Bearer del device) y no puede leer la config del panel sin atarse a un
 * realm. El día que exista un provider de locale del tenant, este es uno de
 * los dos call-sites.
 */
function chipLabel(d: Date): string {
  return d
    .toLocaleDateString(undefined, { day: "2-digit", month: "short" })
    .replace(".", "")
    .toUpperCase()
}

/** "YYYY-MM-DD" → Date local. Constructor local para evitar el shift por TZ. */
function parseISO(s: string | null): Date | undefined {
  if (!s) return undefined
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (!m) return undefined
  return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
}

/** Date local → "YYYY-MM-DD" (sin TZ shift). */
function toISO(d: Date): string {
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const dd = String(d.getDate()).padStart(2, "0")
  return `${yyyy}-${mm}-${dd}`
}
