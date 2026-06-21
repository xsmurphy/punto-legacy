"use client"

import * as React from "react"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { AgentChatContent } from "@/components/agent/agent-chat-content"
import { Button } from "@/components/ui/button"

const SUGGESTIONS = [
  "¿Cuánto vendí este mes?",
  "Buscame el cliente Juan",
  "Creá el producto Café Espresso a 12.000 Gs, categoría Bebidas",
  "¿Cuánto stock queda del producto X?",
  "Mostrame las sucursales",
  "Listame los usuarios del equipo",
  "Resumen del año pasado",
  "Creá una categoría llamada Promociones",
]

export default function ChatPage() {
  const { data: bootstrap } = useBootstrap()
  const [initialInput, setInitialInput] = React.useState("")

  if (!bootstrap) return null

  return (
    <div className="mx-auto flex h-[calc(100vh-4rem)] w-full max-w-3xl flex-col p-4">
      <div className="mb-4">
        <h1 className="text-2xl font-semibold">Asistente</h1>
        <p className="text-sm text-muted-foreground">Consultá datos, creá registros básicos.</p>
      </div>
      <AgentChatContent
        companyName={bootstrap.companyName}
        outletName={bootstrap.activeOutletName}
        initialInput={initialInput}
        showHeader={false}
        renderEmpty={
          <div className="grid grid-cols-1 gap-3 pt-8 sm:grid-cols-2">
            {SUGGESTIONS.map((s) => (
              <Button
                key={s}
                variant="outline"
                className="h-auto justify-start whitespace-normal text-left text-sm"
                onClick={() => setInitialInput(s)}
              >
                {s}
              </Button>
            ))}
          </div>
        }
      />
    </div>
  )
}
