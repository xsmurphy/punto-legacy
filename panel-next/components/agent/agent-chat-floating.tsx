"use client"

import * as React from "react"
import { MessageCircle } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Sheet, SheetContent, SheetTitle } from "@/components/ui/sheet"
import { AgentChatContent } from "./agent-chat-content"
import { useAgentChatStore } from "@/lib/agent/store"

interface Props {
  companyName: string
  outletName: string
  showFab?: boolean
}

export function AgentChatFloating({ companyName, outletName, showFab = true }: Props) {
  const open = useAgentChatStore((s) => s.open)
  const setOpen = useAgentChatStore((s) => s.setOpen)

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
        <SheetContent side="right" overlay={false} className="flex !w-[95vw] flex-col p-0 sm:!w-full sm:max-w-md">
          <SheetTitle className="sr-only">Asistente</SheetTitle>
          <AgentChatContent companyName={companyName} outletName={outletName} showHeader />
        </SheetContent>
      </Sheet>
    </>
  )
}
