/**
 * Errores de react-hook-form → sub-pestaña de la pestaña Datos de una ficha
 * (`components/forms/form-subtabs.tsx`, context/84 §3).
 *
 * Lógica pura, sin React: la ficha declara qué campos viven en cada
 * sub-pestaña y esto decide cuáles marcar y a cuál saltar al guardar con
 * errores.
 */

export interface SubtabFields {
  id: string
  /**
   * Nombres de campo del form (rutas de react-hook-form). Un nombre cubre
   * también sus hijos: `availability` cubre `availability.days.mon.from`.
   */
  fields?: readonly string[]
}

const FIELD_ERROR_META = new Set(["type", "message", "ref", "types"])

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null
}

/**
 * Rutas con error, en el orden en que las trae el objeto de errores. Un
 * `root` (error del array entero) se reporta en la ruta del array.
 */
export function errorPaths(errors: unknown, prefix = ""): string[] {
  if (!isRecord(errors)) return []
  const out: string[] = []
  if (prefix && typeof errors.type === "string") out.push(prefix)
  for (const [key, value] of Object.entries(errors)) {
    if (FIELD_ERROR_META.has(key) || !isRecord(value)) continue
    const path = key === "root" ? prefix : prefix ? `${prefix}.${key}` : key
    for (const p of errorPaths(value, path)) {
      if (!out.includes(p)) out.push(p)
    }
  }
  return out
}

export function fieldBelongsTo(path: string, field: string): boolean {
  return path === field || path.startsWith(`${field}.`)
}

function tabOwns(tab: SubtabFields, path: string): boolean {
  return (tab.fields ?? []).some((f) => fieldBelongsTo(path, f))
}

/** Ids de las sub-pestañas con al menos un campo inválido. */
export function subtabsWithErrors(
  tabs: readonly SubtabFields[],
  errors: unknown,
): Set<string> {
  const paths = errorPaths(errors)
  const out = new Set<string>()
  for (const tab of tabs) {
    if (paths.some((p) => tabOwns(tab, p))) out.add(tab.id)
  }
  return out
}

/**
 * Primera sub-pestaña (en el orden de la lista) con error y el primer campo
 * inválido dentro de ella, para enfocarlo. `null` si ningún error cae en una
 * sub-pestaña de la lista.
 */
export function firstSubtabError(
  tabs: readonly SubtabFields[],
  errors: unknown,
): { tabId: string; field: string } | null {
  const paths = errorPaths(errors)
  for (const tab of tabs) {
    const field = paths.find((p) => tabOwns(tab, p))
    if (field) return { tabId: tab.id, field }
  }
  return null
}
