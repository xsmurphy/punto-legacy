"use client"

import * as React from "react"
import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"
import type { UIMessage } from "ai"
import { redactMessages } from "./redact-credentials"

/**
 * Historial persistente del agente IA — vive en localStorage del browser.
 *
 * Por qué Zustand persist en vez de manejar localStorage a mano:
 *   - Se hidrata sync en el render inicial (sin flash de "chat vacío").
 *   - Lo comparte el FAB y la página /chat: al hidratar al mount, ambos
 *     arrancan con la misma historia. Si están abiertos simultáneamente,
 *     cada `useChat` tiene su propio state interno → puede haber divergencia
 *     hasta que uno cierre/abra (last-write-wins en el storage). Aceptable
 *     para MVP — el caso "ambos abiertos" es raro.
 *
 * Cap a 100 mensajes: localStorage tiene ~5MB por origin, un mensaje
 * típico pesa unos KB; 100 deja muchísimo margen pero evita inflar si
 * alguien tiene una conversación de 500 vueltas.
 *
 * Key NO incluye companyId — la cookie del operador ya cambia el JWT al
 * loguearse a otro tenant; el storage se mantiene asociado al browser. Si
 * querés aislamiento real por tenant agregalo a `name` al pasar useBootstrap.
 * MVP: una sola conversación por browser.
 */
interface ChatHistoryState {
  messages: UIMessage[]
  /** Timestamps persistidos por id de mensaje. Clave: message.id → epoch ms. */
  messageTimestamps: Record<string, number>
  setMessages: (messages: UIMessage[]) => void
  /**
   * Registra el timestamp de aparición de un mensaje NUEVO.
   * Si ya existe un ts para ese id, lo ignora — así los históricos
   * conservan su ts original tras recargar.
   */
  setMessageTimestamp: (id: string, ts: number) => void
  clear: () => void
}

export const useChatHistoryStore = create<ChatHistoryState>()(
  persist(
    (set, get) => ({
      messages: [],
      messageTimestamps: {},
      // SIEMPRE redactamos credenciales antes de persistir — si el user hace
      // refresh, la contraseña ya no está. Defense in depth: el timer client
      // de 60s también oculta en vivo, pero esto es la red de seguridad final
      // contra "cerré la pestaña antes de que expire".
      setMessages: (messages) =>
        set({ messages: redactMessages(messages.slice(-100)) }),
      setMessageTimestamp: (id, ts) => {
        if (get().messageTimestamps[id] !== undefined) return
        set((s) => ({ messageTimestamps: { ...s.messageTimestamps, [id]: ts } }))
      },
      clear: () => set({ messages: [], messageTimestamps: {} }),
    }),
    {
      name: "punto-agent-chat-history",
      storage: createJSONStorage(() => localStorage),
    },
  ),
)

/**
 * Hook que devuelve `true` cuando `persist` terminó de leer localStorage.
 * En SSR/Next el primer render del cliente puede pasar antes de la hidratación
 * — si hidratamos el chat con el store en ese punto, copiamos `[]` y nunca
 * más rehidratamos. Esperamos a este flag antes de tocar `chat.setMessages`.
 */
export function useChatHistoryHydrated(): boolean {
  const [hydrated, setHydrated] = React.useState<boolean>(() =>
    typeof window === "undefined" ? false : useChatHistoryStore.persist.hasHydrated(),
  )
  React.useEffect(() => {
    if (useChatHistoryStore.persist.hasHydrated()) {
      setHydrated(true)
      return
    }
    const unsub = useChatHistoryStore.persist.onFinishHydration(() => setHydrated(true))
    return () => unsub()
  }, [])
  return hydrated
}
