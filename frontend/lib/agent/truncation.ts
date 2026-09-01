import type { TextStreamPart, ToolSet } from "ai"

/**
 * Corte por longitud: detección en el servidor, señal al cliente.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * EL PROBLEMA
 *
 * Los dos routes del asistente ponen un `maxOutputTokens` (el del panel y el
 * de la caja, cada uno con su número y su porqué). Cuando el modelo llega a
 * ese techo, el proveedor corta el stream DONDE ESTÉ y el AI SDK lo entrega
 * como una respuesta normal: sin error, sin warning, sin nada. En pantalla,
 * media respuesta se ve exactamente igual que una completa.
 *
 * Eso ya pasó en producción: el owner pidió un balance general, recibió la
 * tabla de ACTIVOS entera y el texto terminó en "**Total Act". Sin pasivos,
 * sin patrimonio, y sin ninguna marca de que faltaba algo. Un balance a medias
 * que parece entero es peor que un error: alguien decide sobre él.
 *
 * Es el mismo criterio que ya aplica `normalize-tool-result.ts` con `MAX_ROWS`
 * — cuando recorta filas lo DECLARA en `meta`, porque un total calculado sobre
 * una parte y presentado como el total es un dato falso, no un dato incompleto.
 * Acá el recorte es de texto en vez de filas, pero la regla es la misma:
 * lo que se cortó se dice.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR DÓNDE VIAJA LA SEÑAL
 *
 * El AI SDK expone `finishReason` en el part `finish` del stream, y vale
 * `"length"` exactamente cuando se agotó el presupuesto de salida (el resto de
 * los cortes tienen sus propios valores: `"stop"`, `"tool-calls"`, `"error"`).
 * Con multi-step, el `finish` de nivel superior lleva el `finishReason` del
 * ÚLTIMO paso, que es justo el que le habla al usuario.
 *
 * El transporte es `messageMetadata` de `toUIMessageStreamResponse`: el SDK lo
 * llama en `start` y en `finish`, y lo que devuelve viaja en el chunk y se
 * mergea en `message.metadata` del lado del cliente. Se eligió esto y no un
 * data part porque la metadata VIVE CON EL MENSAJE: se persiste con él en el
 * historial de localStorage (`chat-history-store.ts`), así que el aviso sigue
 * ahí después de un reload. Un mensaje truncado no deja de estarlo porque el
 * usuario recargue la pestaña.
 *
 * `onFinish` del route NO sirve para esto: corre server-side para debitar
 * créditos y no tiene ningún canal hacia la UI.
 */

/**
 * Metadata que los routes del asistente adjuntan a la respuesta.
 *
 * `truncated` es opcional y solo se manda cuando es `true`: mandar
 * `{truncated:false}` en cada respuesta sana sería un chunk extra por mensaje
 * para no decir nada.
 */
export interface AgentMessageMetadata {
  /** Presente solo si la respuesta se cortó por el techo de tokens de salida. */
  truncated?: true
}

/**
 * Callback de `messageMetadata` — se pasa TAL CUAL a
 * `toUIMessageStreamResponse` en los dos routes.
 *
 * Devolver `undefined` es lo normal: el SDK omite el campo y no se manda nada.
 * En el part `start` siempre devuelve `undefined` porque todavía no hay
 * `finishReason` que mirar.
 */
export function truncationMetadata({
  part,
}: {
  part: TextStreamPart<ToolSet>
}): AgentMessageMetadata | undefined {
  if (part.type !== "finish") return undefined
  return part.finishReason === "length" ? { truncated: true } : undefined
}

/**
 * Lado cliente: ¿este mensaje se cortó por longitud?
 *
 * Toma el mensaje estructuralmente (`{ metadata?: unknown }`) y no un
 * `UIMessage` para que el test corra sin construir uno entero. `metadata` es
 * `unknown` en el tipo del SDK —quien la tipa es el consumidor— y además llega
 * de localStorage, donde puede haber quedado cualquier cosa de una versión
 * anterior del formato: por eso se valida la forma en vez de castear.
 */
export function isTruncated(message: { metadata?: unknown }): boolean {
  const meta = message.metadata
  if (typeof meta !== "object" || meta === null) return false
  return (meta as AgentMessageMetadata).truncated === true
}
