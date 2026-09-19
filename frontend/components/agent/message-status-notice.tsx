"use client"

import * as React from "react"
import type { UIMessage } from "ai"
import { TriangleAlert } from "lucide-react"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"
import { isTruncated } from "@/lib/agent/truncation"
import { interruptedDetail, isInterrupted, retryPayloadFor, type RetryPayload } from "@/lib/agent/interruption"

/**
 * Aviso al pie de un mensaje del asistente que quedó INCOMPLETO. Un solo
 * sistema de avisos para los dos motivos:
 *
 *  - `truncated`: llegó al largo máximo (lo declara el servidor,
 *    lib/agent/truncation.ts) → "Continuar la respuesta".
 *  - `interrupted`: el turno se cortó —sin cierre, con error o sin datos— (lo
 *    marca el cliente, lib/agent/interruption.ts) → "Reintentar", que reenvía
 *    la misma pregunta. El texto parcial que llegó queda arriba, tal cual.
 *
 * Lo usan las dos pantallas que dibujan el hilo (`AgentChatContent` —Sheet del
 * panel y caja— y `/chat`), que antes resolvían el aviso cada una por su lado y
 * `/chat` no lo mostraba nunca.
 *
 * La acción solo aparece en el ÚLTIMO mensaje y con el stream quieto: retomar
 * un mensaje viejo arrastraría al modelo a algo que la conversación ya dejó.
 */
export function MessageStatusNotice({
  message,
  messages,
  isLatest,
  isStreaming,
  sendMessage,
  className,
}: {
  message: UIMessage
  messages: UIMessage[]
  isLatest: boolean
  isStreaming: boolean
  sendMessage: (m: RetryPayload) => void
  className?: string
}) {
  const interrupted = isInterrupted(message)
  const truncated = !interrupted && message.role === "assistant" && isTruncated(message)
  if (!interrupted && !truncated) return null

  const showAction = isLatest && !isStreaming
  const retry = interrupted ? retryPayloadFor(messages, message.id) : null
  // En el mensaje del USUARIO la marca significa que no llegó nada de la respuesta.
  const text = interrupted
    ? message.role === "user"
      ? "No llegó la respuesta."
      : "La respuesta se interrumpió y está incompleta."
    : "La respuesta se cortó porque llegó al largo máximo: está incompleta."
  const detail = interrupted ? interruptedDetail(message) : undefined

  return (
    <Alert className={className ?? "mt-1 w-full max-w-[95%]"}>
      <TriangleAlert />
      <AlertDescription>
        {text}
        {detail ? ` ${detail}` : ""}
      </AlertDescription>
      {showAction && truncated && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="mt-2 w-fit"
          onClick={() =>
            sendMessage({
              text: "Continuá la respuesta anterior desde donde se cortó, sin repetir lo que ya escribiste.",
            })
          }
        >
          Continuar la respuesta
        </Button>
      )}
      {showAction && interrupted && retry && (
        <Button type="button" variant="outline" size="sm" className="mt-2 w-fit" onClick={() => sendMessage(retry)}>
          Reintentar
        </Button>
      )}
    </Alert>
  )
}
