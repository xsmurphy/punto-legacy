/**
 * Título de la ventana de la caja: `Comercio · Sucursal · Caja`.
 *
 * En la PWA instalada (macOS/Windows) ese texto ES el título de la ventana, y
 * es lo único que distingue una caja de otra cuando el comerciante tiene varias
 * abiertas. Decía "Punto" en todas (reporte del owner 2026-08-28).
 *
 * NO es el nombre de la app instalada: ese sale de `name`/`short_name` del
 * manifest (`app/manifest.ts`) y de `appleWebApp.title`, y los tres dicen
 * "Punto" a propósito — es la marca, y el nombre bajo el icono no puede
 * depender de datos que recién existen después de parear el device.
 *
 * Vive en `lib/` y no dentro del componente porque es lógica pura sobre el
 * estado del store: así se testea sin montar el POS entero.
 */

/** Fallback: la marca, igual que el `<title>` estático del layout raíz. */
export const POS_TITLE_FALLBACK = "Punto"

/** Lo que `selectPosWindowTitle` necesita del catalog store. */
export interface PosWindowTitleState {
  config: { companyName?: string } | null
  outlet: { name?: string } | null
  registers: { id: string; name?: string }[]
  activeRegisterId: string | null
}

/** `Comercio · Sucursal · Caja`, salteando lo que todavía no hidrató. */
export function selectPosWindowTitle(state: PosWindowTitleState): string {
  const register = state.registers.find((r) => r.id === state.activeRegisterId) ?? null
  const parts = [state.config?.companyName, state.outlet?.name, register?.name]
    .map((p) => (typeof p === "string" ? p.trim() : ""))
    .filter((p) => p !== "")
  return parts.length > 0 ? parts.join(" · ") : POS_TITLE_FALLBACK
}
