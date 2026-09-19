"use client"

import * as React from "react"
import type { UIMessage } from "ai"
import { interruptionFor, isStalled, markLastInterrupted, type TurnEnd } from "./interruption"
import { CLIENT_STALL_MS } from "./turn-guard"

/**
 * Guardia del turno del asistente en el CLIENTE. Un solo mecanismo para las dos
 * superficies (`use-agent-chat.ts` del panel y `use-pos-agent-chat.ts` de la
 * caja): ninguna de las dos puede quedar colgada ni muda.
 *
 * Hace dos cosas:
 *
 *  1. WATCHDOG: mientras el turno está en curso, si pasan `CLIENT_STALL_MS` sin
 *     que llegue NADA —ni texto, ni tools, ni el latido que el servidor manda
 *     cuando está ocupado (`guardUIStream`)— la conexión está muerta: corta el
 *     turno con `stop()`.
 *  2. CIERRE: al terminar el turno decide con `interruptionFor` si fue una
 *     interrupción (sin `finish`, error, desconexión o corte del watchdog) y, si
 *     lo fue, marca el último mensaje. La marca vive en `message.metadata`, así
 *     que se persiste con el historial y sobrevive a un reload.
 *
 * Uso (el orden importa: los callbacks se pasan a `useChat` y el watchdog
 * necesita el `chat` que devuelve):
 *
 *   const guard = useTurnGuard()
 *   const chat = useChat({ ..., onData: guard.onData, onError: guard.onError,
 *                          onFinish: (e) => { guard.onFinish(e); ... } })
 *   useTurnWatchdog(chat, guard)
 */

interface ChatLike {
  status: "submitted" | "streaming" | "ready" | "error"
  messages: UIMessage[]
  stop: () => void | Promise<void>
  setMessages: (updater: (prev: UIMessage[]) => UIMessage[]) => void
}

export interface TurnGuard {
  onData: () => void
  onError: (error: Error) => void
  onFinish: (e: { isAbort: boolean; isDisconnect: boolean; isError: boolean; finishReason?: string }) => void
  /** Estado interno — lo usa `useTurnWatchdog`, no los consumidores. */
  _state: React.MutableRefObject<{
    lastActivityAt: number
    stalled: boolean
    lastError: unknown
    chat: ChatLike | null
  }>
}

export function useTurnGuard(): TurnGuard {
  const state = React.useRef({
    lastActivityAt: Date.now(),
    stalled: false,
    lastError: undefined as unknown,
    chat: null as ChatLike | null,
  })

  const onData = React.useCallback(() => {
    state.current.lastActivityAt = Date.now()
  }, [])

  const onError = React.useCallback((error: Error) => {
    state.current.lastError = error
  }, [])

  const onFinish = React.useCallback<TurnGuard["onFinish"]>((e) => {
    const end: TurnEnd = { ...e, stalled: state.current.stalled, error: state.current.lastError }
    state.current.stalled = false
    state.current.lastError = undefined
    const interruption = interruptionFor(end)
    if (!interruption) return
    state.current.chat?.setMessages((prev) => markLastInterrupted(prev, interruption))
  }, [])

  return React.useMemo(() => ({ onData, onError, onFinish, _state: state }), [onData, onError, onFinish])
}

export function useTurnWatchdog(chat: ChatLike, guard: TurnGuard, stallMs: number = CLIENT_STALL_MS): void {
  const state = guard._state
  state.current.chat = chat

  const busy = chat.status === "submitted" || chat.status === "streaming"

  // Cualquier cambio del hilo es actividad: cada chunk que el SDK aplica genera
  // un array de mensajes nuevo.
  React.useEffect(() => {
    state.current.lastActivityAt = Date.now()
  }, [chat.messages, state])

  React.useEffect(() => {
    if (!busy) return
    // Arranque de turno: el reloj cuenta desde acá, no desde el turno anterior.
    state.current.lastActivityAt = Date.now()
    state.current.stalled = false
    const id = setInterval(() => {
      if (state.current.stalled) return
      if (!isStalled(state.current.lastActivityAt, Date.now(), stallMs)) return
      state.current.stalled = true
      void state.current.chat?.stop()
    }, 1_000)
    return () => clearInterval(id)
  }, [busy, stallMs, state])
}
