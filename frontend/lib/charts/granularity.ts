import { format } from "date-fns"
import { es } from "date-fns/locale"

/**
 * Formato de los gráficos con fechas en el eje — el lado del FRONT de la regla
 * de granularidad.
 *
 * El grano (día / semana / mes) NO se decide acá: lo decide el servidor con
 * `api/lib/Support/TimeBuckets.php` según el largo del rango (≤ 31 días →
 * día, 32–120 → semana ISO, > 120 → mes) y cada endpoint de serie devuelve
 * `granularity` y, por bucket, su primer día (`bucket`), su último día natural
 * (`end`) y si el rango lo recortó (`partial`). Este módulo solo sabe
 * mostrarlo: la etiqueta del eje, la del tooltip, "Promedio por …" y cómo se
 * distingue un período incompleto. Todos los gráficos temporales pasan por
 * acá para que dos del mismo período hablen igual.
 *
 * Las fechas son CALENDARIO del comercio ('YYYY-MM-DD' ya cortado en su zona
 * por el servidor), no instantes: se arman con los componentes locales y
 * nunca con `new Date("YYYY-MM-DD")`, que las lee como UTC y en América las
 * corre un día para atrás. Mismo criterio que `lib/format-date.ts`, del que
 * sale también el locale (la UI de Punto está en español en todos sus
 * mercados — ver `COUNTRY_LOCALE.language` en `lib/tenant-locale.ts`).
 */

/** `hour` solo aparece cuando el rango es de UN día (serie de ventas por hora). */
export type Granularity = "hour" | "day" | "week" | "month"

/** Identidad de un bucket tal como la devuelve el servidor. */
export interface TimeBucket {
  /** Primer día del período, 'YYYY-MM-DD' (o la hora '0'–'23' si es `hour`). */
  bucket: string
  /** Último día NATURAL del período, aunque el rango lo corte antes. */
  end: string
  /** El rango no cubre el período entero (primera/última semana o mes). */
  partial: boolean
}

const GRANULARITIES: readonly Granularity[] = ["hour", "day", "week", "month"]

/** Normaliza lo que venga del servidor; ante basura, día (el grano de siempre). */
export function asGranularity(value: unknown): Granularity {
  return GRANULARITIES.includes(value as Granularity) ? (value as Granularity) : "day"
}

/** Unidad en singular: "hora", "día", "semana", "mes". */
export function granularityUnit(g: Granularity): string {
  switch (g) {
    case "hour":
      return "hora"
    case "week":
      return "semana"
    case "month":
      return "mes"
    default:
      return "día"
  }
}

/** "Promedio por día" / "Promedio por semana" / "Promedio por mes". */
export function averageLabel(g: Granularity): string {
  return `Promedio por ${granularityUnit(g)}`
}

/** Título de un gráfico según el grano: `perUnit("Ventas", "week")` → "Ventas por semana". */
export function perUnit(subject: string, g: Granularity): string {
  return `${subject} por ${granularityUnit(g)}`
}

/** Aviso del tooltip para un bucket recortado por el rango. */
export const PARTIAL_NOTE = "período incompleto"

/**
 * Opacidad de la barra de un período incompleto, relativa a la de la serie.
 * Sutil a propósito: el dato es real, lo que cambia es que no se compara de
 * igual a igual con los períodos completos.
 */
export const PARTIAL_OPACITY = 0.45

/** Opacidad de relleno de un punto: la de la serie, atenuada si el período está incompleto. */
export function bucketOpacity(point: { partial?: boolean } | null | undefined, base = 1): number {
  return point?.partial ? base * PARTIAL_OPACITY : base
}

/** 'YYYY-MM-DD' → Date con esos componentes en hora local, o null. */
function calendarDate(ymd: string): Date | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd ?? "")
  if (!m) return null
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
  return Number.isNaN(d.getTime()) ? null : d
}

/** Último día de la semana ISO que empieza en `start` (+6 días). */
function weekEndOf(start: Date): Date {
  return new Date(start.getFullYear(), start.getMonth(), start.getDate() + 6)
}

const fmt = (d: Date, pattern: string) => format(d, pattern, { locale: es })

/**
 * "8–14 sep" · "29 sep–5 oct" · "29 dic 2025–4 ene 2026" (con `withYear`, el
 * año va al final: "8–14 sep 2026").
 */
function weekRange(start: Date, end: Date, withYear: boolean): string {
  const sameYear = start.getFullYear() === end.getFullYear()
  const sameMonth = sameYear && start.getMonth() === end.getMonth()
  const tailYear = withYear ? ` ${end.getFullYear()}` : ""
  if (sameMonth) return `${start.getDate()}–${fmt(end, "d MMM")}${tailYear}`
  if (sameYear) return `${fmt(start, "d MMM")}–${fmt(end, "d MMM")}${tailYear}`
  return `${fmt(start, "d MMM yyyy")}–${fmt(end, "d MMM yyyy")}`
}

/**
 * Etiqueta CORTA para el eje: "14h" · "18 sep" · "8–14 sep" · "sep 2026".
 *
 * `end` es opcional: para la semana se deriva (+6 días) si no viene.
 */
export function formatBucketTick(bucket: string, g: Granularity, end?: string): string {
  if (g === "hour") return `${String(bucket).padStart(2, "0")}h`
  const start = calendarDate(bucket)
  if (!start) return bucket
  switch (g) {
    case "week":
      return weekRange(start, calendarDate(end ?? "") ?? weekEndOf(start), false)
    case "month":
      return fmt(start, "MMM yyyy")
    default:
      return fmt(start, "d MMM")
  }
}

/**
 * Etiqueta COMPLETA para el tooltip: "14:00" · "18 sep 2026" · "8–14 sep 2026"
 * · "septiembre 2026".
 */
export function formatBucketLabel(bucket: string, g: Granularity, end?: string): string {
  if (g === "hour") return `${String(bucket).padStart(2, "0")}:00`
  const start = calendarDate(bucket)
  if (!start) return bucket
  switch (g) {
    case "week":
      return weekRange(start, calendarDate(end ?? "") ?? weekEndOf(start), true)
    case "month":
      return fmt(start, "LLLL yyyy")
    default:
      return fmt(start, "d MMM yyyy")
  }
}

/**
 * Etiqueta del tooltip de UN punto, con el aviso de período incompleto cuando
 * corresponde: "1–7 sep 2026 (período incompleto)".
 */
export function bucketTooltipLabel(
  point: Partial<TimeBucket> | null | undefined,
  g: Granularity,
): string {
  if (!point?.bucket) return ""
  const label = formatBucketLabel(point.bucket, g, point.end)
  return point.partial ? `${label} (${PARTIAL_NOTE})` : label
}

/**
 * El punto de datos que Recharts le pasa al `labelFormatter` del tooltip
 * (`payload[0].payload`), tipado lo justo para leer la identidad del bucket.
 */
export function tooltipPoint(payload: unknown): Partial<TimeBucket> | null {
  if (!Array.isArray(payload) || payload.length === 0) return null
  const p = (payload[0] as { payload?: unknown })?.payload
  return p && typeof p === "object" ? (p as Partial<TimeBucket>) : null
}

/**
 * Promedio por período de una serie, sin los períodos incompletos: una semana
 * de 2 días promediada contra semanas de 7 hunde el promedio sin que nada haya
 * cambiado. Si TODOS son incompletos (rango corto que cruza dos semanas), se
 * promedian todos — no hay contra qué más comparar.
 */
export function averagePerBucket<T extends { partial?: boolean }>(
  points: readonly T[],
  value: (p: T) => number,
): number {
  const full = points.filter((p) => !p.partial)
  const base = full.length > 0 ? full : points
  if (base.length === 0) return 0
  return base.reduce((acc, p) => acc + (value(p) || 0), 0) / base.length
}
