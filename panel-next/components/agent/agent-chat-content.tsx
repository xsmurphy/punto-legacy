"use client"

import * as React from "react"
import { isTextUIPart } from "ai"
import { MessageCircle } from "lucide-react"
import Link from "next/link"
import { useAiBalance, useInvalidateAiBalance } from "@/hooks/use-ai-balance"
import { AgentInputBox } from "@/components/agent/agent-input-box"
import { MessageMarkdown } from "@/components/agent/message-markdown"
import { MessageActions } from "@/components/agent/message-actions"
import { useAgentChat } from "@/lib/agent/use-agent-chat"
import type { StoredMessage } from "@/lib/agent/chat-history-store"
import { formatRelativeTime } from "@/lib/agent/format-relative-time"

// El balance se sigue consultando para gatear el input cuando llega a 0,
// pero NO se muestra en el header — esa info ya vive en /history-billing.
// El banner "Sin créditos disponibles" sí queda porque es el CTA que el
// user necesita en ese momento (compra inmediata).

interface Props {
  companyName: string
  outletName: string
  showHeader?: boolean
  initialInput?: string
  onInputChange?: (v: string) => void
  renderEmpty?: React.ReactNode
}

export function AgentChatContent({
  companyName,
  outletName,
  showHeader = true,
  initialInput,
  onInputChange,
  renderEmpty,
}: Props) {
  const [input, setInput] = React.useState("")
  const bottomRef = React.useRef<HTMLDivElement>(null)
  const taRef = React.useRef<HTMLTextAreaElement>(null)
  const [tick, setTick] = React.useState(0)

  const { messages, sendMessage, status, error } = useAgentChat({
    companyName,
    outletName,
  })

  const isStreaming = status === "streaming" || status === "submitted"

  const { data: balData } = useAiBalance()
  const balance = balData?.balance ?? null
  const hasNoCredits = balance !== null && balance <= 0
  const is402 = error?.message?.includes("Sin créditos") || error?.message?.includes("402")
  const invalidateBalance = useInvalidateAiBalance()

  React.useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" })
  }, [messages])

  const prevStatusRef = React.useRef(status)
  React.useEffect(() => {
    if (
      (prevStatusRef.current === "streaming" || prevStatusRef.current === "submitted") &&
      status === "ready"
    ) {
      invalidateBalance()
    }
    prevStatusRef.current = status
  }, [status, invalidateBalance])

  // Auto-refresh cada 30s para actualizar los tiempos relativos
  React.useEffect(() => {
    const id = setInterval(() => setTick((t) => t + 1), 30_000)
    return () => clearInterval(id)
  }, [])

  // Suprimir warning de tick no usado en render
  void tick

  React.useEffect(() => {
    if (initialInput !== undefined && initialInput !== "") {
      setInput(initialInput)
      taRef.current?.focus()
    }
  }, [initialInput])

  function handleSend() {
    const text = input.trim()
    if (!text || isStreaming || hasNoCredits) return
    setInput("")
    sendMessage({ text })
    if (taRef.current) taRef.current.style.height = "auto"
  }

  function handleInputChange(v: string) {
    setInput(v)
    onInputChange?.(v)
  }

  return (
    <div className="flex flex-col h-full">
      {showHeader && (
        <div className="border-b px-4 py-3">
          <div className="flex items-center gap-2">
            <div className="flex size-7 items-center justify-center rounded-full bg-foreground text-background">
              <MessageCircle className="size-4" />
            </div>
            <span className="text-sm font-medium">Asistente</span>
          </div>
        </div>
      )}

      <div className="flex-1 overflow-y-auto px-4 py-3 space-y-3">
        {(hasNoCredits || is402) && (
          <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            Sin créditos disponibles.{" "}
            <Link href="/history-billing" className="underline font-medium">
              Comprar créditos
            </Link>
          </div>
        )}

        {messages.length === 0 &&
          (renderEmpty ?? (
            <p className="text-center text-sm text-muted-foreground pt-8">
              Hola, soy tu asistente de Punto. Podés preguntarme sobre ventas, ingresos u otros datos del negocio.
            </p>
          ))}

        {messages.map((message) => {
          const isUser = message.role === "user"
          const ts = (message as StoredMessage).createdAt

          return (
            <div
              key={message.id}
              className={`group flex flex-col gap-1 ${isUser ? "items-end" : "items-start"}`}
            >
              {message.parts.map((part, idx) => {
                if (isTextUIPart(part)) {
                  // User: plano (lo que escribió). Assistant: markdown +
                  // acciones (copiar/leer). Mismo tratamiento que la página
                  // /chat — la pieza visual es idéntica para que la UX no
                  // varíe entre FAB y página dedicada.
                  if (isUser) {
                    return (
                      <React.Fragment key={idx}>
                        <div className="max-w-[85%] rounded-2xl bg-foreground px-3 py-2 text-base text-background leading-relaxed">
                          {part.text}
                        </div>
                        <div className="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                          {ts !== undefined && (
                            <time className="text-xs text-muted-foreground">
                              {formatRelativeTime(ts)}
                            </time>
                          )}
                          <MessageActions text={part.text} showSpeak={false} />
                        </div>
                      </React.Fragment>
                    )
                  }
                  return (
                    <div key={idx} className="w-full max-w-[95%] space-y-1">
                      <div className="px-1 py-1 text-foreground text-base">
                        <MessageMarkdown content={part.text} />
                      </div>
                      <div className="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <MessageActions text={part.text} />
                        {ts !== undefined && (
                          <time className="text-xs text-muted-foreground">
                            {formatRelativeTime(ts)}
                          </time>
                        )}
                      </div>
                    </div>
                  )
                }

                return null
              })}
            </div>
          )
        })}

        {isStreaming && messages[messages.length - 1]?.role === "user" && (
          <div className="flex items-start">
            <div className="flex items-center gap-1.5 rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground">
              <span className="size-3 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent" />
              <span>Pensando…</span>
            </div>
          </div>
        )}

        <div ref={bottomRef} />
      </div>

      <div className="px-4 py-3">
        <AgentInputBox
          ref={taRef}
          value={input}
          onChange={handleInputChange}
          onSend={handleSend}
          disabled={isStreaming || hasNoCredits}
          placeholder={hasNoCredits ? "Sin créditos para usar el asistente" : undefined}
        />
      </div>
    </div>
  )
}
