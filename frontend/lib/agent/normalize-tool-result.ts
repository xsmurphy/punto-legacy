import { FIELD_RULES, type FieldRule } from "@/lib/agent/tool-field-rules"

/**
 * Normalizador ÚNICO de las respuestas del catálogo de tools de lectura.
 *
 * ── El problema que resuelve ────────────────────────────────────────────────
 * Las 19 tools de fetch de `read-tools.ts` terminaban todas igual:
 * `return json?.data ?? json`. Passthrough crudo de un endpoint pensado para el
 * PANEL, que ya conoce el vocabulario interno del sistema. El modelo del otro
 * lado no lo conoce, y eso se ve en producción. Caso real (MCP, 2026-08-30):
 *
 *   «ambas transacciones vienen con `transactionComplete: 0` y
 *    `transactionType: 3`, lo que SUGIERE documentos emitidos a crédito o
 *    pendientes de cierre»
 *
 * Acertó de casualidad y lo marcó como especulación siendo un dato exacto. Un
 * modelo que adivina bien hoy adivina mal mañana, y el dueño del comercio no
 * tiene cómo distinguir los dos casos. Nadie sabe tampoco qué son `usold`,
 * `cogs`, `nonAddingTotal` o `smonth` sin leer el PHP, y ningún monto dice en
 * qué moneda está.
 *
 * ── Motor acá, vocabulario en `tool-field-rules.ts` ─────────────────────────
 * Este archivo es el RECORRIDO: cómo se camina la respuesta, qué se poda, cómo
 * se arma el sobre. Qué significa cada campo vive en el diccionario de al lado.
 * Se separan porque cambian por motivos distintos: una columna nueva en un
 * reporte toca el vocabulario y nunca el motor.
 *
 * ── Por qué CENTRAL y no traducción por tool ───────────────────────────────
 * El diccionario es por NOMBRE DE CAMPO y el walker lo aplica a cualquier
 * objeto, a cualquier profundidad, venga de la tool que venga. Diecinueve
 * bloques de traducción copiados dentro de cada `execute` serían diecinueve
 * lugares donde `transactionType` puede significar cosas distintas —
 * exactamente el problema que `lib/domain/sale-type.ts` tuvo que arreglar
 * cuando el mismo mapa estaba triplicado. Un campo que aparece en cinco
 * reportes se traduce UNA vez, y un endpoint nuevo sale traducido gratis si sus
 * columnas ya están en el diccionario.
 */

// ── Tope de filas ────────────────────────────────────────────────────────────

/**
 * Máximo de filas que viaja en una respuesta.
 *
 * `get_transactions` puede traer hasta 5000 filas y hasta hoy viajaban todas
 * (pendiente anotado en `context/_handoff.md`). No es solo costo de tokens: un
 * contexto inundado de filas crudas empeora la respuesta, porque el modelo se
 * pone a resumir en vez de responder. El recorte se DECLARA en `meta` —
 * recortar en silencio haría que sume un total sobre una parte y lo presente
 * como el total, que es peor que no tener el dato.
 */
export const MAX_ROWS = 200

/**
 * Claves que el walker no toca NUNCA.
 *
 * `error` es el canal de fallo de las tools: podarlo o renombrarlo escondería
 * el fallo detrás de algo que parece una respuesta vacía pero exitosa. `meta`
 * es el sobre que agrega este mismo archivo.
 */
const RESERVED_KEYS = new Set(["error", "meta"])

// ── Resultado del recorrido ──────────────────────────────────────────────────

export interface NormalizedResult {
  /** El payload traducido y podado. */
  value: unknown
  /**
   * Campos monetarios que aparecieron. Vacío significa que NO hace falta
   * resolver la moneda — de ahí sale la laziness del resolver en `read-tools`:
   * la mayoría de las lecturas (categorías, marcas, usuarios) no lleva montos y
   * no debe pagar un fetch de settings.
   */
  moneyFields: string[]
  /** Advertencias que aportaron las reglas aplicadas, sin repetir. */
  notes: string[]
  /** Presente solo si se recortaron filas. */
  truncated?: { returned: number; total: number }
}

// ── Recorrido ────────────────────────────────────────────────────────────────

interface WalkState {
  moneyFields: Set<string>
  notes: Set<string>
}

/**
 * Un campo sin valor no viaja.
 *
 * `null`, `undefined` y `""` significan lo mismo —no hay dato— y ocupan lugar
 * en el contexto invitando a interpretarlos. El `smonth: null` del reporte de
 * productos es el caso testigo: no aporta nada y el modelo se pregunta qué es.
 * La ausencia comunica exactamente lo mismo, gratis.
 *
 * OJO: `0` y `false` NO son "sin valor" — un stock en 0 es un dato. Qué se hace
 * con ellos lo decide el diccionario, campo por campo.
 */
function isEmptyValue(v: unknown): boolean {
  return v === null || v === undefined || (typeof v === "string" && v.trim() === "")
}

/** Primera regla cuyo `when` pasa. Sin `when`, la regla matchea siempre. */
function matchRule(
  key: string,
  value: unknown,
  row: Record<string, unknown>,
): FieldRule | undefined {
  const rules = FIELD_RULES[key]
  if (!rules) return undefined
  return rules.find((r) => !r.when || r.when(value, row))
}

function applyRule(
  key: string,
  value: unknown,
  rule: FieldRule,
  state: WalkState,
): { key: string; value: unknown } | null {
  const dropped = rule.drop === true || (typeof rule.drop === "function" && rule.drop(value))

  // La nota se registra igual cuando la regla PODA: buena parte de las notas
  // existen justamente para explicar una ausencia ("el margen no viaja porque
  // no hay costo cargado"). Sin eso, el modelo ve un campo faltante y no sabe
  // si es que no hay dato o que nunca lo hubo.
  if (rule.note) state.notes.add(rule.note)
  if (dropped) return null

  const outKey = rule.rename ?? key
  const outValue = rule.translate ? rule.translate(value) : value
  // Una traducción que devuelve `undefined` es una poda condicional; el JSON no
  // transporta `undefined` y mandar la clave con ese valor la haría desaparecer
  // en silencio del lado del cliente.
  if (outValue === undefined) return null

  if (rule.money) state.moneyFields.add(outKey)
  return { key: outKey, value: outValue }
}

function walk(input: unknown, state: WalkState): unknown {
  if (Array.isArray(input)) return input.map((v) => walk(v, state))
  if (input === null || typeof input !== "object") return input

  // Solo objetos PLANOS: el diccionario describe filas de datos, no instancias
  // (`Date`, `Map`, etc.), que se dejan intactas.
  const proto = Object.getPrototypeOf(input)
  if (proto !== Object.prototype && proto !== null) return input

  const row = input as Record<string, unknown>
  const out: Record<string, unknown> = {}

  for (const [key, raw] of Object.entries(row)) {
    if (RESERVED_KEYS.has(key)) {
      out[key] = raw
      continue
    }
    if (isEmptyValue(raw)) continue

    // `matchRule` recibe la fila CRUDA, con las claves originales: las reglas
    // que resuelven colisiones miran los hermanos (`"onHand" in row`,
    // `"transactionId" in row`) y tienen que verlos sin renombrar.
    const rule = matchRule(key, raw, row)
    if (!rule) {
      // Sin regla, el campo viaja CRUDO. Es el caso honesto: preferimos que el
      // modelo vea `transactionStatus: 4` y dude, antes que darle un nombre que
      // inventamos y del que no va a dudar.
      out[key] = walk(raw, state)
      continue
    }

    const applied = applyRule(key, raw, rule, state)
    if (applied === null) continue
    out[applied.key] = walk(applied.value, state)
  }

  return out
}

/**
 * Recorta la lista de filas de nivel superior, si hay una.
 *
 * Solo mira el tope: una lista anidada corta —los pagos de una venta, los
 * depósitos de un ítem— es parte del dato, no volumen. Lo que desborda son
 * siempre las filas del reporte, que llegan como array pelado o dentro de
 * `rows` (las dos formas que ya contemplaban `get_stock` y `get_top_products`).
 */
function capRows(payload: unknown): {
  payload: unknown
  truncated?: { returned: number; total: number }
} {
  if (Array.isArray(payload) && payload.length > MAX_ROWS) {
    return {
      payload: payload.slice(0, MAX_ROWS),
      truncated: { returned: MAX_ROWS, total: payload.length },
    }
  }
  if (payload && typeof payload === "object" && !Array.isArray(payload)) {
    const rows = (payload as { rows?: unknown }).rows
    if (Array.isArray(rows) && rows.length > MAX_ROWS) {
      return {
        payload: { ...(payload as object), rows: rows.slice(0, MAX_ROWS) },
        truncated: { returned: MAX_ROWS, total: rows.length },
      }
    }
  }
  return { payload }
}

/**
 * Traduce y poda un payload. NO resuelve la moneda: solo reporta si hizo falta.
 *
 * La moneda queda afuera a propósito — es lo único que exige una llamada de red,
 * y dejándola afuera esta función es pura, o sea testeable sin mockear nada.
 */
export function normalizeToolResult(payload: unknown): NormalizedResult {
  // Un error de tool pasa intacto: es el contrato de fallo del catálogo y
  // normalizarlo lo volvería indistinguible de una respuesta vacía.
  if (payload && typeof payload === "object" && !Array.isArray(payload) && "error" in payload) {
    return { value: payload, moneyFields: [], notes: [] }
  }

  const { payload: capped, truncated } = capRows(payload)
  const state: WalkState = { moneyFields: new Set(), notes: new Set() }
  const value = walk(capped, state)
  return { value, moneyFields: [...state.moneyFields], notes: [...state.notes], truncated }
}

// ── El sobre ─────────────────────────────────────────────────────────────────

/**
 * Envuelve el resultado en `{ meta, data }` — SOLO si hay algo que declarar.
 *
 * `meta` no es decoración: es el portador de los hechos que las filas no pueden
 * enunciar por sí mismas —en qué moneda están los montos, que la lista viene
 * recortada, que un neto incluye devoluciones—. Cuando no hay ninguno de esos
 * hechos (una lista de marcas, por ejemplo) el sobre sería un envoltorio más
 * para que el modelo desarme, así que la respuesta va pelada.
 *
 * La moneda va UNA vez en el sobre y no pegada a cada valor: una lista de 200
 * transacciones con 4 montos por fila repetiría la etiqueta 800 veces para
 * decir lo mismo, y un monto con la unidad adentro (`"Gs 1.230.000"`) deja de
 * ser un número con el que el modelo pueda operar.
 */
export function withMeta(result: NormalizedResult, currencyLabel: string | null): unknown {
  const meta: Record<string, unknown> = {}

  if (result.moneyFields.length > 0) {
    meta.currency = currencyLabel
    meta.amountFields = result.moneyFields
    meta.currencyNote =
      currencyLabel === null
        ? "Los campos de monto son números sin unidad: el negocio no tiene configurada ni moneda ni país, así que la moneda no se puede afirmar."
        : `Todos los campos de monto están expresados en ${currencyLabel}.`
  }

  if (result.truncated) {
    meta.truncated = result.truncated
    meta.truncatedNote =
      `Se devuelven ${result.truncated.returned} de ${result.truncated.total} filas. ` +
      "Un total calculado sobre esta muestra es parcial: acotá el rango de fechas para ver el resto."
  }

  if (result.notes.length > 0) meta.notes = result.notes

  if (Object.keys(meta).length === 0) return result.value
  return { meta, data: result.value }
}
