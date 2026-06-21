"use client"

import * as React from "react"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport } from "ai"
import { useChatHistoryStore, useChatHistoryHydrated } from "./chat-history-store"
import { messageHasCredential, redactMessage } from "./redact-credentials"

/** Tiempo de vida de un mensaje con credencial en el thread vivo. */
const CREDENTIAL_TTL_MS = 60_000

/**
 * Hook unificado para el agente. Envuelve `useChat` con:
 *   - hidratación al mount desde el historial persistido (localStorage)
 *   - persistencia automática al terminar cada respuesta (status pasa a "ready")
 *   - `clear()` que borra el state del useChat Y el localStorage
 *
 * Tanto el FAB como la página `/chat` usan este mismo hook → al abrir y
 * cerrar el Sheet, el último historial persistido se rehidrata. Si ambos
 * están montados a la vez, ver nota en chat-history-store.ts (last-write-wins).
 */
export function useAgentChat({
  companyName,
  outletName,
}: {
  companyName: string
  outletName: string
}) {
  const setStored = useChatHistoryStore((s) => s.setMessages)
  const clearStored = useChatHistoryStore((s) => s.clear)
  const storeHydrated = useChatHistoryHydrated()

  const chat = useChat({
    transport: new DefaultChatTransport({
      api: "/api/agent/chat",
      body: { companyName, outletName },
    }),
  })

  // Hidratar el chat con el historial guardado APENAS persist termine de
  // leer localStorage. El primer render puede pasar antes de la hidratación
  // (SSR/Next), por eso no podemos asumir que `stored` ya tiene los mensajes
  // al mount — esperamos al flag. UNA sola vez por instancia del hook.
  const hydratedRef = React.useRef(false)
  React.useEffect(() => {
    if (hydratedRef.current || !storeHydrated) return
    // Leemos del store DIRECTO (no del selector) para no depender de re-renders
    // — al ejecutarse este effect el snapshot ya está hidratado.
    const stored = useChatHistoryStore.getState().messages
    hydratedRef.current = true
    if (stored.length > 0 && chat.messages.length === 0) {
      chat.setMessages(stored)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [storeHydrated])

  // Persistir CADA cambio en messages después de hidratado (no solo en
  // status→ready). El user puede salir de la sección a mitad de streaming
  // o navegar entre páginas — queremos no perder nada. El store ya redacta
  // credenciales antes de escribir. Cap de 100 mensajes evita inflar localStorage.
  React.useEffect(() => {
    if (!hydratedRef.current) return
    setStored(chat.messages)
  }, [chat.messages, setStored])

  // Auto-expiración de mensajes con credenciales: cuando aparece un nuevo
  // mensaje del assistant que contiene una contraseña visible, programar un
  // timeout que reemplace el texto a los 60s. Trackeamos por id para no
  // duplicar timers en re-renders. El store ya redacta al persistir, así
  // que al expirar el timer y llamar setMessages, el localStorage queda en
  // sync con el state.
  const scheduledRef = React.useRef<Set<string>>(new Set())
  React.useEffect(() => {
    for (const msg of chat.messages) {
      if (!messageHasCredential(msg)) continue
      if (scheduledRef.current.has(msg.id)) continue
      scheduledRef.current.add(msg.id)
      setTimeout(() => {
        chat.setMessages((prev) =>
          prev.map((m) => (m.id === msg.id ? redactMessage(m) : m)),
        )
      }, CREDENTIAL_TTL_MS)
    }
  }, [chat.messages, chat])

  const clear = React.useCallback(() => {
    chat.setMessages([])
    clearStored()
    scheduledRef.current.clear()
  }, [chat, clearStored])

  return { ...chat, clear }
}
