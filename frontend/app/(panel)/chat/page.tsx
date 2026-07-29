"use client"

import * as React from "react"
import { isTextUIPart, isToolOrDynamicToolUIPart } from "ai"
import Link from "next/link"
import {
  ArrowDown,
  TrendingUp,
  Search,
  Plus as PlusIcon,
  Package,
  BarChart3,
  RotateCcw,
} from "lucide-react"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useViewScope } from "@/hooks/use-view-scope"
import { useAiBalance, useInvalidateAiBalance } from "@/hooks/use-ai-balance"
import { AgentInputBox } from "@/components/agent/agent-input-box"
import { MessageMarkdown } from "@/components/agent/message-markdown"
import { MessageActions } from "@/components/agent/message-actions"
import { RegisterActionCard, ExecuteActionSummary, isEmptyCodeFence } from "@/components/agent/agent-action-card"
import { useAgentChat } from "@/lib/agent/use-agent-chat"
import type { StoredMessage } from "@/lib/agent/chat-history-store"
import { formatRelativeTime } from "@/lib/agent/format-relative-time"
import { Button } from "@/components/ui/button"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"

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
  const { scope: viewScope } = useViewScope()
  const outlets = bootstrap?.outlets ?? []
  // Mismo cálculo del view-scope que panel-auth-guard: UUID → ese outlet,
  // "all" → todas, null → outlet del JWT (sin override).
  const viewOutletId =
    typeof viewScope === "string" && viewScope !== "all" ? viewScope : viewScope === "all" ? "all" : ""
  const viewOutletName =
    viewScope === "all"
      ? "Todas las sucursales"
      : typeof viewScope === "string"
        ? (outlets.find((o) => o.id === viewScope)?.name ?? bootstrap?.activeOutletName ?? "")
        : (bootstrap?.activeOutletName ?? "")
  const [input, setInput] = React.useState("")
  const [tick, setTick] = React.useState(0)
  const taRef = React.useRef<HTMLTextAreaElement>(null)
  const bottomRef = React.useRef<HTMLDivElement>(null)

  const { messages, sendMessage, status, error, clear } = useAgentChat({
    companyName: bootstrap?.companyName ?? "",
    viewOutletId,
    viewOutletName,
  })

  const isStreaming = status === "streaming" || status === "submitted"
  const { data: balData } = useAiBalance()
  const balance = balData?.balance ?? null
  const hasNoCredits = balance !== null && balance <= 0
  const is402 = error?.message?.includes("Sin créditos") || error?.message?.includes("402")
  const invalidateBalance = useInvalidateAiBalance()
  const isEmpty = messages.length === 0

  // Mismo patrón que AgentChatContent (FAB): auto-scroll solo si el usuario ya
  // estaba abajo; si subió a releer, aparece el botón de bajar. Instantáneo
  // durante el stream (las animaciones suaves encadenadas se ven como saltos).
  const [isAtBottom, setIsAtBottom] = React.useState(true)

  const scrollToBottom = React.useCallback((behavior: ScrollBehavior = "smooth") => {
    bottomRef.current?.scrollIntoView({ behavior })
  }, [])

  function handleScroll(e: React.UIEvent<HTMLDivElement>) {
    const el = e.currentTarget
    setIsAtBottom(el.scrollHeight - el.scrollTop - el.clientHeight < 80)
  }

  React.useEffect(() => {
    if (isAtBottom) scrollToBottom(isStreaming ? "auto" : "smooth")
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [messages, scrollToBottom, isStreaming])

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

  // Suprimir warning de tick no usado en render — se usa vía closure en formatRelativeTime
  void tick

  function handleSend() {
    const text = input.trim()
    if (!text || isStreaming || hasNoCredits) return
    setInput("")
    sendMessage({ text })
    if (taRef.current) {
      taRef.current.style.height = "auto"
      taRef.current.focus()
    }
  }

  function pickSuggestion(text: string) {
    setInput(text)
    requestAnimationFrame(() => taRef.current?.focus())
  }

  if (!bootstrap) return null

  return (
    <div className="flex h-[calc(100dvh-4rem)] flex-col gap-0">
      {/* Header de página — patrón estándar (items/contacts/etc.) */}
      <header className="mb-4 flex flex-row items-center justify-between gap-3">
        <div className="flex min-w-0 flex-col gap-1">
          <h1 className="truncate text-2xl font-semibold">Asistente</h1>
        </div>
        {messages.length > 0 && (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button variant="ghost" size="icon" onClick={clear} aria-label="Nueva conversación">
                <RotateCcw className="size-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent>Nueva conversación</TooltipContent>
          </Tooltip>
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
          <div className="flex-1 overflow-y-auto" onScroll={handleScroll}>
            <div className="mx-auto w-full max-w-3xl space-y-4 px-2 pt-6 pb-[10px] sm:px-6">
              {messages.map((message) => {
                const isUser = message.role === "user"
                // createdAt viaja embebido en el mensaje desde la primera vez
                // que se persistió; sobrevive reloads sin races de hidratación.
                const ts = (message as StoredMessage).createdAt

                // Defensa: el modelo (DeepSeek) a veces degenera y repite el
                // mismo texto en parts consecutivas, o emite fences de código
                // vacíos (```{}```). Ver agent-action-card.tsx para el detalle.
                let lastRenderedText: string | null = null

                return (
                  <div
                    key={message.id}
                    className={`group flex flex-col gap-1 ${isUser ? "items-end" : "items-start"}`}
                  >
                    {message.parts.map((part, idx) => {
                      if (isTextUIPart(part)) {
                        const trimmed = part.text.trim()
                        if (trimmed === "" || isEmptyCodeFence(trimmed)) return null
                        if (
                          lastRenderedText !== null &&
                          (lastRenderedText === trimmed || lastRenderedText.includes(trimmed))
                        ) {
                          return null
                        }
                        lastRenderedText = trimmed

                        // User: texto plano (lo que escribió). Assistant:
                        // markdown formateado + acciones (copiar/leer).
                        if (isUser) {
                          return (
                            <React.Fragment key={idx}>
                              <div className="max-w-[85%] rounded-2xl bg-foreground px-4 py-2.5 text-base leading-relaxed text-background whitespace-pre-wrap">
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
                          <div key={idx} className="w-full max-w-[90%] space-y-1">
                            <div className="px-1 py-1 text-foreground">
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

                      if (isToolOrDynamicToolUIPart(part)) {
                        if (part.type === "tool-register_action" && part.state === "output-available") {
                          const isLatest = idx === message.parts.length - 1
                          return (
                            <RegisterActionCard
                              key={idx}
                              input={part.input as never}
                              output={part.output as never}
                              disabled={isStreaming || !isLatest}
                              onConfirm={() => sendMessage({ text: "Sí, confirmo" })}
                              onCancel={() => sendMessage({ text: "No, cancelá" })}
                            />
                          )
                        }
                        if (part.type === "tool-execute_action" && part.state === "output-available") {
                          return <ExecuteActionSummary key={idx} output={part.output as never} />
                        }
                        return null
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
          <div className="relative bg-background/80 backdrop-blur">
            {/* Bajar al último mensaje — solo con el hilo scrolleado arriba;
                absolute para que el input no se mueva de lugar. */}
            {!isAtBottom && (
              <Button
                type="button"
                size="icon"
                variant="secondary"
                aria-label="Ir al último mensaje"
                onClick={() => scrollToBottom()}
                className="absolute -top-11 left-1/2 z-10 size-9 -translate-x-1/2 rounded-full border shadow-md"
              >
                <ArrowDown className="size-4" />
              </Button>
            )}
            <div className="mx-auto w-full max-w-3xl px-2 pt-0 pb-[10px] sm:px-6">

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
              <p className="mt-2 text-center text-xs text-muted-foreground">
                Punto usa IA y puede cometer errores. Verificá la información importante.
              </p>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
