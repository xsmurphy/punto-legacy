"use client"

import * as React from "react"

/**
 * Devuelve `value` retrasado `delay` ms — el valor solo "asienta" cuando el
 * caller deja de cambiarlo (ej. dejar de tipear). Pensado para búsquedas que
 * pegan al servidor por cada cambio: sin esto, cada tecla dispara un fetch.
 *
 * Extraído de `pos-transactions-dialog.tsx` (primera implementación,
 * 2026-08) a compartido cuando `/items` necesitó el mismo patrón para la
 * búsqueda server-side de Artículos — dos call-sites con la misma lógica
 * inline ya justifican el wrapper.
 */
export function useDebounce<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = React.useState(value)
  React.useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(t)
  }, [value, delay])
  return debounced
}
