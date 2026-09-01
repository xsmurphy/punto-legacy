"use client"

import * as React from "react"
import { MessageCircle, WifiOff } from "lucide-react"

import { Sheet, SheetContent, SheetTitle } from "@/components/ui/sheet"
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
 * `usePosAgentChat` (Bearer del device + `X-Operator-Token`,
 * `credentials: "omit"`) y `useCatalogStore` (config del POS). Ni un hook con
 * credencial de panel entra en este árbol: `showSettings`, `showCharts`,
 * `showAttachments` y `showCredits` van en `false` justamente porque los
 * componentes que gatean —`AgentSettingsDialog` (useSettings), `AgentChart`
 * (useBootstrap) y el link a `/history-billing`— son del panel.
 *
 * `showActions` SÍ va prendido desde 2026-08-31: el asistente de la caja hace
 * cambios simples, y la tarjeta de confirmación es el control donde la persona
 * los aprueba de un toque. Es presentación pura —lee el input/output de la
 * tool-call, no una credencial— y por eso dejó de compartir interruptor con los
 * gráficos.
 *
 * Hasta el 2026-08-30 este archivo tenía una COPIA del markup del chat, por el
 * temor —correcto en su momento— de arrastrar esos hooks. El resultado fue el
 * previsible: el owner reportó que la caja no se ve como el panel. El markup
 * duplicado se eliminó; la paridad ahora es por construcción.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * EL CONTENEDOR ES EL `Sheet` LATERAL, EL MISMO QUE EL PANEL
 *
 * Decisión del owner, pedida dos veces (2026-08-30 y 2026-08-31): "no tiene la
 * misma UI que la versión del panel ni tampoco aparece en un drawer a la
 * derecha como en el panel". La paridad que quiere es COMPLETA — contenido y
 * contenedor—, y el asistente es la misma herramienta en las dos superficies:
 * que se abra distinto según dónde estés es exactamente la inconsistencia que
 * el pedido señala.
 *
 * Esto convive con §2.2 de context/14, que prohíbe el DRAWER lateral de vaul
 * para contenido denso en el POS. Acá el primitive es `Sheet` (Radix), que es
 * el que ya usa el panel para este mismo chat, y el caso es el que la regla
 * contempla: un panel auxiliar a una vista que sigue siendo el foco. El cajero
 * consulta algo SIN perder de vista el carrito — que es justamente lo que un
 * modal a pantalla completa le tapaba.
 *
 * Las medidas se copian del panel (`components/agent/agent-chat-floating.tsx`)
 * a propósito, incluido el `!w-[95vw]` con `important`: `SheetContent` trae
 * `data-[side=right]:w-3/4` con más especificidad que un `className` custom, y
 * sin el `!` el override se pierde. En mobile ese 95vw deja ver un borde del
 * POS por detrás, que es la señal de overlay y la zona de cierre al tocar
 * afuera.
 *
 * TECLADO: no se toca nada acá, pero NO por lo que decía este párrafo hasta el
 * 2026-09-01 ("el shell ya resta el teclado una sola vez, un segundo descuento
 * sería la doble resta"). Eso era falso: el `Sheet` es un portal `fixed` que
 * cuelga del `<body>`, y un body fijado no crea bloque contenedor para sus
 * descendientes fijos, así que este árbol nunca heredó el reposicionamiento
 * del shell — con el teclado abierto el header del chat quedaba fuera de vista
 * por arriba. La corrección va en el primitive (`components/ui/sheet.tsx`,
 * bordes verticales sobre `--kb-top`/`--kb-bottom`), no en este call-site: es
 * el mismo bug para todos los Sheet, y arreglarlo acá lo dejaba abierto en el
 * resto.
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
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetContent
        side="right"
        // `overlay={false}`, igual que el panel: sin fondo oscurecido, así el
        // cajero sigue viendo el carrito mientras consulta. Es lo que hace que
        // esto sea un panel auxiliar y no un modal que interrumpe la venta.
        overlay={false}
        showCloseButton={false}
        aria-describedby={undefined}
        className="flex !w-[95vw] flex-col p-0 sm:!w-full sm:max-w-md"
      >
        {/* El título accesible del diálogo. Visible ya está el header del chat,
            que es el mismo del panel. */}
        <SheetTitle className="sr-only">Asistente</SheetTitle>

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
          headerSubtitle="Datos de esta sucursal — los cambios se confirman"
          // Todo lo que resuelve con credencial de PANEL, apagado. Las cards de
          // confirmación no entran en esa bolsa (ver docblock).
          showSettings={false}
          showCharts={false}
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
              description="Precios, stock, saldo de un cliente o las ventas de esta sucursal. También cambios simples, con tu confirmación."
            />
          }
        />
      </SheetContent>
    </Sheet>
  )
}
