"use client"

import * as React from "react"
import { defaultDateRange } from "@/components/date-range-picker"
import {
  dateToDayKey,
  deserializeDateRange,
  serializeDateRange,
  type DateRangeValue,
} from "@/lib/date-range-presets"

/**
 * Rango de fecha compartido — análogo a `use-view-scope`. El rango que el
 * usuario elige en el dashboard o en cualquier reporte queda FIJO y se comparte
 * entre todas las pantallas: navegar de un reporte a otro NO resetea el rango.
 *
 * Persiste en localStorage (sobrevive reloads) y se sincroniza entre tabs vía
 * el evento `storage`. Las suscripciones internas mantienen en fase a todos los
 * componentes que usan el hook dentro de la misma pestaña.
 *
 * Serialización (`lib/date-range-presets`): un rango que salió de un preset
 * ("Hoy", "Últimos 7 días"…) se guarda como PRESET y se vuelve a resolver
 * contra el reloj al leer — guardar sus fechas hacía que mañana "Hoy" mostrara
 * ayer. Un rango elegido a mano se guarda con sus fechas (YYYY-MM-DD, sin hora
 * ni TZ) porque el picker y `rangeToBackend` trabajan a granularidad de día. Se
 * sigue leyendo el formato viejo `{from,to}`.
 *
 * `isCustom` responde "¿el usuario eligió un rango, o está viendo el default?".
 * Se deriva de si HAY algo guardado, no de comparar contra `defaultDateRange()`:
 * esa comparación nunca da igualdad porque el default arma `to: new Date()` en
 * cada llamada y los milisegundos difieren. La presencia del valor guardado es
 * la única señal estable, y además sobrevive al remonte del componente — un
 * flag local `useState(false)` volvía a "no personalizado" en cada navegación
 * aunque el rango elegido siguiera aplicándose.
 *
 * SCOPES. Hay dos rangos, no uno: el del panel y el de la caja. El POS y el
 * panel comparten origin, así que comparten localStorage — sin separar la clave,
 * el rango de 90 días que el dueño dejó puesto analizando un reporte aparecería
 * en la búsqueda de ventas del cajero, que espera ver lo de hoy. Son dos
 * superficies con dos sesiones distintas (cookie del panel vs token del
 * dispositivo) y dos usos distintos; el rango es una preferencia de UI de cada
 * una. Dentro de cada scope el rango sigue siendo global y persistente, que es
 * lo que se pedía.
 */

export type DateRangeScope = "panel" | "pos"

const KEY_BY_SCOPE: Record<DateRangeScope, string> = {
  panel: "punto.dateRange",
  pos: "punto.dateRange.pos",
}

/** Clave histórica del panel. Se mantiene como export por compatibilidad. */
export const DATE_RANGE_KEY = KEY_BY_SCOPE.panel

const subs = new Set<() => void>()

function readRaw(scope: DateRangeScope): DateRangeValue | null {
  if (typeof window === "undefined") return null
  try {
    const raw = window.localStorage.getItem(KEY_BY_SCOPE[scope])
    return raw ? deserializeDateRange(JSON.parse(raw)) : null
  } catch {
    return null
  }
}

/** Lectura síncrona desde fuera de React. */
export function readDateRange(scope: DateRangeScope = "panel"): DateRangeValue {
  return readRaw(scope) ?? defaultDateRange()
}

export function setDateRange(value: DateRangeValue, scope: DateRangeScope = "panel") {
  if (typeof window === "undefined") return
  try {
    window.localStorage.setItem(KEY_BY_SCOPE[scope], JSON.stringify(serializeDateRange(value)))
  } catch {
    // Storage lleno o bloqueado: se pierde la persistencia, no el rango.
  }
  // Se notifica a TODOS los suscriptores: cada uno relee su propio scope, así
  // que los del otro scope se quedan como estaban (su `sameState` no cambia).
  subs.forEach((fn) => fn())
}

/**
 * Vuelve al rango default. Es global dentro de su scope, como el resto del hook:
 * quitar el filtro de fecha en una pantalla del panel lo quita en todas las del
 * panel, igual que elegirlo en una lo aplica en todas. Un "limpiar" que solo
 * afectara a la pantalla actual dejaría dos estados distintos a la vez, que es
 * justo lo que este rango compartido viene a evitar.
 */
export function clearDateRange(scope: DateRangeScope = "panel") {
  if (typeof window === "undefined") return
  try {
    window.localStorage.removeItem(KEY_BY_SCOPE[scope])
  } catch {
    // idem setDateRange
  }
  subs.forEach((fn) => fn())
}

interface DateRangeState {
  range: DateRangeValue
  isCustom: boolean
}

/** Rango + procedencia, siempre en fase: nunca uno sin el otro. */
function readState(scope: DateRangeScope): DateRangeState {
  const stored = readRaw(scope)
  return stored
    ? { range: stored, isCustom: true }
    : { range: defaultDateRange(), isCustom: false }
}

/**
 * Igualdad a granularidad de DÍA, no de milisegundo. Es la granularidad real
 * del rango (lo guardado es `YYYY-MM-DD` y `rangeToBackend` trabaja por día),
 * y comparar por `getTime()` daría siempre "distinto" en el caso default:
 * `defaultDateRange()` arma `to: new Date()` en cada llamada, así que cada
 * notificación produciría un objeto nuevo, y con él un `useMemo([range])` nuevo
 * y un refetch de más en cada pantalla — justo lo que esta guarda evita.
 */
function sameState(a: DateRangeState, b: DateRangeState): boolean {
  return (
    a.isCustom === b.isCustom &&
    a.range.preset === b.range.preset &&
    dateToDayKey(a.range.from) === dateToDayKey(b.range.from) &&
    dateToDayKey(a.range.to) === dateToDayKey(b.range.to)
  )
}

export function useDateRange(scope: DateRangeScope = "panel"): {
  range: DateRangeValue
  setRange: (value: DateRangeValue) => void
  /** `true` si el rango vigente lo eligió el usuario; `false` si es el default. */
  isCustom: boolean
  /** Vuelve al default, en todas las pantallas del scope. */
  clearRange: () => void
} {
  // SSR-safe: arranca con el default (determinístico a granularidad de día, así
  // el primer render del cliente coincide con el del server) y se hidrata desde
  // localStorage en el primer effect.
  const [state, setState] = React.useState<DateRangeState>(() => ({
    range: defaultDateRange(),
    isCustom: false,
  }))

  React.useEffect(() => {
    // El updater lee localStorage, así que es impuro: en StrictMode React lo
    // invoca dos veces. Es benigno porque la lectura no tiene efectos y el
    // resultado es el mismo en ambas pasadas — devuelve `prev` o un valor
    // equivalente. `sameState` además hace que la segunda pasada no re-renderice.
    const sync = () =>
      setState((prev) => {
        const next = readState(scope)
        return sameState(prev, next) ? prev : next
      })

    sync()
    subs.add(sync)
    const onStorage = (e: StorageEvent) => {
      if (e.key === KEY_BY_SCOPE[scope]) sync()
    }
    window.addEventListener("storage", onStorage)
    return () => {
      subs.delete(sync)
      window.removeEventListener("storage", onStorage)
    }
  }, [scope])

  const setRange = React.useCallback(
    (value: DateRangeValue) => setDateRange(value, scope),
    [scope],
  )
  const clearRange = React.useCallback(() => clearDateRange(scope), [scope])

  return { range: state.range, setRange, isCustom: state.isCustom, clearRange }
}
