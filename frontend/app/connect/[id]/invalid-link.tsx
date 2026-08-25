"use client"

import { XCircle } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"

/**
 * Pantalla terminal del flujo de conexión: este link no va a servir.
 *
 * `reason` acepta dos cosas: un código corto de los que arma `page.tsx`
 * ("not-found", "expired", ...) o el mensaje textual que devolvió la API.
 * Antes el prop se recibía y se DESCARTABA — siempre se pintaba "Link inválido
 * o expirado" —, así que el motivo real ("ya está en uso en otro dispositivo",
 * "el dispositivo fue desconectado por un administrador") nunca llegaba a la
 * pantalla y el operador no tenía forma de saber qué hacer distinto.
 */
const CODE_COPY: Record<string, { title: string; detail: string }> = {
  "invalid-format": {
    title: "Link inválido",
    detail: "La dirección está incompleta o mal copiada. Pedí que te reenvíen el link entero.",
  },
  "not-found": {
    title: "Link inválido",
    detail: "Esta invitación no existe o fue cancelada. Pedí una nueva desde Configuración › Dispositivos.",
  },
  expired: {
    title: "Link vencido",
    detail: "Las invitaciones caducan por seguridad. Pedí una nueva desde Configuración › Dispositivos.",
  },
  "in-use": {
    title: "Link ya usado",
    detail:
      "Este link se abrió en otro dispositivo. Cada link conecta un solo dispositivo: pedí uno nuevo desde Configuración › Dispositivos.",
  },
  "config-error": {
    title: "No se pudo contactar al servidor",
    detail: "Revisá la conexión e intentá de nuevo. Si sigue, avisale al administrador.",
  },
}

export function InvalidLink({ reason }: { reason: string }) {
  const known = CODE_COPY[reason]
  const title = known?.title ?? "No se pudo conectar el dispositivo"
  const detail =
    known?.detail ??
    (reason && reason !== "unknown" && reason !== "error"
      ? reason
      : "Pedile al administrador que genere un link nuevo y volvé a abrirlo.")

  return (
    <div className="min-h-svh flex flex-col items-center justify-center p-6 bg-background">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
          <XCircle className="size-12 text-muted-foreground" />
          <div className="space-y-1">
            <h2 className="text-xl font-semibold">{title}</h2>
            <p className="text-sm text-muted-foreground">{detail}</p>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
