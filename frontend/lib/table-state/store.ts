/**
 * Preferencias persistidas de un listado `<DataTable>` — capa pura sobre
 * localStorage, sin React.
 *
 * Qué se guarda: orden, filtros por columna, texto del buscador, visibilidad de
 * columnas (todo del wrapper) y el estado del CALLER (filtros de dominio y
 * búsqueda server-side, vía `usePersistedTableState`). La paginación NO: al
 * recargar se vuelve a la página 1, así nunca se queda parado en una página que
 * con otros filtros ya no existe.
 *
 * ── Son preferencias de la PERSONA ──────────────────────────────────────────
 *
 * La clave va namespaceada por empresa + usuario (`namespace`, ver `scope.tsx`):
 *   - el mismo browser entra a varias empresas (multi-empresa, "entrar como" de
 *     /admin), y un filtro por sucursal o categoría guarda ids de UNA empresa;
 *   - el mismo equipo lo usan varias personas (encargado y cajero), y el orden o
 *     las columnas ocultas son cómo trabaja cada una.
 *
 * No hay TTL ni limpieza automática, a propósito (decisión del owner): lo único
 * que borra una preferencia es que el usuario la cambie o apriete "Restablecer
 * vista".
 *
 * ── Una sola clave versionada por tabla ─────────────────────────────────────
 *
 * `punto.dt.v1.<namespace>.<tableId>` con un objeto. "Campo ausente" significa
 * "sin preferencia" y "campo presente" significa preferencia, AUNQUE esté vacío:
 * `columnVisibility: {}` es "el usuario mostró todas", no "no eligió nada". La
 * versión anterior confundía los dos (`Object.keys(v).length === 0`) y volvía a
 * ocultar las columnas que el usuario había mostrado.
 *
 * localStorage puede tirar (modo privado, cuota llena, sitio bloqueado): toda
 * lectura/escritura está envuelta y ante un fallo se pierde la persistencia,
 * nunca el listado.
 */

import type { ColumnFiltersState, SortingState, VisibilityState } from "@tanstack/react-table"

export interface PersistedTableState {
  sorting?: SortingState
  columnFilters?: ColumnFiltersState
  globalFilter?: string
  columnVisibility?: VisibilityState
  /** Estado del caller, por clave de `usePersistedTableState`. Ya serializado. */
  caller?: Record<string, unknown>
}

const PREFIX = "punto.dt.v1"

export function tableStateKey(namespace: string, tableId: string): string {
  return `${PREFIX}.${namespace}.${tableId}`
}

/**
 * Clave de la versión anterior: solo visibilidad, global al browser. Se migra a
 * la clave namespaceada la primera vez que se lee sin valor nuevo, para que
 * nadie pierda las columnas que ya había ocultado.
 */
export function legacyVisibilityKey(tableId: string): string {
  return `punto.dt.cols.${tableId}`
}

// ── Namespaces ──────────────────────────────────────────────────────────────

function segment(v: string | number): string {
  // Los ids son uuid o enteros; se sanea igual para que un valor raro no pueda
  // fabricar una clave que pise la de otro namespace.
  return String(v).replace(/[^A-Za-z0-9_-]/g, "")
}

/** Realm tenant (panel y caja): empresa + usuario. */
export function tenantTableNamespace(companyId: string | number, userId: string | number): string {
  return `c-${segment(companyId)}.u-${segment(userId)}`
}

/** Realm admin: no hay empresa, el usuario admin es la persona. */
export function adminTableNamespace(adminId: string | number): string {
  return `admin.u-${segment(adminId)}`
}

// ── Storage seguro ──────────────────────────────────────────────────────────

function storage(): Storage | null {
  try {
    return typeof window !== "undefined" ? window.localStorage : null
  } catch {
    return null
  }
}

function safeGet(key: string): string | null {
  try {
    return storage()?.getItem(key) ?? null
  } catch {
    return null
  }
}

function safeSet(key: string, value: string): void {
  try {
    storage()?.setItem(key, value)
  } catch {
    // Cuota llena / bloqueado: solo se pierde la persistencia.
  }
}

function safeRemove(key: string): void {
  try {
    storage()?.removeItem(key)
  } catch {
    // idem
  }
}

function parseJson(raw: string | null): unknown {
  if (raw === null) return undefined
  try {
    return JSON.parse(raw)
  } catch {
    return undefined
  }
}

// ── Validación ──────────────────────────────────────────────────────────────
// Lo que está en storage lo pudo escribir otra versión del front (o nadie
// sabe quién). Cada campo se valida por separado: uno corrupto se descarta sin
// arrastrar a los demás.

function isPlainObject(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null && !Array.isArray(v)
}

function sanitizeSorting(v: unknown): SortingState | undefined {
  if (!Array.isArray(v)) return undefined
  return v.filter(
    (s): s is { id: string; desc: boolean } =>
      isPlainObject(s) && typeof s.id === "string" && typeof s.desc === "boolean",
  ).map((s) => ({ id: s.id, desc: s.desc }))
}

function sanitizeColumnFilters(v: unknown): ColumnFiltersState | undefined {
  if (!Array.isArray(v)) return undefined
  return v
    .filter((f): f is { id: string; value: unknown } => isPlainObject(f) && typeof f.id === "string" && "value" in f)
    .map((f) => ({ id: f.id, value: f.value }))
}

function sanitizeVisibility(v: unknown): VisibilityState | undefined {
  if (!isPlainObject(v)) return undefined
  const out: VisibilityState = {}
  for (const [k, val] of Object.entries(v)) {
    if (typeof val === "boolean") out[k] = val
  }
  return out
}

export function sanitizeTableState(v: unknown): PersistedTableState {
  if (!isPlainObject(v)) return {}
  const out: PersistedTableState = {}
  const sorting = sanitizeSorting(v.sorting)
  if (sorting) out.sorting = sorting
  const columnFilters = sanitizeColumnFilters(v.columnFilters)
  if (columnFilters) out.columnFilters = columnFilters
  if (typeof v.globalFilter === "string") out.globalFilter = v.globalFilter
  const columnVisibility = sanitizeVisibility(v.columnVisibility)
  if (columnVisibility) out.columnVisibility = columnVisibility
  if (isPlainObject(v.caller)) out.caller = { ...v.caller }
  return out
}

// ── API ─────────────────────────────────────────────────────────────────────

function readRaw(namespace: string, tableId: string): PersistedTableState {
  return sanitizeTableState(parseJson(safeGet(tableStateKey(namespace, tableId))))
}

function write(namespace: string, tableId: string, state: PersistedTableState): void {
  safeSet(tableStateKey(namespace, tableId), JSON.stringify(state))
}

/**
 * Lee las preferencias de una tabla. Si todavía no hay visibilidad namespaceada
 * pero existe la clave vieja `punto.dt.cols.<tableId>`, la adopta y la escribe
 * en la clave nueva. La vieja NO se borra acá: si el equipo lo usan varias
 * personas, cada una la hereda la primera vez (se borra en "Restablecer vista").
 */
export function readTableState(namespace: string, tableId: string): PersistedTableState {
  const state = readRaw(namespace, tableId)
  if (state.columnVisibility === undefined) {
    const legacy = sanitizeVisibility(parseJson(safeGet(legacyVisibilityKey(tableId))))
    if (legacy) {
      state.columnVisibility = legacy
      write(namespace, tableId, state)
    }
  }
  return state
}

/**
 * Mezcla un parche sobre lo guardado. `caller` se mezcla por clave, así el
 * wrapper y cada `usePersistedTableState` escriben su parte sin pisarse (todo
 * es síncrono: leer-mezclar-escribir no se intercala).
 */
export function patchTableState(
  namespace: string,
  tableId: string,
  patch: Omit<PersistedTableState, "caller"> & { caller?: Record<string, unknown> },
): void {
  const current = readRaw(namespace, tableId)
  const next: PersistedTableState = { ...current, ...patch }
  if (patch.caller) next.caller = { ...current.caller, ...patch.caller }
  write(namespace, tableId, next)
}

/** Borra TODAS las preferencias de la tabla (incluida la clave vieja) y avisa. */
export function clearTableState(namespace: string, tableId: string): void {
  safeRemove(tableStateKey(namespace, tableId))
  safeRemove(legacyVisibilityKey(tableId))
  const listeners = resetListeners.get(tableStateKey(namespace, tableId))
  if (listeners) for (const fn of [...listeners]) fn()
}

// Solo el reset se notifica: es el único cambio que viene de AFUERA de quien
// es dueño del valor (el botón vive en el wrapper, los filtros en la página).
// Notificar cada escritura haría que cada consumidor relea y re-renderice por
// cambios que él mismo produjo.
const resetListeners = new Map<string, Set<() => void>>()

export function subscribeTableStateReset(
  namespace: string,
  tableId: string,
  fn: () => void,
): () => void {
  const key = tableStateKey(namespace, tableId)
  let set = resetListeners.get(key)
  if (!set) {
    set = new Set()
    resetListeners.set(key, set)
  }
  set.add(fn)
  return () => {
    set.delete(fn)
    if (set.size === 0) resetListeners.delete(key)
  }
}

// ── Columnas existentes ─────────────────────────────────────────────────────

interface ColumnDefLike {
  id?: string
  accessorKey?: unknown
  header?: unknown
  columns?: ColumnDefLike[]
}

/**
 * Ids que TanStack le va a asignar a estas columnas — misma regla que
 * `createColumn` de table-core: `id` explícito, si no `accessorKey` con los
 * puntos cambiados por `_`, si no el header string. Incluye grupos y hojas.
 *
 * Sirve para no aplicar orden/filtros guardados que refieren a columnas que ya
 * no existen: una tabla que cambió no puede quedar filtrada por una columna
 * fantasma.
 */
export function collectColumnIds(defs: readonly ColumnDefLike[]): Set<string> {
  const ids = new Set<string>()
  const walk = (list: readonly ColumnDefLike[]) => {
    for (const def of list) {
      const id =
        def.id ??
        (typeof def.accessorKey === "string" ? def.accessorKey.replace(/\./g, "_") : undefined) ??
        (typeof def.header === "string" ? def.header : undefined)
      if (id) ids.add(id)
      if (def.columns) walk(def.columns)
    }
  }
  walk(defs)
  return ids
}
