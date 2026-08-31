import { ADDITIVE_FIELDS, PERIOD_SCALARS } from "@/lib/agent/tool-field-rules"

/**
 * Motor de COMPARACIÓN entre períodos para el catálogo de tools de lectura.
 *
 * ── El problema que resuelve ────────────────────────────────────────────────
 * Ninguna tool devolvía una comparativa. Sin "contra qué", el modelo solo puede
 * DESCRIBIR el período que le pidieron: «vendiste 47.500.000 en agosto». Eso no
 * es análisis — el dueño del comercio ya sabe cuánto vendió. Lo que no sabe es
 * si eso está bien, y eso solo se contesta contra el período anterior y contra
 * el mismo período del año pasado.
 *
 * ── Motor acá, vocabulario en `tool-field-rules.ts` ─────────────────────────
 * Mismo corte que `normalize-tool-result.ts`, y por el mismo motivo. Acá viven
 * la aritmética de fechas, la extracción de métricas y el contrato del delta.
 * QUÉ campos se pueden sumar entre filas es vocabulario y vive en el
 * diccionario (`ADDITIVE_FIELDS`): sumar `price` o `averageUnitCost` entre
 * filas da un número plausible y falso, que es el peor resultado posible.
 *
 * ── Por qué CENTRAL y no una comparación por tool ──────────────────────────
 * Cinco tools la aceptan hoy. Copiada en cada `execute`, `previous_year`
 * significaría cinco cosas —hay al menos dos definiciones defendibles y el
 * backend ya usa la otra, ver abajo— y el borde de los años bisiestos estaría
 * resuelto de cinco formas, cuatro de ellas mal.
 *
 * ── `previous_period` NO reusa `NonAddingSales::previousPeriod()` ──────────
 * El helper PHP (`api/lib/Reports/NonAddingSales.php:205-214`) desplaza por
 * DURACIÓN EN SEGUNDOS: `diference = (end - start) + 1`. Con fechas sin hora
 * —que es como las manda el catálogo— `strtotime` le da 00:00:00 a las dos
 * puntas, así que agosto (2026-08-01 a 2026-08-31) mide 30 días y 1 segundo, y
 * el "período anterior" le sale 2026-07-02 23:59 a 2026-08-01 23:59: ni el mes
 * de julio ni una ventana alineada a nada. Y para "agosto contra agosto del año
 * pasado" da julio, porque desplazar por duración nunca cae en el mismo mes
 * calendario.
 *
 * Acá el desplazamiento es por DÍAS ENTEROS INCLUSIVOS y `previous_year` es
 * calendario puro, que es lo que un comerciante quiere decir cuando dice "el
 * año pasado".
 */

// ── Tipos ────────────────────────────────────────────────────────────────────

export type CompareWith = "previous_period" | "previous_year"

/**
 * `explicit_year` es el modo de `get_sales_summary`, que no toma rango sino un
 * año: ahí el usuario elige el año contra el que comparar y puede ser cualquiera
 * (2026 contra 2023 es una pregunta legítima), así que llamarlo
 * `previous_year` sería mentir en la etiqueta que el modelo va a leer.
 */
export type ComparisonMode = CompareWith | "explicit_year"

export interface DateRange {
  /** YYYY-MM-DD */
  from: string
  /** YYYY-MM-DD */
  to: string
}

/**
 * Un número comparado, con la resta y el porcentaje YA hechos.
 *
 * Que el modelo reste es desperdicio (dos números para decir uno) y fuente de
 * error: los totales de un comercio tienen 8 o 9 dígitos y un LLM se equivoca
 * en la aritmética larga con total confianza.
 */
export interface MetricDelta {
  /** Valor en el período que el usuario pidió. */
  current: number
  /** Valor en el período de comparación. */
  previous: number
  /** `current - previous`. Positivo = creció. */
  absoluteChange: number
  /** Variación porcentual, o `null` cuando no hay base (ver `basis`). */
  percentChange: number | null
  /** Qué representa —o por qué falta— el porcentaje. */
  basis: PercentBasis
}

/**
 * El contrato del porcentaje, explícito y nombrado.
 *
 *  - `ratio`        → `previous > 0`: el porcentaje es el habitual.
 *  - `both_zero`    → los dos en 0. `percentChange: 0`, y es literal: no hubo
 *                     cambio. El único caso donde un 0 sobre base 0 es honesto.
 *  - `from_zero`    → `previous === 0` y `current !== 0`. `percentChange: null`.
 *                     NO es "infinito" ni "+100%": crecer 100% desde cero sigue
 *                     dando cero, así que ese número sería directamente falso, y
 *                     "infinito" no es algo que se pueda poner en una frase para
 *                     el dueño del comercio. El cambio absoluto ya dice toda la
 *                     verdad ("pasó de 0 a 1.500.000") y no necesita ayuda.
 *  - `negative_base`→ `previous < 0` (pasa de verdad: `netFlow` de Finanzas y
 *                     `revenue` pueden ser negativos). Ahí el porcentaje INVIERTE
 *                     el signo: ir de -100 a -50 es una mejora y la fórmula da
 *                     -50%. Un porcentaje que dice lo contrario de lo que pasó es
 *                     peor que no tener porcentaje, así que va `null`.
 */
export type PercentBasis = "ratio" | "both_zero" | "from_zero" | "negative_base"

export interface PeriodComparison {
  mode: ComparisonMode
  /** El período que pidió el usuario. */
  current: DateRange
  /** El período contra el que se compara. */
  baseline: DateRange
  /** Métrica → delta. Las claves son las del payload YA normalizado. */
  metrics: Record<string, MetricDelta>
  /** Advertencias sobre cómo leer los números de arriba. */
  notes: string[]
}

// ── Aritmética de fechas ─────────────────────────────────────────────────────

const DAY_MS = 86_400_000
const DATE_RE = /^(\d{4})-(\d{2})-(\d{2})/

/**
 * Fecha del catálogo (`YYYY-MM-DD`) a milisegundos UTC.
 *
 * UTC y no hora local A PROPÓSITO: estas fechas son días calendario del
 * comercio, sin hora, y `new Date("2026-08-01")` interpretado en una zona al
 * oeste de Greenwich devuelve el 31 de julio. Con `Date.UTC` la aritmética de
 * días es exacta y no depende de dónde corra el contenedor.
 *
 * Un sufijo de hora se ignora: algunos endpoints devuelven `Y-m-d H:i:s`, pero
 * lo que el catálogo MANDA es siempre el día pelado.
 */
function parseDay(value: string): { y: number; m: number; d: number } | null {
  const m = DATE_RE.exec(value.trim())
  if (!m) return null
  const y = Number(m[1])
  const mo = Number(m[2])
  const d = Number(m[3])
  if (mo < 1 || mo > 12 || d < 1 || d > 31) return null
  // Rechaza el 31 de febrero y compañía: `Date.UTC` los desborda al mes
  // siguiente en silencio, y una fecha corrida es un dato equivocado, no un
  // error visible.
  const ms = Date.UTC(y, mo - 1, d)
  const back = new Date(ms)
  if (back.getUTCFullYear() !== y || back.getUTCMonth() !== mo - 1 || back.getUTCDate() !== d) {
    return null
  }
  return { y, m: mo, d }
}

function toMs(p: { y: number; m: number; d: number }): number {
  return Date.UTC(p.y, p.m - 1, p.d)
}

function fmt(ms: number): string {
  return new Date(ms).toISOString().slice(0, 10)
}

/** Días del mes `m` (1-12) del año `y`. El día 0 del mes siguiente es el último. */
function daysInMonth(y: number, m: number): number {
  return new Date(Date.UTC(y, m, 0)).getUTCDate()
}

/**
 * El rango contra el que se compara, o `null` si el rango pedido no sirve.
 *
 * Devuelve `null` —en vez de inventar algo— cuando falta una punta o cuando
 * `from` es posterior a `to`. Comparar contra una ventana adivinada es peor que
 * no comparar: el modelo no tiene cómo saber que el "período anterior" que le
 * llegó no es el que pidió.
 */
export function comparisonRange(range: DateRange, mode: CompareWith): DateRange | null {
  const from = parseDay(range.from)
  const to = parseDay(range.to)
  if (!from || !to) return null

  const fromMs = toMs(from)
  const toMs_ = toMs(to)
  if (fromMs > toMs_) return null

  if (mode === "previous_period") {
    // Largo INCLUSIVO en días: 1..31 de agosto son 31 días, no 30. La ventana
    // anterior termina el día antes de `from` y mide lo mismo, así que agosto
    // compara contra el 1 al 31 de julio y no contra una ventana corrida.
    const spanDays = Math.round((toMs_ - fromMs) / DAY_MS) + 1
    const prevTo = fromMs - DAY_MS
    return { from: fmt(prevTo - (spanDays - 1) * DAY_MS), to: fmt(prevTo) }
  }

  // previous_year: MISMO rango calendario del año anterior. Agosto 2026 compara
  // contra agosto 2025, que es lo que alguien quiere decir con "el año pasado".
  //
  // El día se recorta al último del mes destino en vez de desbordar al mes
  // siguiente. Es el borde de los bisiestos: 2024-02-29 no existe en 2023, y la
  // respuesta correcta es 2023-02-28 (el mismo febrero, completo), no 2023-03-01
  // (que sacaría el rango de febrero). El mismo recorte hace que "todo febrero
  // de 2024" (1 al 29) compare contra "todo febrero de 2023" (1 al 28): meses de
  // distinto largo, cada uno entero, que es la comparación que el comerciante
  // tiene en la cabeza.
  const clamp = (p: { y: number; m: number; d: number }) =>
    Date.UTC(p.y - 1, p.m - 1, Math.min(p.d, daysInMonth(p.y - 1, p.m)))

  return { from: fmt(clamp(from)), to: fmt(clamp(to)) }
}

// ── Extracción de métricas ───────────────────────────────────────────────────

/**
 * Reduce un payload YA NORMALIZADO a los números que se pueden comparar.
 *
 * Dos formas, las mismas dos que ya contempla el normalizador:
 *
 *  - filas (array pelado o `rows`) → se SUMAN los campos aditivos de cada fila,
 *    más `rowCount`;
 *  - objeto de totales (`/v1/finance/summary`, el widget de KPIs) → se toman sus
 *    propios escalares aditivos, más los `PERIOD_SCALARS` (ticket promedio,
 *    margen), que el backend ya calculó para el período entero.
 *
 * El filtro por `ADDITIVE_FIELDS` es lo que hace esto seguro. Sumar cualquier
 * número que aparezca produciría "precio total: 4.870.000" sumando la lista de
 * precios del catálogo, o un `averageUnitCost` acumulado que no significa nada.
 * Un campo que nadie declaró aditivo NO se compara — la ausencia es correcta y
 * la invención no.
 *
 * Las claves son las del payload normalizado (`unitsSold`, no `usold`): la
 * comparación se construye DESPUÉS de traducir, así los nombres del bloque
 * `comparison` son los mismos que el modelo ve en `data`.
 */
export function extractMetrics(payload: unknown): Record<string, number> {
  const out: Record<string, number> = {}

  const rows = rowsOf(payload)
  if (rows) {
    for (const row of rows) {
      if (!isPlainObject(row)) continue
      for (const [key, value] of Object.entries(row)) {
        if (!ADDITIVE_FIELDS.has(key)) continue
        const n = numeric(value)
        if (n === null) continue
        out[key] = (out[key] ?? 0) + n
      }
    }
    out.rowCount = rows.length
  }

  // Los escalares propios del objeto ganan sobre la suma de filas: un total que
  // el backend ya calculó es más confiable que el que sale de re-sumar las filas
  // que sobrevivieron al recorte de `MAX_ROWS`.
  //
  // Y acá —y SOLO acá— entran también los `PERIOD_SCALARS`: promedios y
  // porcentajes que el backend calculó para el período entero. Sumarlos entre
  // filas no significaría nada, pero compararlos entre dos períodos es
  // exactamente lo que se quiere saber ("¿subió mi ticket promedio?").
  if (isPlainObject(payload)) {
    for (const [key, value] of Object.entries(payload)) {
      if (!ADDITIVE_FIELDS.has(key) && !PERIOD_SCALARS.has(key)) continue
      const n = numeric(value)
      if (n === null) continue
      out[key] = n
    }
  }

  return out
}

function isPlainObject(v: unknown): v is Record<string, unknown> {
  if (v === null || typeof v !== "object" || Array.isArray(v)) return false
  const proto = Object.getPrototypeOf(v)
  return proto === Object.prototype || proto === null
}

/** Las filas del payload, si las hay. `null` = no es una lista. */
function rowsOf(payload: unknown): unknown[] | null {
  if (Array.isArray(payload)) return payload
  if (isPlainObject(payload) && Array.isArray(payload.rows)) return payload.rows
  return null
}

/** Los reportes emiten los números como string tanto como number (driver PDO). */
function numeric(v: unknown): number | null {
  if (typeof v === "number") return Number.isFinite(v) ? v : null
  if (typeof v === "string" && v.trim() !== "") {
    const n = Number(v)
    return Number.isFinite(n) ? n : null
  }
  return null
}

// ── El delta ─────────────────────────────────────────────────────────────────

/** Un decimal: más precisión es ruido, y el modelo lo va a redondear igual. */
function round1(n: number): number {
  return Math.round(n * 10) / 10
}

export function buildDelta(current: number, previous: number): MetricDelta {
  const absoluteChange = round1(current - previous)

  let basis: PercentBasis
  let percentChange: number | null

  if (previous > 0) {
    basis = "ratio"
    percentChange = round1(((current - previous) / previous) * 100)
  } else if (previous < 0) {
    basis = "negative_base"
    percentChange = null
  } else if (current === 0) {
    basis = "both_zero"
    percentChange = 0
  } else {
    basis = "from_zero"
    percentChange = null
  }

  return { current: round1(current), previous: round1(previous), absoluteChange, percentChange, basis }
}

const NOTE_BASE =
  "current es el período que pediste y baseline el de comparación; absoluteChange = current - previous. " +
  "Solo se comparan métricas acumulables (totales, unidades, conteos): precios, costos unitarios y saldos no se suman entre filas y no aparecen acá."

const NOTE_FROM_ZERO =
  "percentChange es null en las métricas con basis 'from_zero': el período anterior fue 0 y no hay base para un porcentaje. " +
  "No lo presentes como +100% ni como infinito — usá el cambio absoluto (pasó de 0 a N)."

const NOTE_NEGATIVE_BASE =
  "percentChange es null en las métricas con basis 'negative_base': el período anterior fue negativo y el porcentaje saldría con el signo invertido " +
  "(ir de -100 a -50 es una mejora y la fórmula daría -50%). Usá el cambio absoluto."

/**
 * Arma el bloque `comparison` a partir de los dos payloads ya normalizados.
 *
 * La estructura nombra los dos períodos con sus fechas EXACTAS y nunca con
 * etiquetas relativas: "actual" y "anterior" sueltos se le mezclan al modelo en
 * cuanto la conversación encadena dos consultas, y ahí reporta la caída de un
 * mes como la del otro.
 */
export function buildComparison(args: {
  mode: ComparisonMode
  current: DateRange
  baseline: DateRange
  currentPayload: unknown
  baselinePayload: unknown
}): PeriodComparison {
  const currentMetrics = extractMetrics(args.currentPayload)
  const baselineMetrics = extractMetrics(args.baselinePayload)

  const metrics: Record<string, MetricDelta> = {}
  for (const key of new Set([...Object.keys(currentMetrics), ...Object.keys(baselineMetrics)])) {
    metrics[key] = buildDelta(currentMetrics[key] ?? 0, baselineMetrics[key] ?? 0)
  }

  const bases = new Set(Object.values(metrics).map((m) => m.basis))
  const notes = [NOTE_BASE]
  if (bases.has("from_zero")) notes.push(NOTE_FROM_ZERO)
  if (bases.has("negative_base")) notes.push(NOTE_NEGATIVE_BASE)

  return { mode: args.mode, current: args.current, baseline: args.baseline, metrics, notes }
}

/**
 * Lo que se devuelve cuando pidieron comparar y no se pudo.
 *
 * Va como bloque `comparison` con `unavailable` en vez de omitirse en silencio:
 * el modelo pidió una comparación y tiene que enterarse de que no la tiene, o
 * responde igual y se inventa el "contra qué".
 */
export function comparisonUnavailable(
  mode: ComparisonMode,
  reason: string,
  ranges?: { current: DateRange; baseline: DateRange },
) {
  return { mode, ...(ranges ?? {}), unavailable: reason }
}

/** El texto del caso más común: pidieron comparar sin decir contra qué rango. */
export const NEEDS_EXPLICIT_RANGE =
  "No se pudo comparar: compareWith necesita un rango explícito y válido en from/to (YYYY-MM-DD, con from anterior o igual a to). " +
  "Volvé a llamar la tool con las dos fechas."

/** No se pudo leer el período de comparación (la primera lectura sí salió). */
export const BASELINE_FETCH_FAILED =
  "No se pudo leer el período de comparación, así que no hay contra qué comparar. Los datos del período pedido sí son válidos."

/**
 * El caso que evita el número plausible y falso.
 *
 * Si alguna de las dos lecturas vino recortada por `MAX_ROWS`, sumar sus filas
 * da el total de una MUESTRA. Comparar dos muestras de tamaño arbitrario y
 * presentar el resultado como "las ventas cayeron 12%" es exactamente el error
 * que no se puede detectar desde afuera, así que la comparación no se emite.
 */
export const TRUNCATED_SAMPLE =
  "No se pudo comparar: alguno de los dos períodos devolvió más filas de las que entran en una respuesta, " +
  "y sumar una muestra parcial daría un porcentaje falso. Usá get_sales_kpis (totales del período, sin filas) o acotá el rango."
