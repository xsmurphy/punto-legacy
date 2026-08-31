"use client"

import * as React from "react"
import { MessageCircle, WifiOff } from "lucide-react"

import { Dialog, DialogContent, DialogTitle } from "@/components/ui/dialog"
import { EmptyState } from "@/components/empty-state"
import { AgentChatContent } from "@/components/agent/agent-chat-content"
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
 * ESTE ARCHIVO ES EL DUEÑO DE DATOS, NO LA UI
 *
 * La UI del chat es `components/agent/agent-chat-content.tsx` — LA MISMA que
 * renderiza el drawer del panel. Acá solo se resuelve de dónde salen los datos:
 * `usePosAgentChat` (Bearer del device, `credentials: "omit"`) y
 * `useCatalogStore` (config del POS). Ni un hook con credencial de panel entra
 * en este árbol: `showSettings`, `showActions`, `showAttachments` y
 * `showCredits` van en `false` justamente porque los componentes que gatean
 * —`AgentSettingsDialog` (useSettings), `AgentChart` (useBootstrap) y el link a
 * `/history-billing`— son del panel.
 *
 * Hasta el 2026-08-30 este archivo tenía una COPIA del markup del chat, por el
 * temor —correcto en su momento— de arrastrar esos hooks. El resultado fue el
 * previsible: el owner reportó que la caja no se ve como el panel. El markup
 * duplicado se eliminó; la paridad ahora es por construcción.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ `Dialog` + `mobileFullscreen` Y NO EL `Sheet` LATERAL DEL PANEL
 *
 * La paridad que pidió el owner es del CONTENIDO (burbujas, tipografía,
 * espaciados, header, input), no del contenedor: §2.2 de context/14 reserva el
 * lateral para paneles AUXILIARES a una vista que sigue siendo el foco, y
 * prohíbe contenido denso ahí. Una conversación con scrollback en una tablet de
 * mostrador es contenido, y el ancho de un lateral juega en contra.
 *
 * `mobileFullscreen` es exactamente su caso de uso: bajo `sm` el diálogo
 * centrado con `max-h-[85dvh]` deja poquísimo alto útil cuando se abre el
 * teclado virtual, y este modal es 100% teclado. El primitive ya descuenta
 * `--kb-inset` del borde inferior.
 *
 * `sectioned` + `max-sm:p-0`: el chat trae chrome propio (header con borde,
 * cuerpo scrolleable, input al pie), así que declara que administra su propio
 * layout vertical y resetea el gutter que `mobileFullscreen` pone por default.
 * Es el patrón documentado en el docblock de `DialogContent` para los shells
 * que montan un módulo entero adentro del modal (ver `customer-dialog.tsx`);
 * los insets del dispositivo los descuenta el chat vía `safeArea`.
 *
 * `showCloseButton={false}` + `onClose`: la X del primitive es absoluta en la
 * esquina y caería justo encima de las acciones del header del chat. El chat
 * la rinde adentro del header, en fila con las demás.
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

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent
        mobileFullscreen
        sectioned
        showCloseButton={false}
        aria-describedby={undefined}
        className="max-sm:p-0 sm:h-[min(85dvh,44rem)] sm:w-full sm:max-w-2xl"
      >
        {/* El título accesible del diálogo. Visible ya está el header del chat,
            que es el mismo del panel. */}
        <DialogTitle className="sr-only">Asistente</DialogTitle>

        <AgentChatContent
          className="min-h-0 flex-1"
          messages={messages}
          status={status}
          error={error}
          sendMessage={(m) => void sendMessage(m)}
          onClear={clear}
          agentName="Asistente"
          // El alcance de los datos es de SUCURSAL, nunca del turno ni de esta
          // caja (Roc::build filtra por company + outlet y nada más). El copy lo
          // dice para que nadie lea "lo mío".
          headerSubtitle="Consultas de esta sucursal — no hace cambios"
          // Todo lo que resuelve con credencial de PANEL, apagado.
          showSettings={false}
          showActions={false}
          showAttachments={false}
          showVoice={false}
          showCredits={false}
          safeArea
          autoFocus
          onClose={() => setOpen(false)}
          // El item del sidebar ya queda deshabilitado sin red, pero la conexión
          // se puede caer con el diálogo ABIERTO: ahí el impedimento se informa
          // en el control que impide — el botón de enviar
          // (`feedback_pos_alerts_on_the_action_not_banners`).
          inputDisabled={!isOnline}
          inputPlaceholder={isOnline ? "Preguntá algo…" : "Sin conexión"}
          inputNotice={
            !isOnline ? (
              <p className="mb-2 flex items-center gap-1.5 text-sm text-muted-foreground">
                <WifiOff className="size-4" />
                Sin conexión — el asistente necesita internet
              </p>
            ) : null
          }
          renderEmpty={
            <EmptyState
              ghost={false}
              icon={MessageCircle}
              title="Preguntá lo que necesites"
              description="Precios, stock, saldo de un cliente o las ventas de esta sucursal. Solo consulta: no modifica nada."
            />
          }
        />
      </DialogContent>
    </Dialog>
  )
}
