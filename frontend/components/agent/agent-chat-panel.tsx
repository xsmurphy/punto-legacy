"use client"

import * as React from "react"
import { AgentChatContent } from "@/components/agent/agent-chat-content"
import { useAgentChat } from "@/lib/agent/use-agent-chat"
import { useAiBalance, useInvalidateAiBalance } from "@/hooks/use-ai-balance"
import { useSettings } from "@/hooks/use-settings"

/**
 * DUEÑO DE DATOS del chat del asistente en el PANEL.
 *
 * `AgentChatContent` es presentación pura desde el refactor del 2026-08-30 (ver
 * su docblock): todo lo que necesita la credencial del panel se resuelve acá y
 * baja por props. La caja tiene su propio dueño de datos
 * (`components/pos/pos-agent-dialog.tsx`) y las dos superficies renderizan el
 * MISMO componente de presentación.
 *
 * Este wrapper se monta DENTRO del `SheetContent` (no afuera) a propósito:
 * Radix desmonta el content al cerrar, así que `useAgentChat` —que hidrata el
 * historial de localStorage— y las queries de settings/saldo siguen corriendo
 * solo con el chat abierto, exactamente como antes del refactor. Subirlos al
 * `AgentChatFloating`, que está montado en todas las páginas del panel, los
 * haría correr siempre.
 *
 * El balance se consulta para gatear el input cuando llega a 0, pero NO se
 * muestra en el header — esa info ya vive en /history-billing. El banner "Sin
 * créditos disponibles" sí queda porque es el CTA que el user necesita en ese
 * momento (compra inmediata).
 */
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

export function AgentChatPanel({
  companyName,
  viewOutletId,
  viewOutletName,
  showHeader = true,
  initialInput,
  onInputChange,
  renderEmpty,
}: Props) {
  const {
    messages,
    sendMessage,
    status,
    error,
    clear,
    attachments,
    addAttachment,
    removeAttachment,
    clearAttachments,
  } = useAgentChat({ companyName, viewOutletId, viewOutletName })

  const { data: settingsData } = useSettings()
  const agentName = settingsData?.agentName?.trim() || "Asistente"

  const { data: balData } = useAiBalance()
  const balance = balData?.balance ?? null
  const hasNoCredits = balance !== null && balance <= 0

  // Al terminar una respuesta el saldo cambió: refrescarlo para que el gate del
  // input y el banner reflejen el consumo sin recargar la página.
  const invalidateBalance = useInvalidateAiBalance()
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

  return (
    <AgentChatContent
      messages={messages}
      status={status}
      error={error}
      sendMessage={(m) => void sendMessage(m)}
      onClear={clear}
      agentName={agentName}
      showHeader={showHeader}
      hasNoCredits={hasNoCredits}
      attachments={attachments}
      onAddAttachment={addAttachment}
      onRemoveAttachment={removeAttachment}
      onClearAttachments={clearAttachments}
      initialInput={initialInput}
      onInputChange={onInputChange}
      renderEmpty={renderEmpty}
    />
  )
}
