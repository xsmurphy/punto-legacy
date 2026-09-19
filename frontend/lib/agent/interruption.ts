import type { AgentMessageMetadata } from "./truncation"
import { USER_FACING_STREAM_ERRORS } from "./turn-guard"

/**
 * Turno interrumpido: detección y marca, del lado del cliente.
 *
 * Es la contracara de `truncation.ts`. El corte por longitud lo DECLARA el
 * servidor, que sabe que llegó al techo. Una interrupción no la puede declarar
 * nadie del otro lado —se cayó el proveedor, Cloudflare cerró la conexión, se
 * reinició el contenedor—, así que la deduce el cliente al cerrar el turno:
 * un turno sano termina SIEMPRE con el chunk `finish`, que es lo que le llena
 * `finishReason` al `onFinish` de `useChat`. Sin eso, lo que haya llegado es
 * parcial aunque se vea entero.
 *
 * Lógica pura, sin React: la usa `use-turn-guard.ts` y se testea sola.
 */

/** Lo que `useChat` le pasa a `onFinish`, más lo que agrega el guard. */
export interface TurnEnd {
  finishReason?: string
  isAbort: boolean
  isDisconnect: boolean
  isError: boolean
  /** El guard cortó el turno por silencio (ningún dato ni latido). */
  stalled: boolean
  /** El error que reportó `useChat`, si hubo. */
  error?: unknown
}

export interface Interruption {
  detail?: string
}

/** ¿El error es el de falta de saldo? Ese tiene su propio aviso con su acción (comprar). */
function isNoCreditsError(error: unknown): boolean {
  const msg = error instanceof Error ? error.message : typeof error === "string" ? error : ""
  return msg.includes("Sin créditos") || msg.includes("402")
}

/**
 * Del texto de un error, la frase que se puede mostrar. Solo pasan las frases
 * que el servidor escribió PARA el usuario (`USER_FACING_STREAM_ERRORS`); un
 * "Failed to fetch" o el HTML de un proxy no se muestran nunca.
 */
function userFacingDetail(error: unknown): string | undefined {
  let msg = error instanceof Error ? error.message : typeof error === "string" ? error : ""
  try {
    const parsed = JSON.parse(msg) as { error?: unknown }
    if (typeof parsed?.error === "string") msg = parsed.error
  } catch {
    // no era JSON
  }
  msg = msg.trim()
  return USER_FACING_STREAM_ERRORS.includes(msg) ? msg : undefined
}

/**
 * ¿Este cierre de turno es una interrupción? `null` si terminó bien.
 *
 *  - cortado por el guard (silencio) → interrupción
 *  - abortado a propósito por otro motivo → no (lo pidió alguien)
 *  - sin saldo → no: tiene su propio aviso y su acción es comprar, no reintentar
 *  - error o desconexión → interrupción
 *  - cerrado sin `finish` → interrupción: el stream terminó antes de tiempo
 */
export function interruptionFor(end: TurnEnd): Interruption | null {
  if (end.stalled) return {}
  if (end.isAbort) return null
  if (end.isError && isNoCreditsError(end.error)) return null
  if (end.isError || end.isDisconnect) {
    const detail = end.isDisconnect ? undefined : userFacingDetail(end.error)
    return detail ? { detail } : {}
  }
  return end.finishReason == null ? {} : null
}

interface MessageLike {
  id: string
  role: string
  metadata?: unknown
  parts?: ReadonlyArray<{ type: string; text?: string; mediaType?: string; filename?: string; url?: string }>
}

/**
 * Marca el ÚLTIMO mensaje del hilo como interrumpido.
 *
 * Si alcanzó a llegar algo, el último es el del asistente y queda con su texto
 * parcial, marcado. Si no llegó nada, el último es el del usuario: la marca va
 * ahí, y significa "la respuesta a esto no llegó". No se inventa un mensaje del
 * asistente vacío para colgarle el aviso — un mensaje sin contenido en el
 * historial viajaría al modelo en el próximo turno.
 */
export function markLastInterrupted<M extends MessageLike>(messages: M[], interruption: Interruption): M[] {
  if (messages.length === 0) return messages
  const last = messages[messages.length - 1]
  const prev = (typeof last.metadata === "object" && last.metadata !== null ? last.metadata : {}) as AgentMessageMetadata
  const metadata: AgentMessageMetadata = { ...prev, interrupted: true }
  if (interruption.detail) metadata.interruptedDetail = interruption.detail
  return [...messages.slice(0, -1), { ...last, metadata }]
}

export function isInterrupted(message: { metadata?: unknown }): boolean {
  const meta = message.metadata
  if (typeof meta !== "object" || meta === null) return false
  return (meta as AgentMessageMetadata).interrupted === true
}

export function interruptedDetail(message: { metadata?: unknown }): string | undefined {
  const meta = message.metadata
  if (typeof meta !== "object" || meta === null) return undefined
  const d = (meta as AgentMessageMetadata).interruptedDetail
  return typeof d === "string" && d !== "" ? d : undefined
}

export interface RetryPayload {
  text: string
  files?: Array<{ type: "file"; mediaType: string; filename?: string; url: string }>
}

/**
 * Qué reenvía "Reintentar" para el mensaje `messageId`: la pregunta del usuario
 * que originó ese turno (el mismo mensaje si es del usuario, o el último del
 * usuario antes de él si es del asistente), con su texto y sus adjuntos.
 *
 * Se reenvía como mensaje NUEVO y no con `regenerate()`: regenerar borra la
 * respuesta interrumpida, y el texto parcial que alcanzó a llegar se conserva.
 */
export function retryPayloadFor(messages: readonly MessageLike[], messageId: string): RetryPayload | null {
  const idx = messages.findIndex((m) => m.id === messageId)
  if (idx === -1) return null
  for (let i = idx; i >= 0; i--) {
    const m = messages[i]
    if (m.role !== "user") continue
    const parts = m.parts ?? []
    const text = parts
      .filter((p) => p.type === "text" && typeof p.text === "string")
      .map((p) => p.text as string)
      .join("\n")
    const files = parts
      .filter((p) => p.type === "file" && typeof p.url === "string" && typeof p.mediaType === "string")
      .map((p) => ({
        type: "file" as const,
        mediaType: p.mediaType as string,
        url: p.url as string,
        ...(p.filename ? { filename: p.filename } : {}),
      }))
    if (text.trim() === "" && files.length === 0) return null
    return files.length > 0 ? { text, files } : { text }
  }
  return null
}

/** ¿El silencio acumulado ya supera el tope? */
export function isStalled(lastActivityAt: number, now: number, stallMs: number): boolean {
  return now - lastActivityAt >= stallMs
}
