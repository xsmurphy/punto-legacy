"use client"

import * as React from "react"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport } from "ai"
import { getDeviceToken } from "@/lib/auth/device-token"
import { useLockStore } from "@/lib/pos/lock-store"

/**
 * Hook del chat del asistente de la CAJA.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ NO REUSA `lib/agent/use-agent-chat.ts`
 *
 * No es por gusto de tener lo propio. El hook del panel tiene cinco bloques y
 * NINGUNO aplica acá:
 *
 *   1. Persistencia en `chat-history-store` (localStorage) + hidratación al
 *      mount, con el flag anti-race del primer persist vacío. La caja es una
 *      TABLET COMPARTIDA: rehidratar la conversación del cajero anterior es
 *      justo lo que no queremos. El thread muere con el diálogo.
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

  /** Vacía el thread. No hay historial persistido que borrar (ver docblock). */
  const clear = React.useCallback(() => {
    chat.setMessages([])
  }, [chat])

  return { ...chat, clear }
}
