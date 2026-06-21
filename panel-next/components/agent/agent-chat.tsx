"use client"

import * as React from "react"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport, isTextUIPart, isToolOrDynamicToolUIPart } from "ai"
import { Bot, Send } from "lucide-react"

import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"

interface Props {
  companyName: string
  outletName: string
}

export function AgentChat({ companyName, outletName }: Props) {
  const [open, setOpen] = React.useState(false)
  const [input, setInput] = React.useState("")
  const bottomRef = React.useRef<HTMLDivElement>(null)

  const { messages, sendMessage, status } = useChat({
    transport: new DefaultChatTransport({
      api: "/api/agent/chat",
      body: { companyName, outletName },
    }),
  })

  const isStreaming = status === "streaming" || status === "submitted"

  React.useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" })
  }, [messages])

  function handleSend() {
    const text = input.trim()
    if (!text || isStreaming) return
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
      {/* FAB */}
      <Button
        onClick={() => setOpen(true)}
        className="fixed bottom-6 right-6 z-50 size-14 rounded-full bg-brand text-brand-foreground shadow-lg hover:bg-brand/90"
        aria-label="Abrir asistente IA"
      >
        <Bot className="size-6" />
      </Button>

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="right" className="flex w-full max-w-sm flex-col p-0 sm:max-w-sm">
          <SheetHeader className="border-b px-4 py-3">
            <div className="flex items-center gap-2">
              <div className="flex size-7 items-center justify-center rounded-full bg-brand text-brand-foreground">
                <Bot className="size-4" />
              </div>
              <SheetTitle className="text-sm font-medium">Asistente</SheetTitle>
            </div>
          </SheetHeader>

          {/* Messages area */}
          <div className="flex-1 overflow-y-auto px-4 py-3 space-y-3">
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
                placeholder="Escribí tu consulta…"
                disabled={isStreaming}
                className="flex-1 text-sm"
                autoFocus
              />
              <Button
                onClick={handleSend}
                disabled={isStreaming || !input.trim()}
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
