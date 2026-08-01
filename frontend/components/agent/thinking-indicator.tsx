"use client"

import * as React from "react"
import type { UIMessage } from "ai"
import { isTextUIPart, isToolOrDynamicToolUIPart } from "ai"

/**
 * Indicador de actividad del agente entre que el usuario envía el mensaje y
 * el primer texto de la respuesta empieza a llegar. Antes esto solo vivía
 * mientras `messages[last].role === "user"` — apenas el assistant abría su
 * mensaje con tool-parts (buscando datos, armando un gráfico) el indicador
 * desaparecía y la UI quedaba muda varios segundos, sensación de colgado.
 *
 * Se muestra mientras haya streaming en curso Y el último mensaje del
 * assistant todavía no tenga NINGÚN part de texto con contenido — cubre el
 * hueco entre tools y primer texto. La label rotativa se deriva de la fase
 * real (qué está haciendo la última tool), no de azar puro.
 *
 * Caso especial `register_action`: la card de confirmación ya se renderiza
 * sola (ver RegisterActionCard) — no pisamos con un label genérico encima.
 */

type Phase = "waiting" | "tool" | "chart" | "postTool" | "hidden"

const PHASE_LABELS: Record<Exclude<Phase, "hidden">, readonly string[]> = {
  waiting:  ["Pensando…"],
  tool:     ["Consultando datos…", "Revisando los números…"],
  chart:    ["Armando el gráfico…"],
  postTool: ["Analizando la información…", "Preparando la respuesta…"],
}

const ROTATION_MS = 4000

function computePhase(message: UIMessage | undefined): Phase {
  if (!message || message.role !== "assistant") return "waiting"

  const hasText = message.parts.some((p) => isTextUIPart(p) && p.text.trim() !== "")
  if (hasText) return "hidden"

  const toolParts = message.parts.filter(isToolOrDynamicToolUIPart)
  if (toolParts.length === 0) return "waiting"

  const last = toolParts[toolParts.length - 1]
  const hasOutput = last.state === "output-available" || last.state === "output-error"

  // register_action ya muestra su propia card de confirmación con output —
  // no superponer un label genérico.
  if (last.type === "tool-register_action" && hasOutput) return "hidden"

  if (!hasOutput) {
    return last.type === "tool-render_chart" ? "chart" : "tool"
  }
  return "postTool"
}

interface Props {
  messages: UIMessage[]
  isStreaming: boolean
  /** Clases del bubble — cada superficie (página vs FAB) tiene su propio padding/radius. */
  bubbleClassName?: string
}

export function ThinkingIndicator({ messages, isStreaming, bubbleClassName }: Props) {
  const lastMessage = messages[messages.length - 1]
  const phase = isStreaming ? computePhase(lastMessage) : "hidden"

  const [tick, setTick] = React.useState(0)
  React.useEffect(() => {
    setTick(0)
    if (phase === "hidden") return
    const id = setInterval(() => setTick((t) => t + 1), ROTATION_MS)
    return () => clearInterval(id)
  }, [phase])

  if (phase === "hidden") return null

  const variants = PHASE_LABELS[phase]
  const label = variants[tick % variants.length]

  return (
    <div className="flex items-start">
      <div
        className={
          bubbleClassName ??
          "flex items-center gap-2 rounded-2xl bg-muted px-4 py-2.5 text-sm text-muted-foreground"
        }
      >
        <span className="size-3 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent" />
        <span key={label} className="animate-in fade-in-0 duration-300 motion-reduce:animate-none">
          {label}
        </span>
      </div>
    </div>
  )
}
