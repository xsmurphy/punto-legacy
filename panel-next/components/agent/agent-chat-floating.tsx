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
      <Sheet open={open} onOpenChange={setOpen}>
        {/* Mobile: fullscreen sin max-width (drawer angosto + teclado virtual
            no se lee bien). Desktop ≥sm: max-w-sm como side panel clásico. */}
        <SheetContent side="right" className="flex w-full flex-col p-0 sm:max-w-sm">
          <SheetTitle className="sr-only">Asistente</SheetTitle>
          <AgentChatContent companyName={companyName} outletName={outletName} showHeader />
        </SheetContent>
      </Sheet>
    </>
  )
}
