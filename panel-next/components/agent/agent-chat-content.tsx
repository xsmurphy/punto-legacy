"use client"

import * as React from "react"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport, isTextUIPart, isToolOrDynamicToolUIPart } from "ai"
import { MessageCircle } from "lucide-react"
import Link from "next/link"
import { useAiBalance, useInvalidateAiBalance } from "@/hooks/use-ai-balance"
import { AgentInputBox } from "@/components/agent/agent-input-box"

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

  const { messages, sendMessage, status, error } = useChat({
    transport: new DefaultChatTransport({
      api: "/api/agent/chat",
      body: { companyName, outletName },
    }),
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

          return (
            <div
              key={message.id}
              className={`flex flex-col gap-1 ${isUser ? "items-end" : "items-start"}`}
            >
              {message.parts.map((part, idx) => {
                if (isTextUIPart(part)) {
                  return (
                    <div
                      key={idx}
                      className={`max-w-[85%] rounded-lg px-3 py-2 text-sm ${
                        isUser
                          ? "bg-primary text-primary-foreground"
                          : "bg-muted text-foreground"
                      }`}
                    >
                      {part.text}
                    </div>
                  )
                }

                if (isToolOrDynamicToolUIPart(part)) {
                  const isDone =
                    "state" in part &&
                    (part.state === "output-available" || part.state === "output-error")
                  return (
                    <div
                      key={idx}
                      className="flex items-center gap-1.5 rounded-md border bg-card px-3 py-1.5 text-xs text-muted-foreground"
                    >
                      {!isDone && (
                        <span className="size-3 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent" />
                      )}
                      <span>{isDone ? "Reporte de ventas obtenido" : "Consultando ventas…"}</span>
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
