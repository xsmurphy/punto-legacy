/**
 * Comparar una cara contra las del comercio (RRHH F2, context/83 D4 y D5).
 *
 * Este archivo es ARITMÉTICA PURA: entran números, salen números. No toca la
 * cámara, ni el DOM, ni la red, ni carga un modelo. Está separado del motor
 * (`face-engine.ts`) a propósito — es la parte que decide a quién se le acredita
 * una jornada de trabajo, y esa parte tiene que poder probarse sin una webcam.
 *
 * ── Qué es un "vector" acá ─────────────────────────────────────────────────
 *
 * El modelo mira una cara y devuelve 128 números. No son la foto y de ellos no
 * se recupera la foto: funcionan como una firma. Dos firmas de la misma persona
 * quedan cerca; las de personas distintas, lejos. Todo lo que sigue es medir esa
 * distancia y decidir si alcanza.
 *
 * ── Identificar NUNCA es lo mismo que autorizar (D4) ───────────────────────
 *
 * Nada de lo que devuelve este archivo bloquea a nadie. Un match propone un
 * nombre y ahorra tipear cuatro dígitos; la ausencia de match no impide marcar,
 * solo deja el camino del código, que es el de la F1 y siempre está.
 *
 * Por eso los umbrales se eligen CONSERVADORES —más estrictos que el default de
 * la librería— y no al revés: el costo de no reconocer a alguien es que tipee su
 * código, y el costo de reconocer mal es acreditarle la entrada de una persona a
 * otra. No son comparables, así que el umbral no se elige "para que ande".
 */

/**
 * El modelo con el que se calculan estos vectores.
 *
 * Espejo EXACTO de `EmployeeFaceService::SUPPORTED_MODELS` en el backend, que
 * tiene la lista cerrada y el largo esperado. Cambiar de modelo obliga a tocar
 * los dos lados en el mismo deploy: vectores de modelos distintos no son
 * comparables entre sí y —esto es lo que lo hace peligroso— la comparación no
 * falla, devuelve un número. Ver el docblock de la mig 231.
 */
export const FACE_MODEL_VERSION = "face-api-recognition-128"

/** Largo del vector de este modelo. */
export const FACE_EMBEDDING_DIMS = 128

/**
 * Distancia máxima para dar a alguien por reconocido.
 *
 * Distancia coseno sobre vectores unitarios: 0 es "idénticos", 1 es "sin
 * relación". El valor equivalente al default publicado de la librería rondaría
 * 0,18; acá se usa menos, que es la decisión de arriba: preferimos que alguien
 * tipee su código a que la caja le acredite la entrada a otro.
 */
export const FACE_MAX_DISTANCE = 0.14

/**
 * Cuánto tiene que ganarle el mejor candidato al segundo.
 *
 * Sin este margen, dos personas parecidas —hermanos, un equipo con barbijo, dos
 * caras mal iluminadas— se turnan para ganar por diferencias mínimas, y el
 * resultado cambia de un cuadro al siguiente. Cuando la diferencia es chica la
 * respuesta honesta es "no sé", y "no sé" acá significa simplemente pedir el
 * código.
 */
export const FACE_MIN_MARGIN = 0.04

/** Una cara registrada, tal como baja del servidor. */
export interface FaceCandidate {
  employeeId: string
  embedding: number[]
  /** Con qué modelo se calculó. Si no es el nuestro, no se compara. */
  modelVersion: string
}

export interface FaceMatch {
  employeeId: string
  /** Qué tan cerca quedó. Menor es mejor. */
  distance: number
}

/**
 * Por qué no hubo match. Sirve para que la pantalla diga algo distinto en cada
 * caso — y para que el motivo de revisión de la marcación sea el correcto.
 *
 *   'no_candidates' → nadie de esta sucursal tiene rostro registrado
 *   'too_far'       → había caras registradas y ninguna se parece
 *   'ambiguous'     → dos se parecen demasiado entre sí como para elegir
 */
export type FaceMissReason = "no_candidates" | "too_far" | "ambiguous"

export type FaceMatchResult =
  | { matched: true; match: FaceMatch }
  | { matched: false; reason: FaceMissReason; best: FaceMatch | null }

/**
 * Norma L2 de un vector. Exportada porque la usan `toUnit` y los tests.
 */
export function norm(vec: readonly number[]): number {
  let sum = 0
  for (const n of vec) sum += n * n
  return Math.sqrt(sum)
}

/**
 * Lleva el vector a longitud 1, o devuelve `null` si no se puede.
 *
 * Normalizar antes de comparar hace que la distancia dependa solo de la
 * DIRECCIÓN del vector. Es lo que permite que el umbral sea un número fijo y no
 * algo que haya que recalibrar según qué tan "fuerte" salió cada lectura.
 *
 * `null` —y no una excepción— para un vector de ceros o con valores inválidos:
 * un dato roto acá significa "no se pudo comparar", que es un resultado legítimo
 * de esta pantalla, no un error del programa.
 */
export function toUnit(vec: readonly number[]): number[] | null {
  if (vec.length === 0) return null
  for (const n of vec) {
    if (!Number.isFinite(n)) return null
  }
  const n = norm(vec)
  if (n <= 1e-9) return null
  return vec.map((v) => v / n)
}

/**
 * Distancia coseno entre dos vectores. 0 = idénticos, 2 = opuestos.
 *
 * Normaliza por las dudas: recibir vectores ya unitarios es lo normal, pero el
 * costo de asegurarlo es una raíz cuadrada y el costo de NO asegurarlo es una
 * distancia fuera de escala comparada contra un umbral fijo.
 *
 * Devuelve `Number.POSITIVE_INFINITY` cuando no hay nada que comparar (largos
 * distintos, vector nulo): "infinitamente lejos" es exactamente lo que significa,
 * y hace que nunca gane un candidato roto.
 */
export function cosineDistance(a: readonly number[], b: readonly number[]): number {
  if (a.length !== b.length) return Number.POSITIVE_INFINITY
  const ua = toUnit(a)
  const ub = toUnit(b)
  if (!ua || !ub) return Number.POSITIVE_INFINITY
  let dot = 0
  for (let i = 0; i < ua.length; i++) dot += ua[i] * ub[i]
  return 1 - dot
}

/**
 * Promedio de varias lecturas de la misma cara, normalizado.
 *
 * Lo usa el enrolamiento antes de mandar las tomas —el servidor vuelve a
 * promediar por su cuenta, porque no puede confiar en que el cliente lo haya
 * hecho— y también la identificación, para no decidir con un solo cuadro: un
 * frame con la persona a mitad de un pestañeo o girando la cabeza es peor que el
 * promedio de tres seguidos.
 *
 * `null` si no hay nada utilizable.
 */
export function averageEmbedding(vectors: readonly (readonly number[])[]): number[] | null {
  const units = vectors.map(toUnit).filter((v): v is number[] => v !== null)
  if (units.length === 0) return null
  const dims = units[0].length
  // Largos mezclados = modelos mezclados. Promediarlos daría un vector que no
  // es de nadie; se descarta todo en vez de inventar uno.
  if (units.some((v) => v.length !== dims)) return null

  const sum = new Array<number>(dims).fill(0)
  for (const v of units) {
    for (let i = 0; i < dims; i++) sum[i] += v[i]
  }
  return toUnit(sum.map((n) => n / units.length))
}

/**
 * A quién se parece esta cara, entre las registradas de la sucursal.
 *
 * Tres filtros, en este orden:
 *
 *   1. **Versión del modelo.** Un candidato de otra versión se descarta sin
 *      compararlo. No es una optimización: compararlo devolvería un número
 *      perfectamente válido y perfectamente sin sentido.
 *   2. **Umbral.** El mejor tiene que estar lo bastante cerca.
 *   3. **Margen.** Y lo bastante mejor que el segundo. Ver `FACE_MIN_MARGIN`.
 *
 * Devuelve por qué NO hubo match cuando no lo hay: la pantalla dice cosas
 * distintas según el caso, y "nadie tiene rostro registrado" no se le puede
 * mostrar a alguien igual que "no te reconocí".
 */
export function pickBestMatch(
  probe: readonly number[],
  candidates: readonly FaceCandidate[],
  options: {
    modelVersion?: string
    maxDistance?: number
    minMargin?: number
  } = {},
): FaceMatchResult {
  const modelVersion = options.modelVersion ?? FACE_MODEL_VERSION
  const maxDistance = options.maxDistance ?? FACE_MAX_DISTANCE
  const minMargin = options.minMargin ?? FACE_MIN_MARGIN

  const usable = candidates.filter(
    (c) => c.modelVersion === modelVersion && Array.isArray(c.embedding) && c.embedding.length > 0,
  )
  if (usable.length === 0) {
    return { matched: false, reason: "no_candidates", best: null }
  }

  const scored = usable
    .map((c) => ({ employeeId: c.employeeId, distance: cosineDistance(probe, c.embedding) }))
    .filter((s) => Number.isFinite(s.distance))
    .sort((a, b) => a.distance - b.distance)

  if (scored.length === 0) {
    // Había candidatos de la versión correcta pero ninguno comparable (vectores
    // rotos, largos distintos dentro del mismo modelo). Para quien mira la
    // pantalla es lo mismo que no tener a nadie registrado.
    return { matched: false, reason: "no_candidates", best: null }
  }

  const best = scored[0]
  if (best.distance > maxDistance) {
    return { matched: false, reason: "too_far", best }
  }

  const runnerUp = scored[1]
  if (runnerUp && runnerUp.distance - best.distance < minMargin) {
    return { matched: false, reason: "ambiguous", best }
  }

  return { matched: true, match: best }
}
