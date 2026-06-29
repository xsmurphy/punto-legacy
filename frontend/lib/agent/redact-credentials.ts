import type { UIMessage } from "ai"
import { isTextUIPart } from "ai"

/**
 * Detecta si un mensaje del assistant contiene una credencial visible.
 * Patrón generoso (case-insensitive, tolera markdown bold/italic):
 *   "contraseña: <algo>"  |  "password: <algo>"  |  "**Contraseña:** <algo>"
 *
 * Se usa para:
 *  - Redactar el texto del mensaje antes de persistir a localStorage (siempre).
 *  - Programar un timer client-side que reemplaza el contenido del mensaje en
 *    vivo a los N segundos (el user ve la contraseña una vez y se va).
 */
const CREDENTIAL_PATTERN = /(contrase(?:ñ|n)a|password)\s*\*{0,2}\s*:\s*\*{0,2}\s*\S+/i

const REDACTED_TEXT =
  "🔐 Contraseña entregada al operador. Por seguridad ya no es visible en el chat — debió guardarse al recibirla."

export function messageHasCredential(message: UIMessage): boolean {
  if (message.role !== "assistant") return false
  return message.parts.some(
    (p) => isTextUIPart(p) && CREDENTIAL_PATTERN.test(p.text),
  )
}

/**
 * Devuelve una copia del mensaje con el texto redactado si contenía
 * credencial. No muta el original. Si no había credencial, devuelve el
 * mismo objeto sin clonar (igualdad referencial para optimizar React).
 */
export function redactMessage<M extends UIMessage>(message: M): M {
  if (!messageHasCredential(message)) return message
  return {
    ...message,
    parts: message.parts.map((p) =>
      isTextUIPart(p) && CREDENTIAL_PATTERN.test(p.text)
        ? { ...p, text: REDACTED_TEXT }
        : p,
    ),
  } as M
}

export function redactMessages<M extends UIMessage>(messages: M[]): M[] {
  let anyChanged = false
  const out = messages.map((m) => {
    const r = redactMessage(m)
    if (r !== m) anyChanged = true
    return r
  })
  return anyChanged ? out : messages
}
