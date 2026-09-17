"use client"

import * as React from "react"
import { MessageCircle } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Sheet, SheetContent, SheetTitle } from "@/components/ui/sheet"
import { AgentChatPanel } from "./agent-chat-panel"
import { useAgentChatStore } from "@/lib/agent/store"
import { useIsMobile } from "@/hooks/use-mobile"

interface Props {
  companyName: string
  /** UUID de la sucursal seleccionada (view-scope), "all", o "" si no hay override. */
  viewOutletId: string
  /** Nombre de la sucursal seleccionada para el contexto del prompt. */
  viewOutletName: string
  showFab?: boolean
}

export function AgentChatFloating({ companyName, viewOutletId, viewOutletName, showFab = true }: Props) {
  const open = useAgentChatStore((s) => s.open)
  const setOpen = useAgentChatStore((s) => s.setOpen)
  const isMobile = useIsMobile()

  return (
    <>
      {showFab && (
        <Button
          onClick={() => setOpen(true)}
          className="fixed bottom-6 right-6 z-50 size-14 rounded-full bg-foreground text-background shadow-lg hover:bg-foreground/90"
          aria-label="Abrir asistente"
        >
          <MessageCircle className="size-6" />
        </Button>
      )}
      <Sheet open={open} onOpenChange={setOpen} modal={false}>
        {/* Mobile: 95vw — más ancho posible que aún muestre un margen visible
            del panel (señal de overlay + zona de cierre al tap-afuera).
            Force con `!` porque SheetContent default tiene
            `data-[side=right]:w-3/4` con más specificity que un className
            custom — sin important el override se pierde.
            Desktop ≥sm: max-w-md side panel. */}
        {/* `showCloseButton={false}`: la X del primitive es absoluta en la
            esquina y caía ENCIMA de las acciones del header del chat —ajustes y
            limpiar— (reporte del owner, 2026-08-31). El chat rinde la suya
            adentro del header, en fila con las demás, y recibe `onClose` para
            eso. Es lo que el POS ya hacía desde que se armó su diálogo; acá
            faltaba. */}
        {/* En DESKTOP el clic afuera NO cierra el chat (reporte del owner,
            2026-09-17). El caso que lo motiva es el que vuelve útil al
            asistente: le preguntás cómo editar un artículo, te da los pasos, y
            al tocar el artículo para seguirlos el chat desaparecía —
            justamente cuando más lo necesitás a la vista.

            `modal={false}` y `overlay={false}` ya permitían operar el panel
            con el chat abierto, pero no alcanzaban: Radix descarta la capa al
            primer pointer-down afuera aunque no sea modal. Hay que prevenir
            el evento; no hay prop que lo desactive.

            En MOBILE se conserva: ahí el chat ocupa 95vw y el margen visible
            es la forma natural de cerrarlo (ver la nota de ancho de abajo).
            Seguir una guía mientras se navega es un flujo de pantalla grande;
            en el teléfono el chat tapa el contenido igual. La X del header
            cierra en las dos. */}
        <SheetContent
          side="right"
          overlay={false}
          showCloseButton={false}
          onInteractOutside={isMobile ? undefined : (e) => e.preventDefault()}
          className="flex !w-[95vw] flex-col p-0 sm:!w-full sm:max-w-md"
        >
          <SheetTitle className="sr-only">Asistente</SheetTitle>
          {/* El dueño de datos del panel. La presentación
              (`AgentChatContent`) es la misma que usa la caja. */}
          <AgentChatPanel
            companyName={companyName}
            viewOutletId={viewOutletId}
            viewOutletName={viewOutletName}
            showHeader
            onClose={() => setOpen(false)}
          />
        </SheetContent>
      </Sheet>
    </>
  )
}
