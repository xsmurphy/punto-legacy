"use client"

/**
 * Vista seleccionada de una pantalla del POS, persistida por dispositivo en
 * localStorage (ej. grilla/mapa en /pos/espacios, cuadros/lista/mapa en
 * /pos/ordenes).
 *
 * Usa `useSyncExternalStore` en vez del patrón `useState` + `useEffect` que
 * pisa el valor tras montar: ese patrón dispara un render en cascada (lo
 * marca `react-hooks/set-state-in-effect`) y hace que la pantalla parpadee un
 * frame en la vista default antes de saltar a la elegida. Acá el server
 * snapshot es el default (sin mismatch de hidratación) y el cliente lee
 * localStorage directo en el primer render del browser.
 *
 * El `storage` listener mantiene sincronizadas otras pestañas del mismo
 * dispositivo; el emitter local, otros componentes de la misma pestaña.
 */

import * as React from "react"

const listeners = new Set<() => void>()

function emit(): void {
  for (const listener of listeners) listener()
}

function subscribe(callback: () => void): () => void {
  listeners.add(callback)
  window.addEventListener("storage", callback)
  return () => {
    listeners.delete(callback)
    window.removeEventListener("storage", callback)
  }
}

/**
 * @param key      Clave de localStorage (convención `punto.pos.<pantalla>.view`).
 * @param allowed  Valores válidos. Definilo como const de módulo, no inline.
 * @param fallback Valor por defecto y snapshot de SSR.
 */
export function usePersistedView<T extends string>(
  key: string,
  allowed: readonly T[],
  fallback: T,
): [T, (view: T) => void] {
  const getSnapshot = React.useCallback((): T => {
    const raw = window.localStorage.getItem(key)
    return raw !== null && (allowed as readonly string[]).includes(raw) ? (raw as T) : fallback
  }, [key, allowed, fallback])

  const getServerSnapshot = React.useCallback((): T => fallback, [fallback])

  const view = React.useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot)

  const setView = React.useCallback(
    (next: T) => {
      window.localStorage.setItem(key, next)
      emit()
    },
    [key],
  )

  return [view, setView]
}
