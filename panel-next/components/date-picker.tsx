"use client"

import * as React from "react"
import { Calendar as CalendarIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { cn } from "@/lib/utils"

interface Props {
  /** Fecha en formato ISO "YYYY-MM-DD" o vacío. */
  value: string
  onChange: (value: string) => void
  placeholder?: string
  className?: string
  id?: string
  disabled?: boolean
}

/**
 * Date picker single — Calendar shadcn dentro de Popover. Reemplazo del
 * `<input type="date">` nativo para mantener look & feel consistente con
 * el resto del panel y evitar el UI nativo del browser que varía entre
 * sistemas. Trabaja con strings "YYYY-MM-DD" para compat directo con
 * <input type="date"> que ya está en uso (form submits, backend).
 */
export function DatePicker({
  value,
  onChange,
  placeholder = "Seleccionar fecha",
  className,
  id,
  disabled,
}: Props) {
  const [open, setOpen] = React.useState(false)

  const selected = parseISO(value)
  const label = selected ? formatLabel(selected) : ""

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          disabled={disabled}
          className={cn(
            "w-full justify-start font-normal",
            !selected && "text-muted-foreground",
            className,
          )}
        >
          <CalendarIcon className="mr-2 size-4" />
          {label || placeholder}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="single"
          selected={selected}
          onSelect={(d) => {
            onChange(d ? toISO(d) : "")
            setOpen(false)
          }}
          autoFocus
        />
      </PopoverContent>
    </Popover>
  )
}

/** "YYYY-MM-DD" → Date local. Si no parsea o viene vacío, undefined. */
function parseISO(s: string): Date | undefined {
  if (!s) return undefined
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (!m) return undefined
  // new Date(y, m-1, d) — constructor local evita el shift por TZ que tiene
  // new Date("YYYY-MM-DD") (que lo interpreta como UTC midnight y se nos
  // cambia a un día antes en zonas con offset negativo).
  return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
}

/** Date local → "YYYY-MM-DD" (sin TZ shift). */
function toISO(d: Date): string {
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const dd = String(d.getDate()).padStart(2, "0")
  return `${yyyy}-${mm}-${dd}`
}

/** Label legible "DD MMM YYYY" en español, para el trigger del picker. */
function formatLabel(d: Date): string {
  return d.toLocaleDateString("es-PY", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  })
}
