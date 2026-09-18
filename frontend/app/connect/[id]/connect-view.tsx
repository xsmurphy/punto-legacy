"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { CheckCircle2, Loader2, XCircle } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { InvalidLink } from "./invalid-link"
import { useInvitationRedeem } from "@/hooks/use-invitation-redeem"
import { DEVICE_KIND_ROUTES, type DeviceKind } from "@/lib/devices/connected-device"

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

/**
 * Pantalla de conexión de un dispositivo (Device Authorization Grant).
 *
 * El canje —apertura, secreto de pairing, espera de la aprobación, persistencia
 * de la credencial— NO vive acá: vive en `hooks/use-invitation-redeem.ts`,
 * compartido con el formulario de vinculación de la pantalla "no conectado"
 * (`components/layout/device-not-connected.tsx`). Esta pantalla aporta lo suyo,
 * que es mostrar el código y, al terminar, mandar a la ruta del módulo.
 *
 * `expect` va vacío a propósito: este link puede ser de cualquier tipo de
 * dispositivo y justamente lo que hace esta ruta es rutear según el que sea.
 */
export function ConnectView({ invitationId }: { invitationId: string }) {
  const router = useRouter()

  const { phase, start } = useInvitationRedeem({
    onRedeemed: (device) => {
      // Un respiro para que se vea el "Conectado" antes de irse.
      setTimeout(() => router.replace(moduleRoute(device.module)), 800)
    },
  })

  React.useEffect(() => {
    start(invitationId)
  }, [invitationId, start])

  if (phase.kind === "error") return <InvalidLink reason={phase.reason} />

  return (
    <div className="min-h-svh flex flex-col items-center justify-center p-6 bg-background">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-6 p-8">
          {phase.kind === "done" ? (
            <>
              <CheckCircle2 className="size-12 text-green-600" />
              <p className="text-base font-medium">Conectado</p>
            </>
          ) : phase.kind === "closed" && phase.status === "denied" ? (
            <>
              <XCircle className="size-12 text-muted-foreground" />
              <div className="space-y-1 text-center">
                <h2 className="text-xl font-semibold">Solicitud rechazada</h2>
                <p className="text-sm text-muted-foreground">
                  El administrador rechazó la conexión de este dispositivo.
                </p>
              </div>
            </>
          ) : phase.kind === "closed" && phase.status === "consumed" ? (
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
          ) : phase.kind === "closed" ? (
            <>
              <XCircle className="size-12 text-muted-foreground" />
              <div className="space-y-1 text-center">
                <h2 className="text-xl font-semibold">Invitación expirada</h2>
                <p className="text-sm text-muted-foreground">
                  Pedile al administrador que genere una nueva.
                </p>
              </div>
            </>
          ) : phase.kind === "waiting" ? (
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
                  {phase.userCode}
                </span>
              </div>

              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" />
                <span>Esperando aprobación...</span>
              </div>
            </>
          ) : (
            <>
              <PuntoLogo variant="mark" className="size-12" />
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                <span>Abriendo la invitación...</span>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
