"use client"

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
  setMessages: (messages: UIMessage[]) => void
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
        set({ messages: redactMessages(messages.slice(-100)) }),
      clear: () => set({ messages: [] }),
    }),
    {
      name: "punto-agent-chat-history",
      storage: createJSONStorage(() => localStorage),
    },
  ),
)
