/**
 * Límites de tiempo del turno del asistente — un solo lugar para los dos
 * routes (panel y caja) y para el cliente que los consume.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE
 *
 * Hasta 2026-09-18 el turno del asistente no tenía NINGÚN tope de tiempo real:
 *
 *  - Los `fetch` de las tools al backend no tenían timeout: una lectura que no
 *    contestaba colgaba el turno entero.
 *  - El `export const maxDuration = 60` de los routes es DECORATIVO acá. Es una
 *    directiva para la plataforma de Vercel: en Next 16 la leen solo el build y
 *    los manifiestos para adaptadores; `next start` (standalone, nuestro deploy
 *    detrás de Traefik + Cloudflare) no la consulta en ningún momento.
 *  - El cliente (`useChat`) espera para siempre mientras la conexión siga
 *    abierta, y si la conexión se cierra SIN el chunk `finish` lo toma como un
 *    final normal: el texto a medias queda en pantalla con cara de respuesta
 *    completa.
 *
 * Resultado en producción: un mensaje enviado con el indicador de espera
 * girando para siempre, y respuestas cortadas a mitad sin ningún aviso.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * LAS TRES PIEZAS
 *
 * 1. `guardTools` — timeout POR TOOL. Al vencer, la tool devuelve un resultado
 *    de error legible para el MODELO (no una excepción), así el modelo contesta
 *    algo útil ("probá acotar el período") en vez de dejar el turno colgado.
 * 2. `guardUIStream` — sobre el stream que va al navegador:
 *      a) LATIDO: si no salió ningún chunk en `heartbeatMs`, manda un data part
 *         transitorio (`data-heartbeat`). No se guarda en el mensaje; solo le
 *         dice al cliente "sigo vivo". Mantiene además abierta la conexión a
 *         través de Cloudflare, que corta un proxy sin bytes a los 100 s.
 *      b) TOPE DEL TURNO: al pasar `turnTimeoutMs` emite un chunk `error` con
 *         copy para el usuario, cierra el stream y aborta la generación.
 * 3. El cliente (`use-turn-guard.ts`) corta si no recibe NADA —ni texto ni
 *    latido— durante `CLIENT_STALL_MS`, y marca como interrumpida toda
 *    respuesta que no terminó con un `finish` normal.
 */

// ── Números ──────────────────────────────────────────────────────────────────

/**
 * Tope por tool. Una lectura sana del backend tarda milisegundos; 25 s es
 * holgura de sobra para un reporte pesado y deja lugar a que el modelo, con el
 * error en la mano, todavía conteste dentro del tope del turno.
 */
export const TOOL_TIMEOUT_MS = 25_000

/**
 * Tope del turno completo en el PANEL. Diez pasos como máximo (`stepCountIs`)
 * con tools de hasta 25 s no entran enteros en ningún número razonable, y no
 * hace falta: un turno de más de dos minutos ya no es una respuesta que alguien
 * esté esperando mirando la pantalla.
 */
export const PANEL_TURN_TIMEOUT_MS = 120_000

/**
 * Tope del turno en la CAJA: seis pasos y respuestas de mostrador. Si pasa un
 * minuto la fila ya decidió por el cajero.
 */
export const POS_TURN_TIMEOUT_MS = 60_000

/** Cada cuánto, como máximo, sale un latido cuando el stream está quieto. */
export const HEARTBEAT_MS = 10_000

/**
 * Silencio máximo que tolera el CLIENTE antes de dar el turno por cortado.
 *
 * No depende de cuánto tarda una tool ni el modelo: mientras el servidor esté
 * vivo manda un latido cada `HEARTBEAT_MS`. Lo que tiene que cubrir es la fase
 * ANTERIOR al stream —el route lee config del modelo, saldo y ajustes antes de
 * responder, tres lecturas con `INFRA_FETCH_TIMEOUT_MS` cada una, 30 s en el
 * peor caso— más un par de latidos perdidos por la red. 45 s cubre eso con
 * margen y sigue siendo un tiempo que una persona tolera antes de irse.
 */
export const CLIENT_STALL_MS = 45_000

/** Tope de las lecturas de infraestructura del route (config, saldo, ajustes). */
export const INFRA_FETCH_TIMEOUT_MS = 10_000

// ── Copy ─────────────────────────────────────────────────────────────────────

/**
 * Lo que recibe el MODELO cuando una tool de lectura no contesta a tiempo. Está
 * escrito para él: le dice qué pasó y qué proponer, para que no lo narre como
 * una falla del sistema ni reintente la misma consulta.
 */
export const TOOL_TIMEOUT_READ_RESULT =
  "La consulta tardó demasiado y se canceló. No reintentes la misma consulta: " +
  "decile al usuario que no pudiste traer ese dato a tiempo y proponé acotarla " +
  "(un período más corto, una sucursal, menos filas)."

/**
 * Para una tool que ESCRIBE. Acá no se puede decir "falló": el backend pudo
 * haber aplicado el cambio aunque la respuesta no llegó, y reintentarlo lo
 * duplicaría.
 */
export const TOOL_TIMEOUT_WRITE_RESULT =
  "La operación no respondió a tiempo y no se sabe si se aplicó. NO la " +
  "reintentes: decile al usuario que revise en el panel si el cambio quedó " +
  "hecho antes de volver a pedirlo."

/** Tools que escriben: su timeout no se puede reportar como "no pasó nada". */
export const MUTATING_TOOLS: ReadonlySet<string> = new Set(["execute_action"])

/** Error que ve el USUARIO cuando el turno supera su tope. Una línea, accionable. */
export const TURN_TIMEOUT_COPY =
  "La respuesta tardó demasiado. Probá con una pregunta más acotada, por ejemplo un período más corto."

/** Error que ve el USUARIO ante cualquier otra falla del stream. */
export const STREAM_ERROR_COPY = "No se pudo completar la respuesta."

/**
 * Las frases del servidor que el cliente puede mostrar tal cual como detalle
 * del aviso. Todo lo demás (un "Failed to fetch", un HTML de un proxy) queda
 * fuera de pantalla: son datos técnicos (context/14 §Regla 8).
 */
export const USER_FACING_STREAM_ERRORS: readonly string[] = [TURN_TIMEOUT_COPY, STREAM_ERROR_COPY]

// ── 1. Timeout por tool ──────────────────────────────────────────────────────

export type ToolOutcome = "ok" | "error" | "timeout" | "threw"

export interface ToolSettled {
  tool: string
  ms: number
  outcome: ToolOutcome
}

interface ExecOptions {
  abortSignal?: AbortSignal
  [k: string]: unknown
}

type AnyExecute = (input: unknown, options?: ExecOptions) => unknown

/**
 * Envuelve el `execute` de cada tool del set con un timeout, sin tocar las
 * definiciones: se aplica en el BORDE (el route), así cubre las tools del
 * catálogo compartido, las de onboarding, las de facturación y las de acción
 * con una sola línea.
 *
 * Al vencer:
 *  - aborta el `abortSignal` que recibe la tool (la que lo use corta su fetch);
 *  - devuelve `{ error }` con copy para el modelo, NO tira: una excepción el SDK
 *    la entrega como `tool-error` y el modelo suele narrarla como falla interna.
 *
 * Una tool que tira por su cuenta sigue tirando (no se cambia su contrato):
 * solo se mide y se reporta.
 */
export function guardTools<T extends Record<string, unknown>>(
  tools: T,
  opts: {
    timeoutMs?: number
    onSettled?: (e: ToolSettled) => void
    mutating?: ReadonlySet<string>
    now?: () => number
  } = {},
): T {
  const timeoutMs = opts.timeoutMs ?? TOOL_TIMEOUT_MS
  const mutating = opts.mutating ?? MUTATING_TOOLS
  const now = opts.now ?? Date.now
  const out: Record<string, unknown> = {}

  for (const [name, def] of Object.entries(tools)) {
    const execute = (def as { execute?: AnyExecute } | undefined)?.execute
    if (typeof execute !== "function") {
      out[name] = def
      continue
    }

    const guarded: AnyExecute = async (input, options) => {
      const started = now()
      const local = new AbortController()
      const parent = options?.abortSignal
      const signal = parent ? AbortSignal.any([parent, local.signal]) : local.signal

      let timer: ReturnType<typeof setTimeout> | undefined
      const timedOut = new Promise<typeof TIMED_OUT>((resolve) => {
        timer = setTimeout(() => resolve(TIMED_OUT), timeoutMs)
      })

      try {
        const result = await Promise.race([
          Promise.resolve(execute(input, { ...options, abortSignal: signal })),
          timedOut,
        ])
        if (result === TIMED_OUT) {
          local.abort(new Error(`tool ${name} timeout after ${timeoutMs}ms`))
          opts.onSettled?.({ tool: name, ms: now() - started, outcome: "timeout" })
          return { error: mutating.has(name) ? TOOL_TIMEOUT_WRITE_RESULT : TOOL_TIMEOUT_READ_RESULT }
        }
        const isError = typeof result === "object" && result !== null && "error" in result
        opts.onSettled?.({ tool: name, ms: now() - started, outcome: isError ? "error" : "ok" })
        return result
      } catch (err) {
        opts.onSettled?.({ tool: name, ms: now() - started, outcome: "threw" })
        throw err
      } finally {
        clearTimeout(timer)
      }
    }

    out[name] = { ...(def as object), execute: guarded }
  }

  return out as T
}

const TIMED_OUT = Symbol("timed-out")

// ── 2. Latido + tope del turno sobre el stream al navegador ──────────────────

/** Forma mínima de un chunk del UI message stream que este guard necesita leer. */
export interface StreamChunk {
  type: string
  [k: string]: unknown
}

export type StreamEnd =
  | { kind: "completed" }
  | { kind: "turn-timeout" }
  | { kind: "client-gone" }
  | { kind: "source-error"; error: unknown }

/**
 * Envuelve el stream de chunks que va al navegador:
 *
 *  - reenvía todo tal cual;
 *  - si pasa `heartbeatMs` sin que salga nada, emite un `data-heartbeat`
 *    TRANSITORIO (el cliente lo recibe por `onData`, nunca se guarda);
 *  - a los `turnTimeoutMs` emite `{type:"error", errorText: TURN_TIMEOUT_COPY}`,
 *    cierra, y llama `onTurnTimeout` para que el route aborte la generación;
 *  - si el navegador se va (`cancel`), llama `onClientGone`.
 *
 * `onEnd` se llama UNA vez, con el motivo, cuando el stream termina por
 * cualquier camino — es de donde sale el log del turno.
 */
export function guardUIStream<C extends StreamChunk>(
  source: ReadableStream<C>,
  opts: {
    heartbeatMs?: number
    turnTimeoutMs: number
    onTurnTimeout?: () => void
    onClientGone?: () => void
    onChunk?: (chunk: C) => void
    onEnd?: (end: StreamEnd) => void
    now?: () => number
  },
): ReadableStream<C> {
  const heartbeatMs = opts.heartbeatMs ?? HEARTBEAT_MS
  const now = opts.now ?? Date.now
  const reader = source.getReader()

  let closed = false
  let lastSentAt = now()
  let heartbeatTimer: ReturnType<typeof setInterval> | undefined
  let turnTimer: ReturnType<typeof setTimeout> | undefined

  const stopTimers = () => {
    clearInterval(heartbeatTimer)
    clearTimeout(turnTimer)
  }

  const end = (e: StreamEnd) => {
    if (closed) return
    closed = true
    stopTimers()
    opts.onEnd?.(e)
  }

  return new ReadableStream<C>({
    start(controller) {
      const send = (chunk: C) => {
        if (closed) return
        lastSentAt = now()
        controller.enqueue(chunk)
      }

      heartbeatTimer = setInterval(() => {
        if (closed) return
        if (now() - lastSentAt < heartbeatMs) return
        send({ type: "data-heartbeat", data: { at: now() }, transient: true } as unknown as C)
      }, Math.max(250, Math.floor(heartbeatMs / 2)))

      turnTimer = setTimeout(() => {
        if (closed) return
        send({ type: "error", errorText: TURN_TIMEOUT_COPY } as unknown as C)
        end({ kind: "turn-timeout" })
        try {
          controller.close()
        } catch {
          // ya cerrado por el otro lado
        }
        reader.cancel().catch(() => {})
        opts.onTurnTimeout?.()
      }, opts.turnTimeoutMs)

      void (async () => {
        try {
          for (;;) {
            const { done, value } = await reader.read()
            if (done) break
            if (closed) return
            opts.onChunk?.(value)
            send(value)
          }
          if (closed) return
          end({ kind: "completed" })
          controller.close()
        } catch (error) {
          if (closed) return
          end({ kind: "source-error", error })
          try {
            controller.error(error)
          } catch {
            // ya cerrado
          }
        }
      })()
    },
    cancel() {
      if (closed) return
      end({ kind: "client-gone" })
      reader.cancel().catch(() => {})
      opts.onClientGone?.()
    },
  })
}
