/**
 * Troceo del texto para el TTS del agente (`context/80-voz-del-agente.md`).
 *
 * POR QUÉ EXISTE — la latencia de Gemini TTS escala con el largo del texto y
 * mal: ~6s para 200 caracteres, ~144s para 1100 (medido contra el endpoint
 * real 2026-09-17), y el primer byte llega recién al FINAL de la generación
 * (TTFB 142s de 144s), así que streamear la respuesta no ayuda en nada. Una
 * respuesta larga del agente tardaba ~48s en empezar a sonar.
 *
 * La salida: pedir el audio POR ORACIONES agrupadas en pedazos chicos, EN
 * PARALELO, y reproducirlos en secuencia. El primer pedazo es más corto que el
 * resto a propósito — es el que define cuánto espera el usuario para escuchar
 * ALGO; los siguientes se generan mientras el primero suena (cada pedazo
 * produce más segundos de audio de los que tarda en generarse, así que la
 * reproducción nunca alcanza a la generación).
 *
 * El cobro no cambia: cada pedazo pasa por el MISMO gate y débito del BFF, y
 * la suma de caracteres es la del texto entero.
 */

/** Tope del PRIMER pedazo — define la espera hasta que empieza a sonar. */
const FIRST_CHUNK_CHARS = 180
/** Tope del resto — más grande: se generan mientras el primero suena. */
const REST_CHUNK_CHARS = 300

/**
 * Corta el texto en pedazos aptos para el TTS respetando límites de oración
 * (y de palabra como último recurso: cortar a mitad de palabra se ESCUCHA).
 * Devuelve al menos un pedazo para todo texto no vacío; el orden concatenado
 * conserva todas las palabras del original.
 */
export function splitTextForTts(text: string): string[] {
  const trimmed = text.trim()
  if (trimmed === "") return []

  // Oraciones: corte después de puntuación fuerte o salto de línea. El salto
  // de línea cuenta porque el agente responde con listas — cada ítem es una
  // "oración" aunque no termine en punto.
  const sentences = trimmed
    .split(/(?<=[.!?…])\s+|\n+/)
    .map((s) => s.trim())
    .filter((s) => s !== "")

  const chunks: string[] = []
  let current = ""
  const maxFor = () => (chunks.length === 0 ? FIRST_CHUNK_CHARS : REST_CHUNK_CHARS)

  const push = () => {
    if (current !== "") {
      chunks.push(current)
      current = ""
    }
  }

  for (const sentence of sentences) {
    // Una oración que sola ya excede el tope se corta por palabras.
    const pieces = sentence.length > REST_CHUNK_CHARS ? splitByWords(sentence, REST_CHUNK_CHARS) : [sentence]
    for (const piece of pieces) {
      const candidate = current === "" ? piece : `${current} ${piece}`
      if (candidate.length > maxFor() && current !== "") {
        push()
        current = piece
      } else {
        current = candidate
      }
    }
  }
  push()
  return chunks
}

/** Corta por palabras sin pasarse de `max`; una "palabra" más larga que `max` se corta dura. */
function splitByWords(sentence: string, max: number): string[] {
  const out: string[] = []
  let current = ""
  for (const word of sentence.split(/\s+/)) {
    const pieces = word.length > max ? (word.match(new RegExp(`.{1,${max}}`, "g")) ?? []) : [word]
    for (const piece of pieces) {
      const candidate = current === "" ? piece : `${current} ${piece}`
      if (candidate.length > max && current !== "") {
        out.push(current)
        current = piece
      } else {
        current = candidate
      }
    }
  }
  if (current !== "") out.push(current)
  return out
}

/**
 * `map` con tope de concurrencia que CONSERVA el orden. Devuelve una promesa
 * por elemento de entrada (no una del total): el consumidor puede esperar la
 * primera y empezar a reproducir mientras las demás siguen en vuelo — que es
 * el punto entero del troceo.
 *
 * El tope existe para no disparar 14 requests simultáneas contra el BFF (y de
 * ahí al proveedor) por un solo click: 3 en vuelo alcanzan de sobra para que
 * la generación siempre vaya adelante de la reproducción.
 */
export function mapWithConcurrency<T, R>(
  items: T[],
  limit: number,
  fn: (item: T, index: number) => Promise<R>,
): Promise<R>[] {
  let next = 0
  const results: Promise<R>[] = []
  const resolvers: ((value: R | PromiseLike<R>) => void)[] = []
  const rejectors: ((reason?: unknown) => void)[] = []

  for (let i = 0; i < items.length; i++) {
    results.push(
      new Promise<R>((resolve, reject) => {
        resolvers[i] = resolve
        rejectors[i] = reject
      }),
    )
  }

  const workers = Math.max(1, Math.min(limit, items.length))
  const run = async () => {
    while (next < items.length) {
      const i = next++
      try {
        resolvers[i](await fn(items[i], i))
      } catch (e) {
        rejectors[i](e)
      }
    }
  }
  for (let w = 0; w < workers; w++) void run()

  return results
}
