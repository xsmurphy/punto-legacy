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
 * SEGMENTADO POR USUARIO (owner, 2026-08-31: "el historial debe estar atado al
 * User ID siempre, tanto en panel como en /pos"). Antes era UNA conversación
 * por browser, y eso estaba mal en las dos superficies: en el panel, dos
 * personas que entran en la misma máquina se leían la conversación entre
 * ellas; en la caja, una tablet la comparte todo el turno. El historial es de
 * quien lo escribió.
 *
 * El id lo pone el consumidor, porque cada superficie sabe quién es su usuario:
 * el panel pasa `bootstrap.user.id`; la caja, el id del OPERADOR que desbloqueó
 * con su PIN (`lib/pos/lock-store.ts`), NO el del dispositivo — el device es la
 * tablet, no la persona.
 *
 * Sin id no se persiste nada: `""` es "todavía no sé quién sos" (bootstrap en
 * vuelo, caja bloqueada) y guardar ahí dejaría mensajes en un cajón que después
 * cualquiera abre. La conversación sigue viva en memoria; lo que no sobrevive
 * es el reload, que es el trade correcto.
 *
 * Cap de usuarios (`MAX_USERS`): el registro se poda por orden de escritura, si
 * no una máquina de mostrador con veinte cajeros acumula veinte historiales
 * para siempre.
 *
 * NO incluye companyId: un mismo id de usuario no se repite entre tenants
 * (UUID), así que el id solo ya aísla.
 *
 * Timestamps: `createdAt` se embebe en cada StoredMessage al persistir por
 * primera vez. Esto elimina el mapa separado (messageTimestamps) y su
 * race de hidratación: el timestamp viaja con el mensaje y sobrevive cualquier
 * reload sin depender de sincronía entre dos estructuras del store.
 */

/** UIMessage con timestamp de primer aparición, para persistencia. */
export type StoredMessage = UIMessage & { createdAt?: number }

/** Cuántos historiales de usuario se conservan en un mismo dispositivo. */
const MAX_USERS = 8

interface ChatHistoryState {
  /** userId → sus mensajes. Nunca lleva la clave `""`. */
  histories: Record<string, StoredMessage[]>
  setMessages: (userId: string, messages: StoredMessage[]) => void
  clear: (userId: string) => void
}

export const useChatHistoryStore = create<ChatHistoryState>()(
  persist(
    (set) => ({
      histories: {},
      // SIEMPRE redactamos credenciales antes de persistir — si el user hace
      // refresh, la contraseña ya no está. Defense in depth: el timer client
      // de 60s también oculta en vivo, pero esto es la red de seguridad final
      // contra "cerré la pestaña antes de que expire".
      setMessages: (userId, messages) =>
        set((state) => {
          // Sin usuario no se guarda: ver el docblock. Devolver el estado tal
          // cual —y no un objeto nuevo— evita además re-renders al pedo.
          if (!userId) return state
          const next: Record<string, StoredMessage[]> = { ...state.histories }
          // El `delete` antes de asignar NO es redundante: reasignar una clave
          // que ya existe la deja en su posición original, así que sin esto el
          // usuario que acaba de escribir seguiría figurando como el más viejo
          // y sería el primero en podarse.
          delete next[userId]
          next[userId] = redactMessages(messages.slice(-100)) as StoredMessage[]
          // Poda por orden de inserción: se cae el que hace más tiempo que no
          // escribe. Las claves de un objeto JS conservan ese orden.
          const ids = Object.keys(next)
          for (const stale of ids.slice(0, Math.max(0, ids.length - MAX_USERS))) {
            delete next[stale]
          }
          return { histories: next }
        }),
      clear: (userId) =>
        set((state) => {
          if (!userId || !(userId in state.histories)) return state
          const next = { ...state.histories }
          delete next[userId]
          return { histories: next }
        }),
    }),
    {
      // `-v2`: la forma cambió de `{messages}` a `{histories}`. La clave vieja
      // guardaba UNA conversación sin dueño, y no hay forma honesta de
      // atribuirla a nadie ahora, así que se descarta en vez de adivinar. Es
      // historial de chat: el costo de perderlo es bajo, el de mostrárselo a
      // otra persona no.
      name: "punto-agent-chat-history-v2",
      storage: createJSONStorage(() => localStorage),
    },
  ),
)

/**
 * Los mensajes de UN usuario. Referencia estable mientras no cambien: sin esto
 * un `?? []` inline devolvería un array nuevo en cada render y dispararía los
 * efectos que dependen de él.
 */
const EMPTY: StoredMessage[] = []

export function useStoredMessages(userId: string): StoredMessage[] {
  return useChatHistoryStore((s) => (userId ? s.histories[userId] : undefined) ?? EMPTY)
}

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
