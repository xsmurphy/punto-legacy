"use client"

import * as React from "react"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport } from "ai"
import { getDeviceToken } from "@/lib/auth/device-token"
import { useLockStore } from "@/lib/pos/lock-store"
import {
  useChatHistoryStore,
  useChatHistoryHydrated,
} from "@/lib/agent/chat-history-store"

/**
 * Hook del chat del asistente de la CAJA.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ NO REUSA `lib/agent/use-agent-chat.ts`
 *
 * No es por gusto de tener lo propio. El hook del panel tiene cinco bloques y
 * NINGUNO aplica acá:
 *
 *   1. (YA NO: ver "El historial es de la persona", abajo.) Los otros cuatro
 *      siguen sin aplicar.
 *   2. Redacción de credenciales con TTL de 60s. Existe porque el agente del
 *      panel puede crear usuarios y devolver un `tempPassword`. En la caja
 *      `create_user` está bloqueada por realm en el backend
 *      (`api/lib/Ai/AgentActor.php`): no hay credencial que redactar.
 *   3. Adjuntos (parseo tabular, upload, thumbnails). El asistente de la caja
 *      no importa planillas de pie en el mostrador.
 *   4. El mapeo confirmToken → acciones para invalidar. Es del punto 5.
 *   5. Invalidación de queryKeys del PANEL (`["items"]`, `["team"]`, …). El POS
 *      no usa esas keys: cuando el asistente escribe, lo que actualiza la caja
 *      es el sync en tiempo real (`realtimePublish` del backend), igual que
 *      cualquier otro cambio hecho desde otra pantalla.
 *
 * Parametrizarlo habría significado seis flags gateando seis bloques, y lo
 * verdaderamente compartido que quedaría abajo es `useChat({ transport })`:
 * una llamada de una línea al SDK. Duplicar eso no es duplicar lógica.
 *
 * Y hay una diferencia que no se puede expresar con un flag: acá el transport
 * DEBE mandar `Authorization: Bearer <device token>` y NO debe mandar cookies.
 * El del panel no manda ninguna de las dos cosas.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * LA CREDENCIAL VA EXPLÍCITA
 *
 * El transport del panel (`DefaultChatTransport` sin `headers`) NO manda
 * `Authorization` — el fetch del SDK no pasa por `lib/api-client.ts`, que es
 * quien conoce el token, así que el BFF del panel recibe el header vacío. Acá
 * no dependemos de ninguna magia: el header se arma a mano con el token del
 * device, y `credentials: "omit"` garantiza que el browser tampoco adjunte
 * cookies same-origin de una sesión de panel abierta en la misma máquina.
 * Esa es la mitad cliente de la regla token-only; la otra mitad la hace el
 * BFF, que exige el Bearer y devuelve 401 sin él.
 *
 * `headers` es una función y no un objeto: el token se lee en el momento de
 * enviar, no en el mount. Si la caja se despareó y volvió a parear con el
 * diálogo abierto, el próximo mensaje sale con el token nuevo.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Y VA UNA SEGUNDA CREDENCIAL: QUIÉN
 *
 * El Bearer dice de qué CAJA viene la request; el `X-Operator-Token` dice QUIÉN
 * la está operando. Son dos hechos distintos y por eso viajan aparte: el
 * primero no expira nunca y lo comparte todo el local, el segundo lo emite el
 * unlock por PIN y se tira al bloquear la pantalla (`lock-store.ts`).
 *
 * Desde que el asistente puede hacer cambios, esa segunda credencial es la que
 * los autoriza: el backend evalúa cada acción contra el rol de esa persona y
 * rechaza todo si falta. Se lee del store en cada envío, por lo mismo que el
 * Bearer — entre dos mensajes puede haber cambiado el operador.
 *
 * En un desbloqueo OFFLINE el PIN se valida contra el roster cacheado y no hay
 * token: el chat sigue funcionando para consultar (cuando vuelva la red) y no
 * ofrece cambios. Es el fail-closed correcto, no un bug.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * EL HISTORIAL ES DE LA PERSONA, NO DEL DISPOSITIVO
 *
 * Owner, 2026-08-31: "el historial debe estar atado al User ID siempre, tanto
 * en panel como en /pos", después de ver que al refrescar la página se perdía
 * la conversación.
 *
 * Este hook NO persistía por una razón buena —una tablet la comparten tres
 * personas por turno y rehidratar la charla del anterior es exactamente lo que
 * no se quiere—, pero la conclusión era demasiado gruesa: recargar la página no
 * es cambiar de persona. Con `chat-history-store` segmentado por usuario el
 * problema desaparece de raíz: cada operador tiene su propio cajón y nunca ve
 * el de otro.
 *
 * El dueño acá es el OPERADOR del PIN (`activeUser.id`), NUNCA el device: el
 * Bearer identifica la tablet, y atar el historial a eso sería volver a
 * mezclar a todos en un solo hilo. Con la caja bloqueada no hay dueño, el id
 * es "" y el store no persiste ni hidrata — así el thread del turno anterior no
 * queda colgado esperando a que alguien lo lea.
 */
export function usePosAgentChat({
  companyName,
  currency,
  country,
  timezone,
}: {
  /** Nombre del comercio — de `useCatalogStore` (config del POS), no del bootstrap del panel. */
  companyName: string
  /** Moneda del tenant (`PosConfig.currency`) — el modelo formatea los montos con esto. */
  currency: string
  /** Código ISO de país (`PosConfig.country`). */
  country: string
  /** TZ IANA del tenant (`PosConfig.timezone`) — define qué día es "hoy" para el modelo. */
  timezone: string
}) {
  const transport = React.useMemo(
    () =>
      new DefaultChatTransport({
        api: "/api/pos/agent/chat",
        credentials: "omit",
        headers: (): Record<string, string> => {
          const token = getDeviceToken("pos")
          // Sin token no hay request útil: el BFF corta con 401 y la UI muestra
          // el error. Mejor eso que inventar una credencial de reemplazo.
          if (!token) return {}
          const operatorToken = useLockStore.getState().operatorToken
          return operatorToken
            ? { Authorization: `Bearer ${token}`, "X-Operator-Token": operatorToken }
            : { Authorization: `Bearer ${token}` }
        },
        body: { companyName, currency, country, timezone },
      }),
    [companyName, currency, country, timezone],
  )

  const chat = useChat({
    transport,
    onError: (err) => {
      console.error("[pos-agent] useChat error", err)
    },
  })

  // Dueño del historial: la persona que desbloqueó con su PIN. Con la caja
  // bloqueada es "" y el store ignora tanto el guardado como la hidratación.
  const operatorId = useLockStore((s) => s.activeUser?.id ?? "")
  const setStored = useChatHistoryStore((s) => s.setMessages)
  const storeHydrated = useChatHistoryHydrated()
  const { setMessages } = chat

  // Hidratación: una vez POR OPERADOR. La ref guarda a quién se hidrató, no un
  // booleano, porque en la misma pestaña se suceden varias personas: con un
  // flag, el segundo cajero se quedaría con el thread en blanco del arranque en
  // vez del suyo.
  const hydratedFor = React.useRef<string | null>(null)
  React.useEffect(() => {
    if (!storeHydrated) return
    if (hydratedFor.current === operatorId) return
    hydratedFor.current = operatorId
    // Al bloquear (operatorId "") el thread se vacía: lo que quede en pantalla
    // es de alguien que ya se fue, incluidas las tarjetas de confirmación que
    // el backend le rechazaría al siguiente de todos modos.
    setMessages(
      operatorId
        ? ((useChatHistoryStore.getState().histories[operatorId] ?? []) as typeof chat.messages)
        : [],
    )
  }, [storeHydrated, operatorId, setMessages])

  // Persistencia: mientras haya dueño. El store recorta y redacta.
  React.useEffect(() => {
    if (!operatorId || !storeHydrated) return
    if (hydratedFor.current !== operatorId) return // no pisar antes de hidratar
    setStored(operatorId, chat.messages)
  }, [chat.messages, operatorId, setStored, storeHydrated])

  /** Vacía el thread en pantalla Y el historial guardado de esta persona. */
  const clearStored = useChatHistoryStore((s) => s.clear)
  const clear = React.useCallback(() => {
    chat.setMessages([])
    clearStored(operatorId)
  }, [chat, clearStored, operatorId])

  return { ...chat, clear }
}
