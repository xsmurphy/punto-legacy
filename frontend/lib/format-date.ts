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
  // Offset de PG: los minutos son OPCIONALES. Postgres emite "-03" y "+00"
  // (solo la hora) cuando los minutos son cero, no "-03:00". La versión
  // anterior exigía 4 dígitos (`\d{2}:?\d{2}`), así que NO stripeaba esos
  // offsets → `new Date("...T09:13:26-03")` daba Invalid Date y TODOS los
  // helpers de abajo caían al fallback de imprimir el ISO crudo. Se veía
  // como "2026-07-23 09:13:26.243535-03" en el listado de órdenes.
  //
  //   "2026-06-29 22:19:38+00"        -> "2026-06-29T22:19:38"
  //   "2026-07-23 09:13:26.243535-03" -> "2026-07-23T09:13:26.243535"
  //
  // El offset se ancla a que venga DESPUÉS de una hora: si no, en una fecha
  // pelada "2026-06-29" el "-29" se comería como offset y quedaría "2026-06".
  const normalized = iso
    .replace(" ", "T")
    .replace(/(\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)(?:[+-]\d{2}(?::?\d{2})?|Z)$/, "$1")
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

/**
 * "hace 10 min" / "hace 1 h" / "hace 2 d" — para listados donde el dato que
 * importa es qué tan vieja es la orden, no la hora de reloj (vista lista de
 * /pos/ordenes). Parsea con `parseNaive` a propósito: es un timestamp naive
 * del negocio, no UTC genuino — usar `new Date(iso)` acá reintroduce el
 * mismo bug de desfasaje de TZ documentado arriba.
 *
 * Devuelve "—" si `iso` no parsea (nunca el ISO crudo: esta función es para
 * texto corto de celda, no para debug).
 */
export function formatRelativeShort(iso: string): string {
  const d = parseNaive(iso)
  if (!d) return "—"
  const diffMs = Date.now() - d.getTime()
  const diffMin = Math.floor(diffMs / 60000)
  if (diffMin < 1) return "recién"
  if (diffMin < 60) return `hace ${diffMin} min`
  const diffH = Math.floor(diffMin / 60)
  if (diffH < 24) return `hace ${diffH} h`
  const diffD = Math.floor(diffH / 24)
  return `hace ${diffD} d`
}

/**
 * "Ahora" formateado como naive "YYYY-MM-DD HH:MM:SS" en la TZ del TENANT.
 *
 * Convención de storage: los timestamps del negocio se guardan en hora LOCAL
 * del tenant, naive (sin offset). El server ya alinea esto con
 * date_default_timezone_set(settingTimeZone) (api/data.php); el cliente debe
 * usar la MISMA TZ del tenant para sus writes, sin importar la TZ del device.
 * Así una tablet en UTC (o un device que viajó) no desfasa la fecha de la venta.
 *
 * `timeZone` es la TZ IANA del tenant (PosConfig.timezone). Si es falsy se cae
 * a la hora local del device — el comportamiento previo, aceptable cuando
 * device y tenant comparten TZ.
 */
export function tenantNow(timeZone?: string, now: Date = new Date()): string {
  const p = (n: number) => String(n).padStart(2, "0")
  if (!timeZone) {
    return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())} ${p(now.getHours())}:${p(now.getMinutes())}:${p(now.getSeconds())}`
  }
  // en-CA da el formato YYYY-MM-DD; con hour12:false las horas quedan 00-23.
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).formatToParts(now)
  const get = (t: string) => parts.find((x) => x.type === t)?.value ?? "00"
  // Intl puede emitir "24" para medianoche en algunos engines; normalizar a "00".
  const hour = get("hour") === "24" ? "00" : get("hour")
  return `${get("year")}-${get("month")}-${get("day")} ${hour}:${get("minute")}:${get("second")}`
}
