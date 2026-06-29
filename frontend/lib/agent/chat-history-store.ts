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
 *
 * Timestamps: `createdAt` se embebe en cada StoredMessage al persistir por
 * primera vez. Esto elimina el mapa separado (messageTimestamps) y su
 * race de hidratación: el timestamp viaja con el mensaje y sobrevive cualquier
 * reload sin depender de sincronía entre dos estructuras del store.
 */

/** UIMessage con timestamp de primer aparición, para persistencia. */
export type StoredMessage = UIMessage & { createdAt?: number }

interface ChatHistoryState {
  messages: StoredMessage[]
  setMessages: (messages: StoredMessage[]) => void
  clear: () => void
}

export const useChatHistoryStore = create<ChatHistoryState>()(
  persist(
    (set) => ({
      messages: [],
      // SIEMPRE redactamos credenciales antes de persistir — si el user hace
      // refresh, la contraseña ya no está. Defense in depth: el timer client
      // de 60s también oculta en vivo, pero esto es la red de seguridad final
      // contra "cerré la pestaña antes de que expire".
      setMessages: (messages) =>
        set({ messages: redactMessages(messages.slice(-100)) as StoredMessage[] }),
      clear: () => set({ messages: [] }),
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
