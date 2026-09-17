"use client"

/**
 * Fecha (y hora) de entrega de la orden en curso (context/79, F1) — el chip de
 * la fila de atributos del carrito, al lado del selector de fulfillment.
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
 * CON HORA desde 2026-09-17 (owner — revisa la D1 de context/79): los pedidos
 * se agrupan por FRANJA, no solo por día — "no es lo mismo pedidos para el
 * medio día que para la cena". Por eso el popover pasó a ser un MODAL centrado
 * con calendario + hora. La hora es opcional: sin hora = "en el día", y el
 * backend la normaliza a 00:00 (por eso 00:00 se trata como "sin hora" al
 * abrir el modal de nuevo).
 *
 * Formato del value: `YYYY-MM-DD` (sin hora) o `YYYY-MM-DD HH:mm` — lo que
 * `OrderCoreService::normalizeScheduledFor` acepta tal cual, en hora del
 * comercio (naive, la convención de storage del proyecto).
 */

import * as React from "react"

import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { CHIP_BASE } from "@/components/register/toggle-chip"
import { cn } from "@/lib/utils"

export function ScheduledForChip({
  value,
  onChange,
}: {
  /** `YYYY-MM-DD` o `YYYY-MM-DD HH:mm`. null = para ahora. */
  value: string | null
  onChange: (value: string | null) => void
}) {
  const [open, setOpen] = React.useState(false)
  // Borrador local del modal: fecha y hora se eligen por separado y recién
  // "Listo" los confirma — cerrar con la X o tocar afuera no cambia la orden.
  const [draftDate, setDraftDate] = React.useState<Date | undefined>(undefined)
  const [draftTime, setDraftTime] = React.useState("")

  const committed = parseValue(value)

  function openModal() {
    setDraftDate(committed?.date)
    setDraftTime(committed?.time ?? "")
    setOpen(true)
  }

  function confirm() {
    if (!draftDate) return
    onChange(toValue(draftDate, draftTime))
    setOpen(false)
  }

  return (
    <>
      <button
        aria-label={committed ? `Entrega ${chipLabel(committed)}` : "Elegir fecha y hora de entrega"}
        onClick={openModal}
        className={cn(
          CHIP_BASE,
          committed
            ? "border-brand bg-brand/20 text-brand"
            : "border-border bg-transparent text-muted-foreground hover:border-muted-foreground",
        )}
      >
        {/* "FECHA" y no "ENTREGA" (owner 2026-09-17): al lado viven
            Mostrador/Retiro/Envío, que son formas de ENTREGA — el mismo
            sustantivo para la fecha se leía como un cuarto fulfillment. */}
        {committed ? chipLabel(committed) : "FECHA"}
      </button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Fecha de entrega</DialogTitle>
          </DialogHeader>

          <div className="flex flex-col items-center gap-4">
            <Calendar
              mode="single"
              selected={draftDate}
              onSelect={(d) => setDraftDate(d ?? undefined)}
              autoFocus
            />
            <div className="flex w-full items-center gap-3">
              <Label htmlFor="scheduled-time" className="shrink-0">
                Hora
              </Label>
              {/* Nativo type="time" sobre el Input de shadcn: teclado numérico
                  en tablet y rueda en móvil, sin widget propio. Vacío = en el
                  día, sin franja. */}
              <Input
                id="scheduled-time"
                type="time"
                value={draftTime}
                onChange={(e) => setDraftTime(e.target.value)}
                className="h-11"
              />
              {draftTime !== "" && (
                <Button variant="ghost" size="sm" onClick={() => setDraftTime("")}>
                  Sin hora
                </Button>
              )}
            </div>
          </div>

          <DialogFooter className="gap-2 sm:gap-2">
            {/* Quitar la fecha es volver a "para ahora" — el estado por
                defecto, no una acción destructiva. Solo aparece cuando hay
                algo que quitar. `size="lg"`: el POS se opera con el dedo. */}
            {committed && (
              <Button
                variant="ghost"
                size="lg"
                onClick={() => {
                  onChange(null)
                  setOpen(false)
                }}
              >
                Sin fecha
              </Button>
            )}
            <Button size="lg" disabled={!draftDate} onClick={confirm}>
              Listo
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}

interface ScheduledParts {
  date: Date
  /** "HH:mm", o "" cuando el pedido es para el día sin franja. */
  time: string
}

/**
 * Etiqueta corta del chip ("19 SEP" / "19 SEP 12:00"). El año se omite
 * mientras no aporta: la fila tiene el ancho que tiene y un pedido se toma
 * para los próximos días.
 *
 * Locale del ENTORNO (primer argumento `undefined`) — misma decisión y mismo
 * motivo que `components/date-picker.tsx`: este componente vive en la caja
 * (Bearer del device) y no puede leer la config del panel sin atarse a un
 * realm. El día que exista un provider de locale del tenant, este es uno de
 * los dos call-sites.
 */
function chipLabel({ date, time }: ScheduledParts): string {
  const day = date
    .toLocaleDateString(undefined, { day: "2-digit", month: "short" })
    .replace(".", "")
    .toUpperCase()
  return time === "" ? day : `${day} ${time}`
}

/**
 * `YYYY-MM-DD[ HH:mm]` → partes locales. Constructor local para evitar el
 * shift por TZ. `00:00` cuenta como "sin hora": es lo que el backend escribe
 * cuando la orden se tomó sin franja, y ofrecer "a medianoche" como hora de
 * entrega de un pedido de comida confunde más de lo que informa.
 */
function parseValue(s: string | null): ScheduledParts | null {
  if (!s) return null
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/)
  if (!m) return null
  const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
  const time = m[4] && `${m[4]}:${m[5]}` !== "00:00" ? `${m[4]}:${m[5]}` : ""
  return { date, time }
}

/** Partes → `YYYY-MM-DD` o `YYYY-MM-DD HH:mm` (naive, sin TZ shift). */
function toValue(d: Date, time: string): string {
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const dd = String(d.getDate()).padStart(2, "0")
  const day = `${yyyy}-${mm}-${dd}`
  return time === "" ? day : `${day} ${time}`
}
