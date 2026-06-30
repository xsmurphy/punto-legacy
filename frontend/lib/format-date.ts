import { format } from "date-fns"
import { es } from "date-fns/locale"

/**
 * Formateo de fechas de TRANSACCIONES / VENTAS / CAJA / REPORTES.
 *
 * Por qué "naive": los timestamps del negocio se guardan en la BD como la
 * hora LOCAL de pared del comercio pero etiquetada como UTC. Ej.: una venta
 * de las 22:19 local queda como "2026-06-29 22:19:38+00".
 *
 * Si se parsea con `new Date("...+00")` el JS la interpreta como UTC y luego
 * `format`/`toLocaleString` la re-convierte a la TZ del browser (Paraguay −3)
 * → mostraría 19:19 en vez de 22:19. O sea: resta 3h a algo que YA es hora
 * local. Para mostrar el wall-clock guardado tal cual, hay que STRIPEAR el
 * offset y parsear los componentes en LOCAL.
 *
 * Usar SOLO para timestamps del negocio guardados local-naive. NO usar para
 * fechas genuinamente UTC ni para pickers de input (esos manejan Date nativos).
 */

/**
 * Parsea un timestamp del backend ignorando el offset de timezone final.
 * El Date resultante representa los mismos componentes de pared (año, mes,
 * día, hora, minuto) interpretados en la TZ local del browser.
 * Devuelve null si no parsea.
 */
export function parseNaive(iso: string): Date | null {
  if (!iso) return null
  // "2026-06-29 22:19:38+00" -> "2026-06-29T22:19:38"
  const normalized = iso.replace(" ", "T").replace(/([+-]\d{2}:?\d{2}|Z)$/, "")
  const d = new Date(normalized)
  return Number.isNaN(d.getTime()) ? null : d
}

/** "29 jun 22:19" (default). Si no parsea, devuelve el iso crudo. */
export function formatDateTime(iso: string, fmt = "d MMM HH:mm"): string {
  const d = parseNaive(iso)
  if (!d) return iso
  return format(d, fmt, { locale: es })
}

/** "29 jun 2026" */
export function formatDate(iso: string): string {
  const d = parseNaive(iso)
  if (!d) return iso
  return format(d, "d MMM yyyy", { locale: es })
}

/** "22:19" */
export function formatTime(iso: string): string {
  const d = parseNaive(iso)
  if (!d) return iso
  return format(d, "HH:mm", { locale: es })
}
