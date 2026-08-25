"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { CheckCircle2, Loader2, XCircle } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { setDeviceToken, type DeviceModule } from "@/lib/auth/device-token"
import { InvalidLink } from "./invalid-link"
import { setDeviceClaims } from "@/lib/auth/device-claims"
import {
  clearPairingSecret,
  getPairingSecret,
  setPairingSecret,
} from "@/lib/auth/pairing-secret"

const POLL_INTERVAL_MS = 3000
const MAX_POLL_MS      = 30 * 60 * 1000 // 30 minutos
const ENDPOINT         = "/api/v1/device_invitations.php"

type InvitationStatus = "pending" | "opened" | "approved" | "denied" | "expired" | "consumed"

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

interface OpenData {
  status?:        string
  userCode?:      string
  module?:        string
  autoApprove?:   boolean
  token?:         string
  deviceId?:      string
  companyId?:     string
  registerId?:    string
  pairingSecret?: string | null
}

interface StatusData {
  status:     InvitationStatus
  token?:     string
  companyId?: string
  registerId?: string
  deviceId?:  string
  module?:    string
}

function envelopeData<T>(body: unknown): T {
  const b = body as { ok?: boolean; data?: T }
  return (b?.data ?? (body as T)) as T
}

function errorMessage(body: unknown): string | null {
  const b = body as { error?: { message?: string } } | null
  return b?.error?.message ?? null
}

/**
 * Pantalla de conexión de un dispositivo (Device Authorization Grant).
 *
 * ── Canje de un solo uso ────────────────────────────────────────────────────
 *
 * `open()` corre acá, en el cliente, y no en el Server Component: la primera
 * apertura devuelve un secreto de sesión de pairing que hay que guardar en
 * este navegador (ver `lib/auth/pairing-secret.ts`). Ese secreto viaja en cada
 * reload y en cada poll, y es lo único que distingue a este dispositivo de
 * cualquier otro que reciba el link reenviado.
 *
 * El token se entrega UNA sola vez: en el poll que encuentra la invitación
 * aprobada, el backend la mueve al estado terminal `consumed` con un CAS. Por
 * eso el polling se protege contra solapamiento (`inFlight`) y contra
 * re-entrada después del canje (`redeemed`): dos requests del MISMO navegador
 * pisándose harían que el segundo viera `consumed` y pintara un error sobre un
 * pareo que en realidad salió bien.
 */
export function ConnectView({ invitationId }: { invitationId: string }) {
  const router = useRouter()
  const [status, setStatus]     = React.useState<InvitationStatus>("pending")
  const [userCode, setUserCode] = React.useState<string>("")
  const [module, setModule]     = React.useState<string>("pos")
  const [opening, setOpening]   = React.useState(true)
  // Motivo definitivo por el que este link no sirve (invitación inexistente,
  // vencida, ya usada por otro dispositivo, o device revocado). Corta el
  // polling y se muestra en vez de dejar al operador esperando una aprobación
  // que nunca va a llegar.
  const [fatalError, setFatalError] = React.useState<string | null>(null)

  const startedAt = React.useRef(Date.now())
  const openedRef = React.useRef(false)   // StrictMode monta el efecto dos veces en dev
  const redeemed  = React.useRef(false)   // ya canjeamos: ignorar respuestas tardías
  const inFlight  = React.useRef(false)   // no solapar polls

  /** Persiste token + claims y manda a la pantalla del módulo. */
  const finish = React.useCallback(
    (data: { token: string; module: string; companyId?: string; registerId?: string; deviceId?: string }) => {
      redeemed.current = true
      const mod = toDeviceModule(data.module)
      // Persistir el Bearer en localStorage namespaced por module
      // (`punto.device.token.pos` / `...screen`). Sin el namespace, parear
      // ambos tipos en el mismo browser pisaba el token del primero y rompía
      // publish/auth — incidente 2026-06-28.
      setDeviceToken(data.token, mod)
      if (data.companyId && data.registerId && data.deviceId) {
        setDeviceClaims(
          { companyId: data.companyId, registerId: data.registerId, deviceId: data.deviceId },
          mod,
        )
      }
      // El secreto ya cumplió: la invitación está consumida y no vuelve a
      // entregar nada. Dejarlo sería guardar una credencial muerta.
      clearPairingSecret(invitationId)
      setStatus("approved")
      setTimeout(() => router.replace(moduleRoute(data.module)), 800)
    },
    [invitationId, router],
  )

  // ── 1. Apertura ───────────────────────────────────────────────────────────
  React.useEffect(() => {
    if (openedRef.current) return
    openedRef.current = true

    void (async () => {
      const form = new URLSearchParams()
      const stored = getPairingSecret(invitationId)
      if (stored) form.set("pairingSecret", stored)

      try {
        const res = await fetch(
          `${ENDPOINT}?resource=open&id=${encodeURIComponent(invitationId)}`,
          {
            method:  "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body:    form.toString(),
            cache:   "no-store",
          },
        )
        const body = await res.json().catch(() => null)

        if (!res.ok) {
          // 409 = la invitación ya tiene dueño y no somos nosotros. Ese caso
          // usa el copy dedicado ("Link ya usado" + qué hacer) en vez del
          // mensaje crudo de la API: es el más probable y el que más necesita
          // decir claramente que hace falta un link NUEVO.
          setFatalError(res.status === 409 ? "in-use" : errorMessage(body) ?? "not-found")
          return
        }

        const data = envelopeData<OpenData>(body)

        // El secreto sale en claro UNA sola vez, en la primera apertura.
        if (data.pairingSecret) setPairingSecret(invitationId, data.pairingSecret)

        // Reconnect (auto_approve): el token llega directo en open(), sin
        // userCode ni polling.
        if (data.autoApprove && data.token && data.deviceId) {
          finish({
            token:      data.token,
            module:     data.module ?? "pos",
            companyId:  data.companyId,
            registerId: data.registerId,
            deviceId:   data.deviceId,
          })
          return
        }

        if (!data.userCode) {
          setFatalError("not-found")
          return
        }
        setUserCode(data.userCode)
        setModule(data.module ?? "pos")
        setStatus("opened")
      } catch {
        setFatalError("config-error")
      } finally {
        setOpening(false)
      }
    })()
  }, [invitationId, finish])

  // ── 2. Polling hasta la aprobación ────────────────────────────────────────
  React.useEffect(() => {
    if (status !== "opened" || fatalError) return

    const intervalId = setInterval(async () => {
      if (redeemed.current) { clearInterval(intervalId); return }
      if (inFlight.current) return           // el poll anterior sigue abierto
      if (Date.now() - startedAt.current > MAX_POLL_MS) {
        clearInterval(intervalId)
        setStatus("expired")
        return
      }
      inFlight.current = true

      try {
        const secret = getPairingSecret(invitationId)
        const res = await fetch(
          `${ENDPOINT}?resource=status&id=${encodeURIComponent(invitationId)}`,
          {
            method:  "GET",
            // El secreto va en header, NUNCA en la query string: es una
            // credencial y la query queda en logs de proxy y en el Referer.
            headers: secret ? { "X-Pairing-Secret": secret } : undefined,
            cache:   "no-store",
          },
        )

        // 404/410/409 son definitivos: la invitación no existe, venció, ya fue
        // usada por otro dispositivo, o su device fue REVOCADO
        // (`issueTokenForExistingDevice` exige status=1). Antes esto caía en un
        // `return` mudo y el poll seguía girando: el operador veía "esperando
        // aprobación" para siempre.
        if (res.status === 404 || res.status === 410 || res.status === 409) {
          clearInterval(intervalId)
          if (redeemed.current) return       // ya teníamos el token: no asustar
          const body = await res.json().catch(() => null)
          setFatalError(res.status === 409 ? "in-use" : errorMessage(body) ?? "not-found")
          return
        }
        if (!res.ok) return

        const data = envelopeData<StatusData>(await res.json())
        if (!data?.status) return
        if (redeemed.current) { clearInterval(intervalId); return }

        if (data.status === "approved" && data.token) {
          clearInterval(intervalId)
          finish({
            token:      data.token,
            module:     data.module ?? module,
            companyId:  data.companyId,
            registerId: data.registerId,
            deviceId:   data.deviceId,
          })
          return
        }

        if (data.status === "denied" || data.status === "expired" || data.status === "consumed") {
          clearInterval(intervalId)
          setStatus(data.status)
        }
      } catch {
        // best-effort — red momentáneamente no disponible
      } finally {
        inFlight.current = false
      }
    }, POLL_INTERVAL_MS)

    return () => clearInterval(intervalId)
  }, [invitationId, status, fatalError, module, finish])

  if (fatalError) return <InvalidLink reason={fatalError} />

  return (
    <div className="min-h-svh flex flex-col items-center justify-center p-6 bg-background">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-6 p-8">
          {status === "approved" ? (
            <>
              <CheckCircle2 className="size-12 text-green-600" />
              <p className="text-base font-medium">Conectado</p>
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
          ) : status === "consumed" ? (
            <>
              <XCircle className="size-12 text-muted-foreground" />
              <div className="space-y-1 text-center">
                <h2 className="text-xl font-semibold">Link ya usado</h2>
                <p className="text-sm text-muted-foreground">
                  Este link conectó otro dispositivo. Pedí uno nuevo desde
                  Configuración › Dispositivos.
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
          ) : opening ? (
            <>
              <PuntoLogo variant="mark" className="size-12" />
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                <span>Abriendo la invitación...</span>
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
