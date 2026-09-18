"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { CheckCircle2, Loader2, XCircle } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { InvalidLink } from "./invalid-link"
import { getPairingSecret } from "@/lib/auth/pairing-secret"
import {
  INVITATIONS_ENDPOINT,
  openInvitation,
  persistRedeemedDevice,
} from "@/lib/devices/redeem-invitation"
import { DEVICE_KIND_ROUTES, type DeviceKind } from "@/lib/devices/connected-device"

const POLL_INTERVAL_MS = 3000
const MAX_POLL_MS      = 30 * 60 * 1000 // 30 minutos
const ENDPOINT         = INVITATIONS_ENDPOINT

type InvitationStatus = "pending" | "opened" | "approved" | "denied" | "expired" | "consumed"

/**
 * module → ruta de la pantalla pareada.
 *
 * Sale del MISMO mapa que usa el panel para ofrecer "abrir" un dispositivo
 * conectado (`DEVICE_KIND_ROUTES`). Acá había una copia del switch, y una copia
 * es un lugar más donde olvidarse al sumar un tipo: el reloj de marcación
 * (context/83 §9.2) habría quedado redirigido a `/pos`, o sea a la caja.
 *
 * El default se mantiene: un `module` que este bundle no conoce —un panel más
 * nuevo que el JS de esta tablet— aterriza en la caja, que es lo que era antes
 * de que existieran los otros tipos.
 */
function moduleRoute(module: string): string {
  return DEVICE_KIND_ROUTES[module as DeviceKind] ?? "/pos"
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
      // Token + claims (namespaced por module) + descarte del secreto de
      // pairing: `persistRedeemedDevice`, el mismo que usa el pareo automático
      // de /pos (context/72 §9.2).
      persistRedeemedDevice(invitationId, data)
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
      try {
        // Apertura compartida con el pareo automático de /pos
        // (`lib/devices/redeem-invitation.ts`): guarda el secreto de pairing
        // de la primera apertura y lo presenta en las recargas.
        const result = await openInvitation(invitationId)

        if (result.kind === "error") {
          // "in-use" (409) usa el copy dedicado ("Link ya usado" + qué hacer)
          // en vez del mensaje crudo de la API: es el caso más probable.
          setFatalError(result.reason)
          return
        }

        // Reconnect (auto_approve): el token llega directo en open(), sin
        // userCode ni polling.
        if (result.kind === "token") {
          finish(result.device)
          return
        }

        setUserCode(result.userCode)
        setModule(result.module)
        setStatus("opened")
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
