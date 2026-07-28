"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { CheckCircle2, Loader2, XCircle } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { setDeviceToken, type DeviceModule } from "@/lib/auth/device-token"
import { InvalidLink } from "./invalid-link"
import { setDeviceClaims } from "@/lib/auth/device-claims"

const POLL_INTERVAL_MS = 3000
const MAX_POLL_MS      = 30 * 60 * 1000 // 30 minutos

type InvitationStatus = "pending" | "opened" | "approved" | "denied" | "expired"

/** module (string libre del invitation) → namespace tipado de device-token/claims. */
function toDeviceModule(module: string): DeviceModule {
  if (module === "screen" || module === "kds" || module === "display" || module === "print") return module
  return "pos"
}

/** module → ruta de la pantalla pareada. */
function moduleRoute(module: string): string {
  switch (module) {
    case "screen":  return "/checkout"
    case "kds":     return "/kds"
    case "display": return "/display"
    case "print":   return "/print"
    default:        return "/pos"
  }
}

interface ConnectViewProps {
  invitationId:          string
  userCode:              string
  module:                string
  autoApproveToken?:     string | null
  autoApproveCompanyId?: string | null
  autoApproveRegisterId?: string | null
  autoApproveDeviceId?:  string | null
}

export function ConnectView({ invitationId, userCode, module, autoApproveToken, autoApproveCompanyId, autoApproveRegisterId, autoApproveDeviceId }: ConnectViewProps) {
  const router = useRouter()
  const [status, setStatus] = React.useState<InvitationStatus>("opened")
  // Motivo definitivo por el que este link no sirve (invitación inexistente,
  // vencida, o dispositivo revocado). Corta el polling y se muestra en vez de
  // dejar al operador esperando una aprobación que nunca va a llegar.
  const [fatalError, setFatalError] = React.useState<string | null>(null)
  const startedAt = React.useRef(Date.now())

  // Reconnect: el token llega directamente desde open() — persistir y redirigir
  // de inmediato sin mostrar el userCode ni iniciar polling.
  React.useEffect(() => {
    if (!autoApproveToken) return
    const mod = toDeviceModule(module)
    setDeviceToken(autoApproveToken, mod)
    if (autoApproveCompanyId && autoApproveRegisterId && autoApproveDeviceId) {
      setDeviceClaims({ companyId: autoApproveCompanyId, registerId: autoApproveRegisterId, deviceId: autoApproveDeviceId }, mod)
    }
    setStatus("approved")
    setTimeout(() => router.replace(moduleRoute(module)), 800)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [autoApproveToken])

  React.useEffect(() => {
    // Si ya fue auto-aprobado, no iniciar polling.
    if (autoApproveToken) return
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
        // 404/410/409 son definitivos: la invitación no existe, venció, o su
        // dispositivo fue REVOCADO (`issueTokenForExistingDevice` exige
        // status=1 y tira 404 "Device no encontrado o revocado"). Antes esto
        // caía en un `return` mudo y el poll seguía girando: el operador veía
        // la pantalla de "esperando aprobación" para siempre, o terminaba en
        // el POS sin token con un "Dispositivo no conectado" que culpaba al
        // admin. Ahora se corta y se muestra el motivo real.
        if (res.status === 404 || res.status === 410 || res.status === 409) {
          clearInterval(intervalId)
          const errBody = (await res.json().catch(() => null)) as
            | { error?: { message?: string } }
            | null
          setFatalError(
            errBody?.error?.message ??
              "El link ya no es válido. Pedí uno nuevo desde Ajustes → Dispositivos.",
          )
          return
        }
        if (!res.ok) return

        const body = (await res.json()) as {
          ok?: boolean
          data?: { status: InvitationStatus; token?: string; companyId?: string; registerId?: string; deviceId?: string }
        }
        const data = (body?.data ?? body) as { status: InvitationStatus; token?: string; companyId?: string; registerId?: string; deviceId?: string }
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
            const mod = toDeviceModule(module)
            setDeviceToken(tokenFromBody, mod)
            if (data.companyId && data.registerId && data.deviceId) {
              setDeviceClaims({ companyId: data.companyId, registerId: data.registerId, deviceId: data.deviceId }, mod)
            }
          }
          setTimeout(() => router.replace(moduleRoute(module)), 800)
        }
      } catch {
        // best-effort -- red momentaneamente no disponible
      }
    }, POLL_INTERVAL_MS)

    return () => clearInterval(intervalId)
  }, [invitationId, module, router, autoApproveToken])

  if (fatalError) return <InvalidLink reason={fatalError} />

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
