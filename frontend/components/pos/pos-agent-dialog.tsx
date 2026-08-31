"use client"

import * as React from "react"
import { isTextUIPart } from "ai"
import { MessageCircle, WifiOff } from "lucide-react"

import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { EmptyState } from "@/components/empty-state"
import { AgentInputBox } from "@/components/agent/agent-input-box"
import { MessageMarkdown } from "@/components/agent/message-markdown"
import { ThinkingIndicator } from "@/components/agent/thinking-indicator"
import { ClearChatButton } from "@/components/agent/clear-chat-button"
import { usePosAgentChat } from "@/lib/pos/use-pos-agent-chat"
import { useCatalogStore } from "@/lib/catalog/store"
import { usePosUIStore } from "@/lib/ui/store"
import { useOnlineStatus } from "@/hooks/use-online-status"

/**
 * Asistente de la CAJA — diálogo del chat.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DÓNDE SE MONTA
 *
 * En `app/(pos)/layout.tsx`, junto a `<PosModeDialog />` y por el MISMO
 * motivo documentado ahí: su trigger vive en el footer del `PosSidebar`, que
 * en mobile ES un drawer y se cierra al tocar cualquier item. Un chat montado
 * dentro del sidebar se desmontaría con él en el mismo gesto que lo abre. El
 * estado de apertura vive en `usePosUIStore` por eso.
 *
 * Sin FAB: taparía el CTA de cobrar (context/59 D7, decisión del owner).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ `Dialog` + `mobileFullscreen` Y NO BOTTOM DRAWER
 *
 * §2.2 de context/14: el bottom drawer es para modales CHICOS de interacción
 * (confirmación, descuento, cantidad) y el `Dialog` es el default para
 * contenido. Una conversación con scrollback y un campo de texto es contenido:
 * necesita alto, no un gesto rápido.
 *
 * `mobileFullscreen` es exactamente su caso de uso: bajo `sm` el diálogo
 * centrado con `max-h-[85dvh]` deja poquísimo alto útil cuando se abre el
 * teclado virtual, y este modal es 100% teclado. El primitive ya descuenta
 * `--kb-inset` del borde inferior, así que el input queda apoyado sobre el
 * teclado en vez de detrás.
 *
 * `sectioned`: header fijo + cuerpo scrolleable + input fijo abajo, con el
 * gutter de 24px que repone el propio primitive.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ NO REUSA `AgentChatContent`
 *
 * Es la trampa de esta pantalla y se verificó antes de decidir:
 * `components/agent/agent-chat-content.tsx` llama `useSettings()` y
 * `useAiBalance()`, que resuelven con la credencial del PANEL. En una caja
 * pareada eso es el bug de `80a21be2` otra vez — el componente se rendería
 * distinto según cómo se hubiera abierto la caja.
 *
 * Lo que sí se reusa es presentación PURA, verificada import por import:
 * `MessageMarkdown` (react-markdown + cn), `AgentInputBox` (Button/Textarea/
 * Tooltip), `ThinkingIndicator` (React + tipos de `ai`) y `ClearChatButton`
 * (Button + AlertDialog). Ninguno toca hooks de panel.
 *
 * El nombre del comercio, la moneda, el país y la zona horaria salen de
 * `useCatalogStore` (config del POS), NUNCA del bootstrap del panel.
 */
export function PosAgentDialog() {
  const open = usePosUIStore((s) => s.agentDialogOpen)
  const setOpen = usePosUIStore((s) => s.setAgentDialogOpen)
  const config = useCatalogStore((s) => s.config)
  const isOnline = useOnlineStatus()

  const { messages, sendMessage, status, error, clear } = usePosAgentChat({
    companyName: config?.companyName ?? "",
    currency: config?.currency ?? "",
    country: config?.country ?? "",
    timezone: config?.timezone ?? "",
  })

  const [input, setInput] = React.useState("")
  const bottomRef = React.useRef<HTMLDivElement>(null)
  const inputRef = React.useRef<HTMLTextAreaElement>(null)

  const isStreaming = status === "streaming" || status === "submitted"

  // Autofocus al abrir: la caja se opera por teclado y quien abrió esto ya
  // sabe qué va a preguntar (`project_pos_touch_keyboard_first`). El timeout
  // espera a que el content del portal esté montado y animado.
  React.useEffect(() => {
    if (!open) return
    const t = setTimeout(() => inputRef.current?.focus(), 80)
    return () => clearTimeout(t)
  }, [open])

  // Auto-scroll al final con cada cambio del thread. A diferencia del panel no
  // hay "solo si estabas abajo": las respuestas de mostrador son de 2-3
  // líneas y no hay scrollback largo que releer mientras llega la siguiente.
  React.useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" })
  }, [messages])

  // El item del sidebar ya queda deshabilitado sin red, pero la conexión se
  // puede caer con el diálogo ABIERTO: ahí el impedimento se informa en el
  // control que impide — el botón de enviar
  // (`feedback_pos_alerts_on_the_action_not_banners`).
  const canSend = isOnline && !isStreaming

  function handleSend() {
    const text = input.trim()
    if (!text || !canSend) return
    setInput("")
    sendMessage({ text })
  }

  const errorMessage = React.useMemo(() => {
    if (!error) return null
    try {
      const parsed = JSON.parse(error.message) as { error?: string }
      if (parsed?.error) return parsed.error
    } catch {
      // no era JSON — el texto plano ya es el mensaje a mostrar
    }
    return error.message || "No se pudo completar la consulta. Probá de nuevo."
  }, [error])

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent
        mobileFullscreen
        sectioned
        className="sm:max-w-2xl sm:h-[min(85dvh,44rem)]"
      >
        <DialogHeader className="flex-row items-center justify-between gap-2 space-y-0">
          <div className="flex items-center gap-2">
            <div className="flex size-7 items-center justify-center rounded-full bg-foreground text-background">
              <MessageCircle className="size-4" />
            </div>
            <div>
              <DialogTitle className="text-base">Asistente</DialogTitle>
              {/* El alcance de los datos es de SUCURSAL, nunca del turno ni de
                  esta caja (Roc::build filtra por company + outlet y nada
                  más). El copy lo dice para que nadie lea "lo mío". */}
              <DialogDescription className="text-sm">
                Consultas de esta sucursal — no hace cambios
              </DialogDescription>
            </div>
          </div>
          {messages.length > 0 && <ClearChatButton onClear={clear} />}
        </DialogHeader>

        <DialogBody className="space-y-3">
          {messages.length === 0 && (
            <EmptyState
              ghost={false}
              icon={MessageCircle}
              title="Preguntá lo que necesites"
              description="Precios, stock, saldo de un cliente o las ventas de esta sucursal. Solo consulta: no modifica nada."
            />
          )}

          {messages.map((message) => {
            const isUser = message.role === "user"
            return (
              <div
                key={message.id}
                className={`flex flex-col gap-1 ${isUser ? "items-end" : "items-start"}`}
              >
                {message.parts.map((part, idx) => {
                  if (!isTextUIPart(part)) return null
                  const trimmed = part.text.trim()
                  if (trimmed === "") return null

                  if (isUser) {
                    return (
                      <div
                        key={idx}
                        className="max-w-[85%] rounded-2xl bg-foreground px-3 py-2 text-base leading-relaxed whitespace-pre-wrap text-background"
                      >
                        {trimmed}
                      </div>
                    )
                  }
                  return (
                    <div key={idx} className="max-w-full text-base leading-relaxed">
                      <MessageMarkdown content={trimmed} />
                    </div>
                  )
                })}
              </div>
            )
          })}

          <ThinkingIndicator messages={messages} isStreaming={isStreaming} />

          {errorMessage && (
            <p className="text-sm text-destructive">{errorMessage}</p>
          )}

          <div ref={bottomRef} />
        </DialogBody>

        <div className="px-6 pb-4">
          {!isOnline && (
            <p className="mb-2 flex items-center gap-1.5 text-sm text-muted-foreground">
              <WifiOff className="size-4" />
              Sin conexión — el asistente necesita internet
            </p>
          )}
          <AgentInputBox
            ref={inputRef}
            value={input}
            onChange={setInput}
            onSend={handleSend}
            disabled={!canSend}
            showAttach={false}
            showVoice={false}
            placeholder={isOnline ? "Preguntá algo…" : "Sin conexión"}
          />
        </div>
      </DialogContent>
    </Dialog>
  )
}
