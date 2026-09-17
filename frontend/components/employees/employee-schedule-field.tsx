"use client"

/**
 * Horario declarado del legajo (context/83 F1).
 *
 * Es la ÚNICA base para medir tardanzas: sin esto, el reporte informa horas
 * trabajadas y se calla sobre la puntualidad. No se completa con un horario
 * estándar por default a propósito — acusar a alguien de llegar tarde contra
 * una regla que nadie escribió es peor que no decir nada.
 *
 * ── Una fila por día, y el día se prende o se apaga ────────────────────────
 *
 * Un día apagado es un día NO laborable, y esa es toda la semántica: no hay
 * "sin definir" además de "no trabaja". La hora de SALIDA es opcional porque no
 * participa del cálculo (las horas salen del apareo real de marcaciones, no del
 * horario) — está para que el comercio pueda dejar escrito el turno completo.
 */

import * as React from "react"

import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import type { EmployeeSchedule, ScheduleDay } from "@/hooks/use-employees"

const DAYS: Array<{ key: ScheduleDay; label: string }> = [
  { key: "mon", label: "Lunes" },
  { key: "tue", label: "Martes" },
  { key: "wed", label: "Miércoles" },
  { key: "thu", label: "Jueves" },
  { key: "fri", label: "Viernes" },
  { key: "sat", label: "Sábado" },
  { key: "sun", label: "Domingo" },
]

/** Lo que se propone al prender un día por primera vez. */
const DEFAULT_IN = "08:00"

export function EmployeeScheduleField({
  value,
  onChange,
}: {
  value: EmployeeSchedule | null
  onChange: (next: EmployeeSchedule | null) => void
}) {
  const days = value?.days ?? {}
  const tolerance = value?.toleranceMinutes ?? 0

  /**
   * Aplica un cambio y colapsa a `null` cuando no queda ningún día.
   *
   * El colapso importa: un horario con cero días y una tolerancia cargada no
   * significa nada distinto de "sin horario", y guardarlo dejaría dos formas de
   * decir lo mismo que después hay que chequear por separado en cada lectura.
   */
  const apply = (nextDays: EmployeeSchedule["days"], nextTolerance: number) => {
    if (Object.keys(nextDays).length === 0) {
      onChange(null)
      return
    }
    onChange({ days: nextDays, toleranceMinutes: nextTolerance })
  }

  const toggleDay = (day: ScheduleDay, on: boolean) => {
    const next = { ...days }
    if (on) {
      next[day] = { in: DEFAULT_IN, out: null }
    } else {
      delete next[day]
    }
    apply(next, tolerance)
  }

  const setTime = (day: ScheduleDay, field: "in" | "out", raw: string) => {
    const current = days[day]
    if (!current) return
    const next = { ...days }
    next[day] =
      field === "in"
        ? { ...current, in: raw }
        : { ...current, out: raw === "" ? null : raw }
    apply(next, tolerance)
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-2">
        {DAYS.map(({ key, label }) => {
          const entry = days[key]
          const on = entry !== undefined
          return (
            // Altura constante prendido o apagado: la lista no puede saltar
            // mientras se arma la semana.
            <div key={key} className="flex items-center gap-3 rounded-md border p-3">
              <Switch
                id={`schedule-${key}`}
                checked={on}
                onCheckedChange={(checked) => toggleDay(key, checked)}
              />
              <Label htmlFor={`schedule-${key}`} className="w-24 font-normal">
                {label}
              </Label>
              <div className="flex flex-1 items-center gap-2">
                <Input
                  type="time"
                  aria-label={`Hora de entrada de ${label}`}
                  className="w-32"
                  disabled={!on}
                  value={entry?.in ?? ""}
                  onChange={(e) => setTime(key, "in", e.target.value)}
                />
                <span className="text-sm text-muted-foreground">a</span>
                <Input
                  type="time"
                  aria-label={`Hora de salida de ${label}`}
                  className="w-32"
                  disabled={!on}
                  value={entry?.out ?? ""}
                  onChange={(e) => setTime(key, "out", e.target.value)}
                />
              </div>
            </div>
          )
        })}
      </div>

      <div className="flex items-center gap-3">
        <Label htmlFor="schedule-tolerance" className="font-normal">
          Minutos de tolerancia
        </Label>
        <Input
          id="schedule-tolerance"
          type="number"
          min={0}
          max={240}
          className="w-24"
          disabled={value === null}
          value={tolerance}
          onChange={(e) => apply(days, Math.max(0, Number(e.target.value) || 0))}
        />
        <span className="text-sm text-muted-foreground">
          Antes de contar una llegada como tarde.
        </span>
      </div>
    </div>
  )
}
