"use client"

import * as React from "react"
import {
  defaultDateRange,
  type DateRangeValue,
} from "@/components/date-range-picker"

/**
 * Rango de fecha global del panel — análogo a `use-view-scope`. El rango que el
 * usuario elige en el dashboard o en cualquier reporte queda FIJO y se comparte
 * entre todas las pantallas: navegar de un reporte a otro NO resetea el rango.
 *
 * Persiste en localStorage (sobrevive reloads) y se sincroniza entre tabs vía
 * el evento `storage`. Las suscripciones internas mantienen en fase a todos los
 * componentes que usan el hook dentro de la misma pestaña.
 *
 * Serialización: guardamos solo la fecha (YYYY-MM-DD, sin hora ni TZ) porque el
 * picker y `rangeToBackend` trabajan a granularidad de día. Al leer reconstruimos
 * `Date` en hora local.
 */

export const DATE_RANGE_KEY = "punto.dateRange"

const subs = new Set<() => void>()

function pad(n: number): string {
  return String(n).padStart(2, "0")
}

function dateToKey(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

function keyToDate(s: string): Date | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s)
  if (!m) return null
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
  return Number.isNaN(d.getTime()) ? null : d
}

function readRaw(): DateRangeValue | null {
  if (typeof window === "undefined") return null
  const raw = window.localStorage.getItem(DATE_RANGE_KEY)
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as { from?: string; to?: string }
    const from = parsed.from ? keyToDate(parsed.from) : null
    const to = parsed.to ? keyToDate(parsed.to) : null
    if (!from || !to) return null
    return { from, to }
  } catch {
    return null
  }
}

/** Lectura síncrona desde fuera de React. */
export function readDateRange(): DateRangeValue {
  return readRaw() ?? defaultDateRange()
}

export function setDateRange(value: DateRangeValue) {
  if (typeof window === "undefined") return
  window.localStorage.setItem(
    DATE_RANGE_KEY,
    JSON.stringify({ from: dateToKey(value.from), to: dateToKey(value.to) }),
  )
  subs.forEach((fn) => fn())
}

export function useDateRange(): {
  range: DateRangeValue
  setRange: (value: DateRangeValue) => void
} {
  // SSR-safe: arranca con el default (determinístico a granularidad de día, así
  // el primer render del cliente coincide con el del server) y se hidrata desde
  // localStorage en el primer effect.
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)

  React.useEffect(() => {
    const stored = readRaw()
    if (stored) setRange(stored)

    const sub = () => {
      const next = readRaw()
      if (next) setRange(next)
    }
    subs.add(sub)
    const onStorage = (e: StorageEvent) => {
      if (e.key === DATE_RANGE_KEY) sub()
    }
    window.addEventListener("storage", onStorage)
    return () => {
      subs.delete(sub)
      window.removeEventListener("storage", onStorage)
    }
  }, [])

  return { range, setRange: setDateRange }
}
