"use client"

import * as React from "react"

/**
 * View-scope del panel — controla por qué outletId se filtran las consultas
 * de lectura (reports, dashboard, listados). Es ORTOGONAL al `active outlet`
 * del JWT, que sigue siendo una sucursal específica para escrituras (POS,
 * drawers, ventas).
 *
 *   - `null`   → no seleccionado todavía → no se manda header → backend usa
 *                el `oid` del JWT (comportamiento default actual).
 *   - `"all"`  → modo "Todas las sucursales" → header `X-Outlet-Id: all` →
 *                backend setea OUTLET_ID='' → Roc::build omite el filtro.
 *   - UUID     → header `X-Outlet-Id: <uuid>` → backend override del JWT
 *                + validación de pertenencia al companyId del JWT.
 *
 * Persiste en localStorage (sobrevive reloads). Sync entre tabs via storage
 * event. Las suscripciones internas (`subs`) permiten que el read-only
 * helper `readViewScope()` (usado por api-client, NO un hook) y los hooks
 * React se mantengan en fase.
 */

export const VIEW_SCOPE_KEY = "punto.viewOutletScope"

export type ViewScope = string | "all" | null

const subs = new Set<() => void>()

function readRaw(): ViewScope {
  if (typeof window === "undefined") return null
  const v = window.localStorage.getItem(VIEW_SCOPE_KEY)
  if (v === null || v === "") return null
  return v as ViewScope
}

/** Lectura síncrona desde fuera de React (api-client). */
export function readViewScope(): ViewScope {
  return readRaw()
}

/**
 * Devuelve el valor del header `X-Outlet-Id` a mandar, o `null` si no se
 * debe mandar el header.
 */
export function viewScopeHeader(): string | null {
  const v = readRaw()
  if (v === null) return null
  return v // "all" o UUID
}

export function setViewScope(value: ViewScope) {
  if (typeof window === "undefined") return
  if (value === null) window.localStorage.removeItem(VIEW_SCOPE_KEY)
  else window.localStorage.setItem(VIEW_SCOPE_KEY, value)
  subs.forEach((fn) => fn())
}

export function useViewScope() {
  // SSR-safe: arranca en null en el server y se hidrata desde localStorage
  // en el primer effect (evita mismatch de hydration por divergencia
  // server/client del localStorage).
  const [scope, setScope] = React.useState<ViewScope>(null)

  React.useEffect(() => {
    setScope(readRaw())
    const sub = () => setScope(readRaw())
    subs.add(sub)
    const onStorage = (e: StorageEvent) => {
      if (e.key === VIEW_SCOPE_KEY) sub()
    }
    window.addEventListener("storage", onStorage)
    return () => {
      subs.delete(sub)
      window.removeEventListener("storage", onStorage)
    }
  }, [])

  return { scope, setScope: setViewScope }
}
