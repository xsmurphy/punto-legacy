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

// Listeners scopeados POR KEY: un Set global notificaba a todos los
// consumidores del hook ante cualquier cambio, así que dos vistas persistidas
// montadas a la vez (o una pantalla con dos toggles) se re-renderizarían
// mutuamente sin motivo. Se arregla en el wrapper, no en el call-site.
const listenersByKey = new Map<string, Set<() => void>>()

function emit(key: string): void {
  const listeners = listenersByKey.get(key)
  if (!listeners) return
  for (const listener of listeners) listener()
}

function subscribeTo(key: string, callback: () => void): () => void {
  let listeners = listenersByKey.get(key)
  if (!listeners) {
    listeners = new Set()
    listenersByKey.set(key, listeners)
  }
  listeners.add(callback)

  // El evento `storage` (otras pestañas del mismo dispositivo) trae la clave
  // afectada: solo despertamos al consumidor de ESA clave.
  const onStorage = (e: StorageEvent) => {
    if (e.key === null || e.key === key) callback()
  }
  window.addEventListener("storage", onStorage)

  return () => {
    listeners.delete(callback)
    if (listeners.size === 0) listenersByKey.delete(key)
    window.removeEventListener("storage", onStorage)
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

  const subscribe = React.useCallback(
    (callback: () => void) => subscribeTo(key, callback),
    [key],
  )

  const view = React.useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot)

  const setView = React.useCallback(
    (next: T) => {
      window.localStorage.setItem(key, next)
      emit(key)
    },
    [key],
  )

  return [view, setView]
}
