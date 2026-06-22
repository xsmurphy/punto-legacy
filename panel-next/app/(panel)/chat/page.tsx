"use client"

import * as React from "react"
import { isTextUIPart, isToolOrDynamicToolUIPart } from "ai"
import Link from "next/link"
import {
  TrendingUp,
  Search,
  Plus as PlusIcon,
  Package,
  BarChart3,
  RotateCcw,
} from "lucide-react"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useAiBalance, useInvalidateAiBalance } from "@/hooks/use-ai-balance"
import { AgentInputBox } from "@/components/agent/agent-input-box"
import { MessageMarkdown } from "@/components/agent/message-markdown"
import { MessageActions } from "@/components/agent/message-actions"
import { useAgentChat } from "@/lib/agent/use-agent-chat"
import { Button } from "@/components/ui/button"

/**
 * Página dedicada del asistente IA en el sidebar.
 *
 * Dos layouts:
 *   - Vacío (sin mensajes): pantalla centrada estilo ChatGPT — título grande,
 *     input al medio, sugerencias en cards visuales DEBAJO del input. Click en
 *     una sugerencia llena el input (no envía) para que el user pueda editar.
 *   - Con mensajes: scroll de la conversación arriba, input al pie (sticky).
 *
 * NO usa AgentChatContent porque ese está pensado para el Sheet del FAB (layout
 * más compacto, header con avatar). Acá controlamos 100% del layout para que se
 * sienta como un destino "first class" del sidebar. Comparte la pieza clave
 * del input — AgentInputBox — para que la UX sea idéntica.
 */

interface Suggestion {
  icon: typeof TrendingUp
  text: string
}

// 5 sugerencias seleccionadas: las más representativas del alcance — consulta
// rápida (sales/stock), búsqueda, creación con confirmación, análisis.
const SUGGESTIONS: Suggestion[] = [
  { icon: TrendingUp, text: "¿Cuánto vendí este mes?" },
  { icon: Search,     text: "Buscame el cliente Juan" },
  { icon: Package,    text: "¿Cuánto stock queda del producto X?" },
  { icon: PlusIcon,   text: "Creá el producto Café Espresso a 12.000 Gs, categoría Bebidas" },
  { icon: BarChart3,  text: "Resumen del año pasado" },
]

export default function ChatPage() {
  const { data: bootstrap } = useBootstrap()
  const [input, setInput] = React.useState("")
  const taRef = React.useRef<HTMLTextAreaElement>(null)
  const bottomRef = React.useRef<HTMLDivElement>(null)

  const { messages, sendMessage, status, error, clear } = useAgentChat({
    companyName: bootstrap?.companyName ?? "",
    outletName: bootstrap?.activeOutletName ?? "",
  })

  const isStreaming = status === "streaming" || status === "submitted"
  const { data: balData } = useAiBalance()
  const balance = balData?.balance ?? null
  const hasNoCredits = balance !== null && balance <= 0
  const is402 = error?.message?.includes("Sin créditos") || error?.message?.includes("402")
  const invalidateBalance = useInvalidateAiBalance()
  const isEmpty = messages.length === 0

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
    if (taRef.current) taRef.current.style.height = "auto"
  }

  function pickSuggestion(text: string) {
    setInput(text)
    requestAnimationFrame(() => taRef.current?.focus())
  }

  if (!bootstrap) return null

  return (
    <div className="flex h-[calc(100vh-4rem)] flex-col gap-6">
      {/* Header de página — patrón estándar (items/contacts/etc.) */}
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Asistente</h1>
          <p className="text-sm text-muted-foreground">
            Consultá datos, creá registros básicos y analizá tu negocio en lenguaje natural.
          </p>
        </div>
        {messages.length > 0 && (
          <Button variant="outline" size="sm" onClick={clear}>
            <RotateCcw className="size-4" />
            Nueva conversación
          </Button>
        )}
      </header>

      {isEmpty ? (
        // ── Estado vacío: layout centrado tipo ChatGPT ───────────────────────
        <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col justify-center px-2 pb-8 sm:px-6">
          <div className="mb-10 text-center">
            <h2 className="text-3xl font-semibold tracking-tight md:text-4xl">
              ¿En qué te puedo ayudar?
            </h2>
          </div>

          <AgentInputBox
            ref={taRef}
            value={input}
            onChange={setInput}
            onSend={handleSend}
            disabled={isStreaming || hasNoCredits}
            placeholder={hasNoCredits ? "Sin créditos para usar el asistente" : undefined}
            maxHeight={200}
          />

          {/* Sugerencias como cards visuales debajo del input */}
          <div className="mt-8 grid grid-cols-1 gap-2 sm:grid-cols-2">
            {SUGGESTIONS.map(({ icon: Icon, text }) => (
              <button
                key={text}
                type="button"
                disabled={hasNoCredits}
                onClick={() => pickSuggestion(text)}
                className="group flex items-start gap-3 rounded-xl border border-border/60 bg-card px-4 py-3 text-left text-sm transition-colors hover:border-border hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
              >
                <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground group-hover:text-foreground" />
                <span className="text-foreground/90 group-hover:text-foreground">{text}</span>
              </button>
            ))}
          </div>

          {(hasNoCredits || is402) && (
            <p className="mt-8 text-center text-xs text-muted-foreground">
              Sin créditos disponibles.{" "}
              <Link
                href="/history-billing"
                className="font-medium text-foreground underline underline-offset-4 hover:text-foreground/80"
              >
                Comprar créditos
              </Link>
            </p>
          )}
        </div>
      ) : (
        // ── Estado con mensajes: thread + input al pie ───────────────────────
        <>
          <div className="flex-1 overflow-y-auto">
            <div className="mx-auto w-full max-w-3xl space-y-4 px-2 py-6 sm:px-6">
              {messages.map((message) => {
                const isUser = message.role === "user"
                return (
                  <div
                    key={message.id}
                    className={`group flex flex-col gap-1 ${isUser ? "items-end" : "items-start"}`}
                  >
                    {message.parts.map((part, idx) => {
                      if (isTextUIPart(part)) {
                        // User: texto plano (lo que escribió). Assistant:
                        // markdown formateado + acciones (copiar/leer).
                        if (isUser) {
                          return (
                            <React.Fragment key={idx}>
                              <div className="max-w-[85%] rounded-2xl bg-foreground px-4 py-2.5 text-sm leading-relaxed text-background">
                                {part.text}
                              </div>
                              <div className="opacity-0 group-hover:opacity-100 transition-opacity">
                                <MessageActions text={part.text} showSpeak={false} />
                              </div>
                            </React.Fragment>
                          )
                        }
                        return (
                          <div key={idx} className="w-full max-w-[90%] space-y-1">
                            <div className="rounded-2xl bg-muted px-4 py-3 text-foreground">
                              <MessageMarkdown content={part.text} />
                            </div>
                            <div className="opacity-0 group-hover:opacity-100 transition-opacity">
                              <MessageActions text={part.text} />
                            </div>
                          </div>
                        )
                      }
                      if (isToolOrDynamicToolUIPart(part)) {
                        const isDone =
                          "state" in part &&
                          (part.state === "output-available" || part.state === "output-error")
                        const isError = "state" in part && part.state === "output-error"
                        return (
                          <div
                            key={idx}
                            className="flex items-center gap-2 px-1 py-0.5 text-[11px] text-muted-foreground/70"
                          >
                            <span
                              className={
                                isError
                                  ? "size-1.5 rounded-full bg-destructive/70"
                                  : isDone
                                    ? "size-1.5 rounded-full bg-muted-foreground/40"
                                    : "size-1.5 animate-pulse rounded-full bg-muted-foreground/70"
                              }
                            />
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
                  <div className="flex items-center gap-2 rounded-2xl bg-muted px-4 py-2.5 text-sm text-muted-foreground">
                    <span className="size-3 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent" />
                    <span>Pensando…</span>
                  </div>
                </div>
              )}

              <div ref={bottomRef} />
            </div>
          </div>

          {/* Sin border-t: el shadow del input box ya separa visualmente del thread */}
          <div className="bg-background/80 backdrop-blur">
            <div className="mx-auto w-full max-w-3xl px-2 py-4 sm:px-6">
              {(hasNoCredits || is402) && (
                <p className="mb-3 text-center text-xs text-muted-foreground">
                  Sin créditos disponibles.{" "}
                  <Link
                    href="/history-billing"
                    className="font-medium text-foreground underline underline-offset-4 hover:text-foreground/80"
                  >
                    Comprar créditos
                  </Link>
                </p>
              )}
              <AgentInputBox
                ref={taRef}
                value={input}
                onChange={setInput}
                onSend={handleSend}
                disabled={isStreaming || hasNoCredits}
                placeholder={hasNoCredits ? "Sin créditos para usar el asistente" : undefined}
                maxHeight={200}
              />
            </div>
          </div>
        </>
      )}
    </div>
  )
}
