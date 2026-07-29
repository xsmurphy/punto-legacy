"use client"

import * as React from "react"
import { isTextUIPart, isToolOrDynamicToolUIPart } from "ai"
import { ArrowDown, MessageCircle, Upload } from "lucide-react"
import Link from "next/link"
import { Button } from "@/components/ui/button"
import { useAiBalance, useInvalidateAiBalance } from "@/hooks/use-ai-balance"
import { AgentInputBox } from "@/components/agent/agent-input-box"
import { MessageMarkdown } from "@/components/agent/message-markdown"
import { MessageActions } from "@/components/agent/message-actions"
import { RegisterActionCard, ExecuteActionSummary, isEmptyCodeFence } from "@/components/agent/agent-action-card"
import { useAgentChat } from "@/lib/agent/use-agent-chat"
import type { StoredMessage } from "@/lib/agent/chat-history-store"
import { formatRelativeTime } from "@/lib/agent/format-relative-time"

// El balance se sigue consultando para gatear el input cuando llega a 0,
// pero NO se muestra en el header — esa info ya vive en /history-billing.
// El banner "Sin créditos disponibles" sí queda porque es el CTA que el
// user necesita en ese momento (compra inmediata).

interface Props {
  companyName: string
  /** UUID de la sucursal seleccionada (view-scope), "all", o "" si no hay override. */
  viewOutletId: string
  /** Nombre de la sucursal seleccionada para el contexto del prompt. */
  viewOutletName: string
  showHeader?: boolean
  initialInput?: string
  onInputChange?: (v: string) => void
  renderEmpty?: React.ReactNode
}

export function AgentChatContent({
  companyName,
  viewOutletId,
  viewOutletName,
  showHeader = true,
  initialInput,
  onInputChange,
  renderEmpty,
}: Props) {
  const [input, setInput] = React.useState("")
  const bottomRef = React.useRef<HTMLDivElement>(null)
  const [isAtBottom, setIsAtBottom] = React.useState(true)
  const taRef = React.useRef<HTMLTextAreaElement>(null)
  const [tick, setTick] = React.useState(0)

  const { messages, sendMessage, status, error, attachments, addAttachment, removeAttachment, clearAttachments } = useAgentChat({
    companyName,
    viewOutletId,
    viewOutletName,
  })

  const isStreaming = status === "streaming" || status === "submitted"

  const { data: balData } = useAiBalance()
  const balance = balData?.balance ?? null
  const hasNoCredits = balance !== null && balance <= 0
  const is402 = error?.message?.includes("Sin créditos") || error?.message?.includes("402")
  // El body de /api/agent/chat viaja como texto plano en error.message. Si es
  // un error HTTP (402/500 tempranos) llega como JSON `{"error":"..."}`; si es
  // un error de stream (ver onError en route.ts) llega como texto simple. En
  // ambos casos queremos el mensaje accionable, nunca "algo salió mal".
  const genericErrorMessage = React.useMemo(() => {
    if (!error || is402) return null
    try {
      const parsed = JSON.parse(error.message) as { error?: string }
      if (parsed?.error) return parsed.error
    } catch {
      // no era JSON — el texto plano del error ya es el mensaje a mostrar
    }
    return error.message || "No se pudo completar el pedido. Probá de nuevo."
  }, [error, is402])
  const invalidateBalance = useInvalidateAiBalance()

  // Auto-scroll SOLO si el usuario ya estaba abajo. Si subió a releer algo,
  // una respuesta nueva no debe arrastrarlo — para eso está el botón de bajar.
  const scrollToBottom = React.useCallback((behavior: ScrollBehavior = "smooth") => {
    bottomRef.current?.scrollIntoView({ behavior })
  }, [])

  function handleScroll(e: React.UIEvent<HTMLDivElement>) {
    const el = e.currentTarget
    // Margen de 80px: el navegador redondea scrollTop en fraccionales y sin
    // holgura el estado parpadea entre "abajo" y "no abajo" al hacer scroll.
    setIsAtBottom(el.scrollHeight - el.scrollTop - el.clientHeight < 80)
  }

  React.useEffect(() => {
    // Mientras streamea, scroll INSTANTÁNEO: el texto crece de a poco y cada
    // animación suave se pisa con la siguiente, que es lo que se ve como el
    // scroll "saltando" en cada bloque. Suave queda solo para el salto de un
    // mensaje ya terminado.
    if (isAtBottom) scrollToBottom(isStreaming ? "auto" : "smooth")
    // `isAtBottom` a propósito fuera de las deps: el efecto se dispara por
    // mensajes nuevos y lee la posición del momento. Incluirlo haría saltar
    // el scroll cada vez que el usuario llega al fondo por su cuenta.
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
    if (!text && (!attachments || attachments.length === 0)) return
    if (isStreaming || hasNoCredits) return

    const readyTabular = (attachments ?? []).filter(
      (a) => a.kind === "tabular" && a.status === "ready" && a.sessionId
    )

    let fullText = text
    if (readyTabular.length > 0) {
      const attachInfo = readyTabular.map((att) => {
        const colLine     = att.columns ? `columnas: ${att.columns.join(", ")}` : ""
        const sampleLines = (att.sample ?? [])
          .map((row) => row.join(" | "))
          .join("\n     ")
        return (
          `- ${att.filename ?? att.file.name} (tabular, ${Math.round(att.file.size / 1024)}KB)\n` +
          `  sessionId: ${att.sessionId}, ${att.rowCount ?? "?"} filas, ${colLine}\n` +
          (sampleLines ? `  Muestra (primeras filas):\n     ${sampleLines}` : "")
        )
      }).join("\n")

      const itemHeaders = "KIND,NOMBRE,SKU,MARCA,CATEGORIA,ETIQUETAS,DESCRIPCION,COSTO,PRECIO,IMPUESTO,SUCURSAL,DESCUENTO_PCT,UOM,MERMA_PCT,COMISION_PCT,STOCK_MINIMO"
      const contactHeaders = "TIPO,NOMBRE,TELEFONO,EMAIL,RUC_CI,DIRECCION,NOTAS"

      fullText =
        `[Adjuntos]\n${attachInfo}\n\n` +
        `Si el usuario pide importar, llamá la tool register_action con actions=[{action:"tabular_import", payload:{sessionId, kind:"items"|"contacts", mapping, mode:"insert"|"update"}}] (y luego execute_action con el confirmToken al confirmar). ` +
        `El mapping mapea cada campo canónico a la columna de origen del archivo. ` +
        `Headers canónicos items: ${itemHeaders}. ` +
        `Headers canónicos contactos: ${contactHeaders}. ` +
        `Si las columnas ya coinciden, mapping=null.\n\n` +
        (text ? text : "")
    }

    setInput("")
    clearAttachments()
    sendMessage({ text: fullText })
    if (taRef.current) taRef.current.style.height = "auto"
  }

  function handleInputChange(v: string) {
    setInput(v)
    onInputChange?.(v)
  }

  // Drag-and-drop sobre TODO el área del chat. Usamos un counter para evitar
  // el flicker que produce dragenter/dragleave al pasar sobre hijos anidados.
  const [isDragging, setIsDragging] = React.useState(false)
  const dragCounter = React.useRef(0)

  function hasFiles(e: React.DragEvent) {
    return Array.from(e.dataTransfer?.types ?? []).includes("Files")
  }

  function handleDragEnter(e: React.DragEvent) {
    if (!hasFiles(e)) return
    e.preventDefault()
    dragCounter.current += 1
    setIsDragging(true)
  }

  function handleDragOver(e: React.DragEvent) {
    if (!hasFiles(e)) return
    e.preventDefault()
    e.dataTransfer.dropEffect = "copy"
  }

  function handleDragLeave(e: React.DragEvent) {
    if (!hasFiles(e)) return
    dragCounter.current = Math.max(0, dragCounter.current - 1)
    if (dragCounter.current === 0) setIsDragging(false)
  }

  function handleDrop(e: React.DragEvent) {
    if (!hasFiles(e)) return
    e.preventDefault()
    dragCounter.current = 0
    setIsDragging(false)
    const files = Array.from(e.dataTransfer.files ?? [])
    for (const f of files) addAttachment(f)
  }

  return (
    <div
      className="flex flex-col h-full relative"
      onDragEnter={handleDragEnter}
      onDragOver={handleDragOver}
      onDragLeave={handleDragLeave}
      onDrop={handleDrop}
    >
      {isDragging && (
        <div className="pointer-events-none absolute inset-0 z-50 flex items-center justify-center bg-foreground/5 backdrop-blur-[2px]">
          <div className="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-foreground/40 bg-card px-8 py-6 shadow-lg">
            <Upload className="size-8 text-foreground/70" />
            <p className="text-sm font-medium text-foreground">Soltá para adjuntar</p>
            <p className="text-xs text-muted-foreground">Excel, CSV o imagen</p>
          </div>
        </div>
      )}
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

      <div
        className="flex-1 overflow-y-auto px-4 py-3 space-y-3"
        onScroll={handleScroll}
      >
        {messages.length === 0 &&
          (renderEmpty ?? (
            <p className="text-center text-sm text-muted-foreground pt-8">
              Hola, soy tu asistente de Punto. Podés preguntarme sobre ventas, ingresos u otros datos del negocio.
            </p>
          ))}

        {messages.map((message) => {
          const isUser = message.role === "user"
          const ts = (message as StoredMessage).createdAt

          // Defensa: el modelo (DeepSeek) a veces degenera y repite el mismo
          // texto en parts consecutivas, o emite fences de código vacíos
          // (```{}```). Ver agent-action-card.tsx para el detalle. Acá
          // filtramos ANTES de renderizar para no duplicar visualmente.
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

                  // User: plano (lo que escribió). Assistant: markdown +
                  // acciones (copiar/leer). Mismo tratamiento que la página
                  // /chat — la pieza visual es idéntica para que la UX no
                  // varíe entre FAB y página dedicada.
                  if (isUser) {
                    return (
                      <React.Fragment key={idx}>
                        <div className="max-w-[85%] rounded-2xl bg-foreground px-3 py-2 text-base text-background leading-relaxed whitespace-pre-wrap">
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
            <div className="flex items-center gap-1.5 rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground">
              <span className="size-3 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent" />
              <span>Pensando…</span>
            </div>
          </div>
        )}

        <div ref={bottomRef} />
      </div>

      <div className="relative px-4 py-3">
        {/* Bajar al último mensaje. Solo aparece con el hilo scrolleado hacia
            arriba; se apoya sobre el input sin empujarlo (absolute) para que
            la caja de escritura no se mueva de lugar. */}
        {!isAtBottom && messages.length > 0 && (
          <Button
            type="button"
            size="icon"
            variant="secondary"
            aria-label="Ir al último mensaje"
            onClick={() => scrollToBottom()}
            className="absolute -top-5 left-1/2 z-10 size-9 -translate-x-1/2 rounded-full border shadow-md"
          >
            <ArrowDown className="size-4" />
          </Button>
        )}
        {(hasNoCredits || is402) && (
          <div className="mb-2 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            Sin créditos disponibles.{" "}
            <Link href="/history-billing" className="underline font-medium">
              Comprar créditos
            </Link>
          </div>
        )}
        {genericErrorMessage && (
          <div className="mb-2 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            {genericErrorMessage}
          </div>
        )}
        <AgentInputBox
          ref={taRef}
          value={input}
          onChange={handleInputChange}
          onSend={handleSend}
          disabled={isStreaming || hasNoCredits}
          placeholder={hasNoCredits ? "Sin créditos para usar el asistente" : undefined}
          attachments={attachments}
          onAddFiles={(files) => files.forEach(addAttachment)}
          onRemoveAttachment={removeAttachment}
        />
        <p className="mt-2 text-center text-xs text-muted-foreground">
          Punto usa IA y puede cometer errores. Verificá la información importante.
        </p>
      </div>
    </div>
  )
}
