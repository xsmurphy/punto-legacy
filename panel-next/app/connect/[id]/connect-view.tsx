"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { CheckCircle2, Loader2, XCircle } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { setDeviceToken } from "@/lib/auth/device-token"

const POLL_INTERVAL_MS = 3000
const MAX_POLL_MS      = 30 * 60 * 1000 // 30 minutos

type InvitationStatus = "pending" | "opened" | "approved" | "denied" | "expired"

interface ConnectViewProps {
  invitationId: string
  userCode:     string
  module:       string
}

export function ConnectView({ invitationId, userCode, module }: ConnectViewProps) {
  const router = useRouter()
  const [status, setStatus] = React.useState<InvitationStatus>("opened")
  const startedAt = React.useRef(Date.now())

  React.useEffect(() => {
    const intervalId = setInterval(async () => {
      if (Date.now() - startedAt.current > MAX_POLL_MS) {
        clearInterval(intervalId)
        setStatus("expired")
        return
      }

      try {
        const res = await fetch(
          `/api/v1/device_invitations.php?resource=status&id=${encodeURIComponent(invitationId)}`,
          { method: "GET", credentials: "include", cache: "no-store" },
        )
        if (!res.ok) return

        const body = (await res.json()) as {
          ok?: boolean
          data?: { status: InvitationStatus; token?: string }
        }
        const data = (body?.data ?? body) as { status: InvitationStatus; token?: string }
        const newStatus = data?.status

        if (!newStatus) return

        setStatus(newStatus)

        if (
          newStatus === "approved" ||
          newStatus === "denied"   ||
          newStatus === "expired"
        ) {
          clearInterval(intervalId)
        }

        if (newStatus === "approved") {
          // Persistir el Bearer token en localStorage namespaced por module
          // (`punto.device.token.pos` / `punto.device.token.screen`). Sin el
          // namespace, parear ambos tipos en el mismo browser pisaba el
          // token del primero y rompía publish/auth — incidente 2026-06-28.
          const tokenFromBody = data?.token
          if (tokenFromBody) {
            const mod = module === "screen" ? "screen" : "pos"
            setDeviceToken(tokenFromBody, mod)
          }
          setTimeout(() => {
            if (module === "pos")         router.replace("/pos")
            else if (module === "screen") router.replace("/checkout")
            else if (module === "kds")    router.replace("/kds")
            else                          router.replace("/pos")
          }, 800)
        }
      } catch {
        // best-effort -- red momentaneamente no disponible
      }
    }, POLL_INTERVAL_MS)

    return () => clearInterval(intervalId)
  }, [invitationId, module, router])

  return (
    <div className="min-h-svh flex flex-col items-center justify-center p-6 bg-background">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-6 p-8">
          {status === "approved" ? (
            <>
              <CheckCircle2 className="size-12 text-green-600" />
              <p className="text-base font-medium">¡Conectado!</p>
            </>
          ) : status === "denied" ? (
            <>
              <XCircle className="size-12 text-muted-foreground" />
              <div className="space-y-1 text-center">
                <h2 className="text-xl font-semibold">Solicitud rechazada</h2>
                <p className="text-sm text-muted-foreground">
                  El administrador rechazó la conexión de este dispositivo.
                </p>
              </div>
            </>
          ) : status === "expired" ? (
            <>
              <XCircle className="size-12 text-muted-foreground" />
              <div className="space-y-1 text-center">
                <h2 className="text-xl font-semibold">Invitación expirada</h2>
                <p className="text-sm text-muted-foreground">
                  Pedile al administrador que genere una nueva.
                </p>
              </div>
            </>
          ) : (
            <>
              <PuntoLogo variant="mark" className="size-12" />
              <div className="text-center space-y-1">
                <h1 className="text-2xl font-semibold">Conectar dispositivo</h1>
                <p className="text-sm text-muted-foreground">
                  Mostrale este código al administrador para aprobar
                </p>
              </div>

              <div className="bg-muted px-6 py-4 rounded-lg text-center w-full">
                <span className="font-mono text-4xl font-bold tracking-widest tabular-nums">
                  {userCode}
                </span>
              </div>

              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" />
                <span>Esperando aprobación...</span>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
