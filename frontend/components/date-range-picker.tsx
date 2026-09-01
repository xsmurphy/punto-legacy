"use client"

import * as React from "react"
import { Calendar as CalendarIcon } from "lucide-react"
import type { DateRange } from "react-day-picker"

import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { Separator } from "@/components/ui/separator"
import { cn } from "@/lib/utils"

export interface DateRangeValue {
  from: Date
  to: Date
}

interface Props {
  value: DateRangeValue
  onChange: (range: DateRangeValue) => void
  className?: string
}

const PRESETS = [
  { label: "Hoy", days: 0 },
  { label: "Últimos 7 días", days: 7 },
  { label: "Últimos 14 días", days: 14 },
  { label: "Últimos 30 días", days: 30 },
  { label: "Últimos 90 días", days: 90 },
  { label: "Este mes", days: -1 }, // sentinel
] as const

export function DateRangePicker({ value, onChange, className }: Props) {
  const [open, setOpen] = React.useState(false)
  const [draft, setDraft] = React.useState<DateRange | undefined>({
    from: value.from,
    to: value.to,
  })

  React.useEffect(() => {
    setDraft({ from: value.from, to: value.to })
  }, [value.from, value.to])

  const applyPreset = (preset: (typeof PRESETS)[number]) => {
    const now = new Date()
    let from: Date
    let to: Date = now
    if (preset.days === -1) {
      // Este mes
      from = new Date(now.getFullYear(), now.getMonth(), 1)
    } else if (preset.days === 0) {
      from = new Date(now.getFullYear(), now.getMonth(), now.getDate())
      to = now
    } else {
      from = new Date(now.getTime() - preset.days * 24 * 60 * 60 * 1000)
    }
    const range = { from, to }
    setDraft(range)
    onChange(range)
    setOpen(false)
  }

  const apply = () => {
    if (draft?.from && draft?.to) {
      onChange({ from: draft.from, to: draft.to })
      setOpen(false)
    }
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          className={cn("h-9 gap-2 font-normal", className)}
        >
          <CalendarIcon className="size-3.5 text-muted-foreground" />
          <span className="tabular-nums">
            {fmt(value.from)} – {fmt(value.to)}
          </span>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="end">
        <div className="flex">
          <div className="flex flex-col gap-1 border-r p-2">
            {PRESETS.map((p) => (
              <Button
                key={p.label}
                variant="ghost"
                size="sm"
                className="h-8 justify-start text-xs"
                onClick={() => applyPreset(p)}
              >
                {p.label}
              </Button>
            ))}
          </div>
          <div className="flex flex-col">
            <Calendar
              mode="range"
              numberOfMonths={2}
              defaultMonth={draft?.from ?? value.from}
              selected={draft}
              onSelect={setDraft}
            />
            <Separator />
            <div className="flex items-center justify-between gap-2 p-2">
              <span className="text-xs text-muted-foreground tabular-nums">
                {draft?.from ? fmt(draft.from) : "—"} –{" "}
                {draft?.to ? fmt(draft.to) : "—"}
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8"
                  onClick={() => setOpen(false)}
                >
                  Cancelar
                </Button>
                <Button
                  size="sm"
                  className="h-8"
                  disabled={!draft?.from || !draft?.to}
                  onClick={apply}
                >
                  Aplicar
                </Button>
              </div>
            </div>
          </div>
        </div>
      </PopoverContent>
    </Popover>
  )
}

function fmt(d: Date): string {
  const dd = String(d.getDate()).padStart(2, "0")
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const yyyy = d.getFullYear()
  return `${dd}/${mm}/${yyyy}`
}

/** Default: últimos 7 días — espejo del legacy. */
export function defaultDateRange(): DateRangeValue {
  const to = new Date()
  const from = new Date(to.getTime() - 7 * 24 * 60 * 60 * 1000)
  return { from, to }
}

/**
 * Ultimo instante representable de un dia. Espejo de `Date::END_OF_DAY` en
 * `api/lib/App/Helpers/Date.php` — ver ahi el porque completo.
 *
 * Resumen: las columnas de fecha son `timestamptz` (microsegundos), asi que un
 * tope en `23:59:59` pierde todo lo que caiga en el ultimo segundo del dia. Una
 * venta a las 23:59:59.5 desaparecia del reporte del dia sin explicacion.
 *
 * El backend ya normaliza una fecha SOLA (`2026-09-01`) al final del dia, pero
 * respeta verbatim la hora explicita — y este selector manda la hora explicita.
 * Mientras siga haciendolo, la precision tiene que viajar desde aca. La limpieza
 * de fondo (mandar solo `YYYY-MM-DD` y que el backend resuelva) esta pendiente:
 * requiere migrar antes los endpoints de finanzas que leen `$_GET` crudo sin
 * pasar por el helper (`movements.php`, `checks.php`, `forecast.php`), porque
 * con una fecha sola volverian a cortar en la medianoche.
 */
const END_OF_DAY = "23:59:59.999999"

/** Convierte el rango a strings YYYY-MM-DD HH:mm:ss para enviar al backend. */
export function rangeToBackend(r: DateRangeValue): { from: string; to: string } {
  const pad = (n: number) => String(n).padStart(2, "0")
  const f = r.from
  const t = r.to
  const from = `${f.getFullYear()}-${pad(f.getMonth() + 1)}-${pad(f.getDate())} 00:00:00`
  const to = `${t.getFullYear()}-${pad(t.getMonth() + 1)}-${pad(t.getDate())} ${END_OF_DAY}`
  return { from, to }
}
