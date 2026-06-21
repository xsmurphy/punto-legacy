"use client"

import * as React from "react"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport, isTextUIPart, isToolOrDynamicToolUIPart } from "ai"
import { Bot, Send } from "lucide-react"

import Link from "next/link"

import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { useAiBalance, useInvalidateAiBalance } from "@/hooks/use-ai-balance"

interface Props {
  companyName: string
  outletName: string
  /** Mostrar el FAB. Default true. En /pos se setea a `menuOpen` del POS para
   *  que el botón no estorbe la barra de categorías. El Sheet ya abierto se
   *  mantiene aunque fabVisible pase a false. */
  fabVisible?: boolean
}

export function AgentChat({ companyName, outletName, fabVisible = true }: Props) {
  const [open, setOpen] = React.useState(false)
  const [input, setInput] = React.useState("")
  const bottomRef = React.useRef<HTMLDivElement>(null)

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

  function handleSend() {
    const text = input.trim()
    if (!text || isStreaming || hasNoCredits) return
    setInput("")
    sendMessage({ text })
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  return (
    <>
      {/* FAB — gateado por fabVisible (en /pos lo ata al menú principal abierto) */}
      {fabVisible && (
        <Button
          onClick={() => setOpen(true)}
          className="fixed bottom-6 right-6 z-50 size-14 rounded-full bg-brand text-brand-foreground shadow-lg hover:bg-brand/90"
          aria-label="Abrir asistente IA"
        >
          <Bot className="size-6" />
        </Button>
      )}

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="right" className="flex w-full max-w-sm flex-col p-0 sm:max-w-sm">
          <SheetHeader className="border-b px-4 py-3">
            <div className="flex items-center gap-2">
              <div className="flex size-7 items-center justify-center rounded-full bg-brand text-brand-foreground">
                <Bot className="size-4" />
              </div>
              <SheetTitle className="text-sm font-medium">Asistente</SheetTitle>
              {balance !== null && (
                <span className={`ml-auto text-xs tabular-nums ${hasNoCredits ? "text-destructive font-medium" : "text-muted-foreground"}`}>
                  {balance} créditos
                </span>
              )}
            </div>
          </SheetHeader>

          {/* Messages area */}
          <div className="flex-1 overflow-y-auto px-4 py-3 space-y-3">
            {(hasNoCredits || is402) && (
              <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                Sin créditos disponibles.{" "}
                <Link href="/history-billing" className="underline font-medium" onClick={() => setOpen(false)}>
                  Comprar créditos
                </Link>
              </div>
            )}

            {messages.length === 0 && (
              <p className="text-center text-sm text-muted-foreground pt-8">
                Hola, soy tu asistente de Punto. Podés preguntarme sobre ventas, ingresos u otros datos del negocio.
              </p>
            )}

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
                        (part.state === "output-available" ||
                          part.state === "output-error")
                      return (
                        <div
                          key={idx}
                          className="flex items-center gap-1.5 rounded-md border bg-card px-3 py-1.5 text-xs text-muted-foreground"
                        >
                          {!isDone && (
                            <span className="size-3 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent" />
                          )}
                          <span>
                            {isDone ? "Reporte de ventas obtenido" : "Consultando ventas…"}
                          </span>
                        </div>
                      )
                    }

                    return null
                  })}
                </div>
              )
            })}

            {/* streaming indicator when no messages yet from assistant */}
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

          {/* Input area */}
          <div className="border-t px-4 py-3">
            <div className="flex gap-2">
              <Input
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder={hasNoCredits ? "Sin créditos para usar el asistente" : "Escribí tu consulta…"}
                disabled={isStreaming || hasNoCredits}
                className="flex-1 text-sm"
                autoFocus
              />
              <Button
                onClick={handleSend}
                disabled={isStreaming || !input.trim() || hasNoCredits}
                size="icon"
                className="shrink-0"
              >
                <Send className="size-4" />
              </Button>
            </div>
          </div>
        </SheetContent>
      </Sheet>
    </>
  )
}
