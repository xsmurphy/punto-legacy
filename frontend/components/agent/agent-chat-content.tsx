"use client"

import * as React from "react"
import { isTextUIPart, isToolOrDynamicToolUIPart, type UIMessage } from "ai"
import { ArrowDown, MessageCircle, TriangleAlert, Upload, X } from "lucide-react"
import Link from "next/link"
import { Button } from "@/components/ui/button"
import { AgentInputBox } from "@/components/agent/agent-input-box"
import { MessageMarkdown } from "@/components/agent/message-markdown"
import { MessageActions } from "@/components/agent/message-actions"
import { RegisterActionCard, ExecuteActionSummary, isEmptyCodeFence } from "@/components/agent/agent-action-card"
import { AgentChart, AgentChartSkeleton } from "@/components/agent/agent-chart"
import { ClearChatButton } from "@/components/agent/clear-chat-button"
import { AgentSettingsDialog } from "@/components/agent/agent-settings-dialog"
import { ThinkingIndicator } from "@/components/agent/thinking-indicator"
import type { AttachmentDraft } from "@/lib/agent/attachment-types"
import type { StoredMessage } from "@/lib/agent/chat-history-store"
import { formatRelativeTime } from "@/lib/agent/format-relative-time"
import { isTruncated } from "@/lib/agent/truncation"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { cn } from "@/lib/utils"

/**
 * PRESENTACIÓN del chat del asistente — la MISMA en el panel y en la caja.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ NO TIENE HOOKS DE DATOS
 *
 * Hasta 2026-08-30 este componente llamaba `useAgentChat()`, `useSettings()` y
 * `useAiBalance()` adentro. Las tres resuelven con la credencial del PANEL, así
 * que el POS —token-only por mandato, ver `feedback_pos_token_only_no_realms`—
 * no podía montarlo y terminó con una copia propia del markup
 * (`components/pos/pos-agent-dialog.tsx`). Dos copias del mismo chat es
 * exactamente lo que se desincroniza a la primera: el owner reportó que la caja
 * "no se ve como el drawer del panel" el mismo día que se shippeó.
 *
 * Ahora todo lo que necesitaba una credencial entra por props y cada superficie
 * trae su propio DUEÑO DE DATOS:
 *
 *   - panel → `components/agent/agent-chat-panel.tsx` (useAgentChat + useSettings
 *     + useAiBalance, credencial de panel)
 *   - caja  → `components/pos/pos-agent-dialog.tsx` (usePosAgentChat con Bearer
 *     del device + `useCatalogStore`, cero cookies)
 *
 * La paridad visual pasa a ser por CONSTRUCCIÓN: hay un solo markup.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * INTERRUPTORES
 *
 * Todo lo exclusivo del panel se apaga con una prop booleana de default `true`
 * —mismo criterio que `showAttach`/`showVoice` de `agent-input-box.tsx`—, así
 * que el panel no cambia ni de aspecto ni de comportamiento por este refactor.
 *
 * `showActions` gatea las cards de confirmación de `register_action` /
 * `execute_action`. Hasta el 2026-08-31 gateaba TAMBIÉN los gráficos, y esa
 * mezcla se volvió un problema el día que el asistente de la caja empezó a
 * escribir: la caja necesita las cards (sin ellas la confirmación se degrada a
 * tipear "sí") y sigue sin poder montar `AgentChart`, que lee `useBootstrap()`
 * —credencial de PANEL— y no tiene sentido en una tablet de mostrador. Un solo
 * interruptor obligaba a elegir entre una regresión de UX y una de auth, así
 * que son dos: `showActions` y `showCharts`. Los demás hijos
 * se auditaron import por import y son presentación pura: `MessageMarkdown`,
 * `MessageActions`, `AgentInputBox`, `ThinkingIndicator`, `ClearChatButton`.
 * `AgentSettingsDialog` sí usa `useSettings()`/`useUpdateSettings()` → va detrás
 * de `showSettings`.
 */

/** Estado del `useChat` del SDK (`ChatStatus`). Union literal para no acoplar el tipo. */
type ChatStatus = "submitted" | "streaming" | "ready" | "error"

export interface AgentChatContentProps {
  // ── Estado del chat (lo provee el dueño de datos de cada superficie) ──────
  messages: UIMessage[]
  status: ChatStatus
  error?: Error
  /** Envía un mensaje al thread. El wrapper decide con qué transport viaja. */
  sendMessage: (message: { text: string }) => void
  /** Vacía el thread (y el historial persistido, donde exista). */
  onClear: () => void

  // ── Identidad y textos ───────────────────────────────────────────────────
  /** Nombre configurable del asistente. En el panel sale de Ajustes; en la caja es fijo. */
  agentName: string
  /** Línea chica bajo el nombre. La usa la caja para declarar el alcance de los datos. */
  headerSubtitle?: React.ReactNode
  /** Reemplaza el texto del thread vacío. */
  renderEmpty?: React.ReactNode

  // ── Interruptores (default = comportamiento del panel) ────────────────────
  showHeader?: boolean
  /** Engranaje de Ajustes del asistente — usa credencial de panel. */
  showSettings?: boolean
  /** Cards de confirmación de `register_action`/`execute_action`. */
  showActions?: boolean
  /** Gráficos (`render_chart`). `AgentChart` usa `useBootstrap()`: solo panel. */
  showCharts?: boolean
  /** Adjuntos: drag-and-drop sobre el thread + botón de adjuntar en el input. */
  showAttachments?: boolean
  /** Botón de voz del input ("próximamente"). */
  showVoice?: boolean
  /** Banner de saldo + link a comprar créditos. Solo panel: `/history-billing` es ruta de panel. */
  showCredits?: boolean
  /** Descuenta las áreas seguras del dispositivo. Lo pide un contenedor fullscreen del POS. */
  safeArea?: boolean

  // ── Créditos (solo panel) ────────────────────────────────────────────────
  hasNoCredits?: boolean

  // ── Adjuntos (solo panel) ────────────────────────────────────────────────
  attachments?: AttachmentDraft[]
  onAddAttachment?: (file: File) => void
  onRemoveAttachment?: (id: string) => void
  onClearAttachments?: () => void

  // ── Input ────────────────────────────────────────────────────────────────
  initialInput?: string
  onInputChange?: (v: string) => void
  /** Deshabilita el envío por una razón de la superficie (ej. la caja sin conexión). */
  inputDisabled?: boolean
  inputPlaceholder?: string
  /** Aviso sobre el input — el impedimento se informa donde está la acción. */
  inputNotice?: React.ReactNode
  /** Enfoca el textarea al montar. La caja se opera por teclado. */
  autoFocus?: boolean

  /** Botón de cerrar en el header. Lo usa el contenedor que apaga su propia X. */
  onClose?: () => void
  className?: string
}

export function AgentChatContent({
  messages,
  status,
  error,
  sendMessage,
  onClear,
  agentName,
  headerSubtitle,
  renderEmpty,
  showHeader = true,
  showSettings = true,
  showActions = true,
  showCharts = true,
  showAttachments = true,
  showVoice = true,
  showCredits = true,
  safeArea = false,
  hasNoCredits = false,
  attachments,
  onAddAttachment,
  onRemoveAttachment,
  onClearAttachments,
  initialInput,
  onInputChange,
  inputDisabled = false,
  inputPlaceholder,
  inputNotice,
  autoFocus = false,
  onClose,
  className,
}: AgentChatContentProps) {
  const [input, setInput] = React.useState("")
  const bottomRef = React.useRef<HTMLDivElement>(null)
  const [isAtBottom, setIsAtBottom] = React.useState(true)
  const taRef = React.useRef<HTMLTextAreaElement>(null)
  const [tick, setTick] = React.useState(0)

  const isStreaming = status === "streaming" || status === "submitted"

  /** Último mensaje del hilo: gatea el botón de continuar una respuesta cortada. */
  const lastMessageId = messages[messages.length - 1]?.id

  const is402 =
    showCredits &&
    (error?.message?.includes("Sin créditos") || error?.message?.includes("402"))
  // El body del endpoint de chat viaja como texto plano en error.message. Si es
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

  // El timeout espera a que el content del portal esté montado y animado; sin
  // eso el foco se pierde con la animación de entrada del overlay.
  React.useEffect(() => {
    if (!autoFocus) return
    const t = setTimeout(() => taRef.current?.focus(), 80)
    return () => clearTimeout(t)
  }, [autoFocus])

  function handleSend() {
    const text = input.trim()
    if (!text && (!attachments || attachments.length === 0)) return
    if (isStreaming || hasNoCredits || inputDisabled) return

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
    onClearAttachments?.()
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
    if (!showAttachments) return false
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
    for (const f of files) onAddAttachment?.(f)
  }

  return (
    <div
      className={cn(
        "flex flex-col h-full relative",
        // Áreas seguras laterales: en un contenedor fullscreen del POS el chat
        // toca los bordes físicos del dispositivo. Arriba y abajo los descuentan
        // el header y la zona del input, para que el borde del header siga
        // cruzando de lado a lado.
        safeArea && "max-sm:pl-[var(--safe-l)] max-sm:pr-[var(--safe-r)]",
        className
      )}
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
        <div
          className={cn(
            "shrink-0 border-b px-4 py-3",
            safeArea && "max-sm:pt-[calc(0.75rem+var(--safe-t))]"
          )}
        >
          <div className="flex items-center justify-between gap-2">
            <div className="flex min-w-0 items-center gap-2">
              <div className="flex size-7 shrink-0 items-center justify-center rounded-full bg-foreground text-background">
                <MessageCircle className="size-4" />
              </div>
              <div className="flex min-w-0 flex-col">
                <span className="truncate text-sm font-medium">{agentName}</span>
                {headerSubtitle ? (
                  <span className="truncate text-xs text-muted-foreground">{headerSubtitle}</span>
                ) : null}
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-1">
              {showSettings && <AgentSettingsDialog />}
              {messages.length > 0 && <ClearChatButton onClear={onClear} />}
              {onClose && (
                <Button variant="ghost" size="icon" aria-label="Cerrar" onClick={onClose}>
                  <X className="size-4" />
                </Button>
              )}
            </div>
          </div>
        </div>
      )}

      <div
        className="flex-1 min-h-0 overflow-y-auto px-4 py-3 space-y-3"
        onScroll={handleScroll}
      >
        {messages.length === 0 &&
          (renderEmpty ?? (
            <p className="text-center text-sm text-muted-foreground pt-8">
              Hola, soy {agentName}. Podés preguntarme sobre ventas, ingresos u otros datos del negocio.
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
                  // acciones (copiar/leer). Mismo tratamiento en las dos
                  // superficies — la pieza visual es una sola.
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

                // Las cards de confirmación se muestran en las dos superficies
                // (la caja también escribe desde 2026-08-31). Los gráficos no:
                // `AgentChart` lee el bootstrap del PANEL, así que en la caja
                // `showCharts` es false y ese hijo nunca se monta.
                if ((showActions || showCharts) && isToolOrDynamicToolUIPart(part)) {
                  if (showActions && part.type === "tool-register_action" && part.state === "output-available") {
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
                  if (showActions && part.type === "tool-execute_action" && part.state === "output-available") {
                    return <ExecuteActionSummary key={idx} output={part.output as never} />
                  }
                  if (showCharts && part.type === "tool-render_chart") {
                    if (part.state === "output-available") {
                      return <AgentChart key={idx} input={part.input} />
                    }
                    if (part.state === "input-available") {
                      return <AgentChartSkeleton key={idx} />
                    }
                    return null
                  }
                  return null
                }

                return null
              })}

              {/* Corte por longitud. Va al PIE del mensaje y no arriba del
                  input, porque lo que quedó incompleto es ESTE mensaje y no la
                  conversación: pegado al texto truncado, el aviso se lee junto
                  a lo que califica, y sobrevive con él en el historial (la
                  señal viaja en `message.metadata`, ver lib/agent/truncation.ts).
                  Sin esto, media respuesta se ve igual que una entera — así se
                  entregó un balance con los activos completos y ni pasivos ni
                  patrimonio.

                  El botón de continuar solo aparece en el ÚLTIMO mensaje y con
                  el stream quieto: pedir la continuación de un mensaje viejo
                  arrastraría al modelo a retomar algo que la conversación ya
                  dejó atrás. En los truncados anteriores queda el aviso solo,
                  que es lo que importa. Mismo criterio de `isLatest` que usa
                  RegisterActionCard más arriba. */}
              {!isUser && isTruncated(message) && (
                <Alert className="mt-1 w-full max-w-[95%]">
                  <TriangleAlert />
                  <AlertDescription>
                    La respuesta se cortó porque llegó al largo máximo: está incompleta.
                  </AlertDescription>
                  {message.id === lastMessageId && !isStreaming && (
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
                </Alert>
              )}
            </div>
          )
        })}

        <ThinkingIndicator
          messages={messages}
          isStreaming={isStreaming}
          bubbleClassName="flex items-center gap-1.5 rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground"
        />

        <div ref={bottomRef} />
      </div>

      <div
        className={cn(
          "relative shrink-0 px-4 py-3",
          safeArea && "max-sm:pb-[calc(0.75rem+var(--safe-b))]"
        )}
      >
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
        {showCredits && (hasNoCredits || is402) && (
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
        {inputNotice}
        <AgentInputBox
          ref={taRef}
          value={input}
          onChange={handleInputChange}
          onSend={handleSend}
          disabled={isStreaming || hasNoCredits || inputDisabled}
          placeholder={
            hasNoCredits ? "Sin créditos para usar el asistente" : inputPlaceholder
          }
          attachments={showAttachments ? attachments : undefined}
          onAddFiles={(files) => files.forEach((f) => onAddAttachment?.(f))}
          onRemoveAttachment={onRemoveAttachment}
          showAttach={showAttachments}
          showVoice={showVoice}
        />
        <p className="mt-2 text-center text-xs text-muted-foreground">
          Punto usa IA y puede cometer errores. Verificá la información importante.
        </p>
      </div>
    </div>
  )
}
