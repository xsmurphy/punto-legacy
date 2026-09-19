/**
 * Primera carga del dashboard (`app/(panel)/page.tsx`).
 *
 * La estructura del dashboard sale de los DATOS: cada bloque se monta solo si
 * tiene algo que decir (`lib/dashboard/visibility.ts`). Evaluar esas reglas con
 * una query todavía sin resolver da "no hay nada" y pinta la página vacía o a
 * medias — y una query APAGADA (`enabled: false` mientras el bootstrap no trajo
 * los permisos) en TanStack v5 tiene `isLoading=false` sin datos, así que ni
 * siquiera mostraba sus skeletons locales: bloques reales vacíos, después
 * skeletons sueltos, después el contenido (bug 2026-09-19).
 *
 * Regla: mientras los permisos no resolvieron o alguna query ENCENDIDA que
 * define la estructura no tiene respuesta (ni datos ni error), la página es
 * el skeleton completo. Los refetch posteriores (cambio de rango con
 * `keepPrevious`, tiempo real, refetch de 60 s) ya tienen datos y no vuelven
 * acá.
 */

export interface SettleableQuery {
  data: unknown
  error: unknown
}

/** Una query "respondió" cuando tiene datos (incluso de placeholder) o error. */
export function isSettled(q: SettleableQuery): boolean {
  return q.data !== undefined || (q.error !== null && q.error !== undefined)
}

export interface FirstLoadInput {
  /** Los permisos del usuario ya llegaron con el bootstrap. */
  permissionsResolved: boolean
  /** Queries que definen la estructura; las apagadas no se esperan. */
  queries: { query: SettleableQuery; enabled: boolean }[]
}

export function isDashboardFirstLoad({ permissionsResolved, queries }: FirstLoadInput): boolean {
  if (!permissionsResolved) return true
  return queries.some(({ query, enabled }) => enabled && !isSettled(query))
}
