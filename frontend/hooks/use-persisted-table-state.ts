"use client"

import * as React from "react"

import { useTableStateNamespace } from "@/lib/table-state/scope"
import {
  patchTableState,
  readTableState,
  subscribeTableStateReset,
} from "@/lib/table-state/store"

/**
 * Estado del CALLER de un `<DataTable>` que tiene que sobrevivir al reload:
 * filtros de dominio (tipo, sucursal, categoría, estado…) y la búsqueda
 * server-side (`searchValue`/`onSearchChange`). Es un `useState` que además
 * persiste.
 *
 * Usa la MISMA clave que el wrapper (`punto.dt.v1.<empresa+usuario>.<tableId>`,
 * dentro de `caller.<key>`), así que:
 *   - las preferencias quedan separadas por empresa y por persona;
 *   - "Restablecer vista" del listado las borra junto con las de la tabla y este
 *     hook vuelve solo a `defaultValue`.
 *
 * Hidratación: el primer render devuelve `defaultValue` (igual en server y
 * cliente, sin mismatch) y el valor guardado se aplica en un layout effect,
 * antes de que el browser pinte. Mientras la identidad carga, el `<DataTable>`
 * del mismo `tableId` muestra skeleton, así que no se ven filas con el filtro
 * default.
 *
 * Valores que no son JSON plano (ej. un rango de fechas con `Date`) pasan
 * `serialize`/`deserialize`; `deserialize` devuelve `null` si lo guardado no
 * sirve, y se usa el default.
 */
export function usePersistedTableState<T>(
  tableId: string,
  key: string,
  defaultValue: T,
  options?: {
    serialize?: (value: T) => unknown
    deserialize?: (raw: unknown) => T | null
  },
): [T, (next: React.SetStateAction<T>) => void] {
  const namespace = useTableStateNamespace()

  const [value, setValue] = React.useState<T>(defaultValue)
  const valueRef = React.useRef(value)
  valueRef.current = value

  // Default y (de)serializadores por ref: los call-sites los escriben inline y
  // no deben re-disparar la restauración en cada render.
  const defaultRef = React.useRef(defaultValue)
  defaultRef.current = defaultValue
  const optionsRef = React.useRef(options)
  optionsRef.current = options

  React.useLayoutEffect(() => {
    if (namespace === undefined) return // identidad cargando
    if (namespace === null) return // sin persistencia: queda el estado en memoria

    const readStored = (): T => {
      const caller = readTableState(namespace, tableId).caller
      if (!caller || !(key in caller)) return defaultRef.current
      const raw = caller[key]
      const des = optionsRef.current?.deserialize
      if (des) return des(raw) ?? defaultRef.current
      return isCompatible(raw, defaultRef.current) ? (raw as T) : defaultRef.current
    }

    const restored = readStored()
    valueRef.current = restored
    setValue(restored)

    return subscribeTableStateReset(namespace, tableId, () => {
      valueRef.current = defaultRef.current
      setValue(defaultRef.current)
    })
  }, [namespace, tableId, key])

  const set = React.useCallback(
    (next: React.SetStateAction<T>) => {
      const resolved =
        typeof next === "function" ? (next as (prev: T) => T)(valueRef.current) : next
      valueRef.current = resolved
      setValue(resolved)
      // Escritura SÍNCRONA (no en un effect): "Restablecer vista" llama al
      // `onClearFilters` del caller y DESPUÉS borra la clave; si esto escribiera
      // en un effect, re-grabaría los defaults sobre la clave recién borrada.
      if (namespace) {
        const ser = optionsRef.current?.serialize
        patchTableState(namespace, tableId, {
          caller: { [key]: ser ? ser(resolved) : resolved },
        })
      }
    },
    [namespace, tableId, key],
  )

  return [value, set]
}

/**
 * Chequeo mínimo sin serializador: mismo tipo JSON que el default. Un filtro que
 * era string y ahora es array (porque el caller cambió) no se aplica a ciegas.
 */
function isCompatible(raw: unknown, fallback: unknown): boolean {
  if (fallback === null || fallback === undefined) return true
  if (Array.isArray(fallback)) return Array.isArray(raw)
  if (typeof fallback === "object") return typeof raw === "object" && raw !== null && !Array.isArray(raw)
  return typeof raw === typeof fallback
}
