"use client"

import * as React from "react"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport } from "ai"
import { getDeviceToken } from "@/lib/auth/device-token"

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
 *      panel puede crear usuarios y devolver un `tempPassword`. Acá no hay
 *      tools de escritura: no hay credencial que redactar.
 *   3. Adjuntos (parseo tabular, upload, thumbnails). El asistente de la caja
 *      es de consulta; no importa planillas de pie en el mostrador.
 *   4. `register_action` / `execute_action` y el mapeo confirmToken → acciones.
 *      No existen en este catálogo.
 *   5. Invalidación de queryKeys del PANEL (`["items"]`, `["team"]`, …). El
 *      POS ni siquiera usa esas keys, y como el asistente no escribe, no hay
 *      nada que invalidar.
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
          return token ? { Authorization: `Bearer ${token}` } : {}
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
