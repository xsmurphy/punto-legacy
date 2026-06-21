"use client"

import * as React from "react"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport } from "ai"
import { useChatHistoryStore } from "./chat-history-store"
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
  const stored = useChatHistoryStore((s) => s.messages)
  const setStored = useChatHistoryStore((s) => s.setMessages)
  const clearStored = useChatHistoryStore((s) => s.clear)

  const chat = useChat({
    transport: new DefaultChatTransport({
      api: "/api/agent/chat",
      body: { companyName, outletName },
    }),
  })

  // Hidratar UNA SOLA VEZ al mount si el chat arrancó vacío y hay historial.
  // No depende de `stored` porque la persistencia es sync en el render inicial.
  const hydratedRef = React.useRef(false)
  React.useEffect(() => {
    if (hydratedRef.current) return
    hydratedRef.current = true
    if (stored.length > 0 && chat.messages.length === 0) {
      chat.setMessages(stored)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Persistir cuando una respuesta termina (status: streaming/submitted → ready).
  // No persistir durante el streaming — escritura por cada chunk = ruido.
  const prevStatusRef = React.useRef(chat.status)
  React.useEffect(() => {
    if (
      (prevStatusRef.current === "streaming" || prevStatusRef.current === "submitted") &&
      chat.status === "ready"
    ) {
      setStored(chat.messages)
    }
    prevStatusRef.current = chat.status
  }, [chat.status, chat.messages, setStored])

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
