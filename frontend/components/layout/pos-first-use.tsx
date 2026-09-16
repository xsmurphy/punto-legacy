"use client"

/**
 * Primer uso de `/pos` en un navegador sin token de device (context/72 §9.2).
 *
 * Lo monta `PosAuthGuard` en lugar de `<DeviceNotConnected>` cuando no hay
 * token de device ni un motivo de rechazo (revocado / pareo incompleto). Si
 * este mismo navegador tiene sesión de panel con permiso para parear, conecta
 * la caja sin código; si no, termina en la pantalla de link de siempre.
 *
 * La lógica y la separación de credenciales están en `lib/devices/auto-pair.ts`
 * (leer su docblock antes de tocar las dependencias de abajo): el panel solo
 * crea la invitación, el token de device sale del canje público, y la caja se
 * toma con el Bearer del device.
 *
 * Tras el pareo se recarga la página: el guard, el bootstrap, el catálogo y la
 * tenencia arrancan con el token nuevo por el camino normal, sin estado a
 * medio construir de este componente.
 */

import * as React from "react"
import { Loader2, Store } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { api } from "@/lib/api-client"
import { getPanelToken } from "@/lib/auth/panel-token"
import {
  planAutoPair,
  pairRegister,
  type AutoPairDeps,
  type AutoPairRegister,
} from "@/lib/devices/auto-pair"
import { openInvitation, persistRedeemedDevice } from "@/lib/devices/redeem-invitation"
import { refreshTenancy } from "@/lib/pos/register-tenancy"

const deps: AutoPairDeps = {
  hasPanelSession: () => getPanelToken() !== null,
  listRegisters: async () => {
    const res = await api.get<{ registers: AutoPairRegister[] }>(
      "/v1/device_invitations?resource=autopair-registers",
    )
    return res?.registers ?? []
  },
  createInvitation: (registerId) =>
    api.post<{ id: string }>("/v1/device_invitations", { action: "autopair", registerId }),
  openInvitation: (id) => openInvitation(id),
  persistDevice: persistRedeemedDevice,
  // Tercer caller legítimo de `acquire: true` (ver `RefreshTenancyOptions`):
  // la persona pidió explícitamente entrar a esta caja.
  acquireRegister: (registerId) => refreshTenancy(registerId, { acquire: true }),
}

type Phase =
  | { kind: "checking" }
  | { kind: "link" }
  | { kind: "choose"; registers: AutoPairRegister[] }
  | { kind: "pairing" }
  | { kind: "error"; message: string }

export function PosFirstUse() {
  const [phase, setPhase] = React.useState<Phase>({ kind: "checking" })
  // StrictMode monta los efectos dos veces en dev: sin esto, el caso de una
  // sola caja crearía dos invitaciones.
  const started = React.useRef(false)

  const pair = React.useCallback(async (registerId: string) => {
    setPhase({ kind: "pairing" })
    const outcome = await pairRegister(registerId, deps)
    if (outcome.ok) {
      window.location.reload()
      return
    }
    setPhase({ kind: "error", message: outcome.message })
  }, [])

  const plan = React.useCallback(async () => {
    setPhase({ kind: "checking" })
    const result = await planAutoPair(deps)
    if (result.kind === "auto") {
      await pair(result.register.registerId)
      return
    }
    setPhase(result.kind === "choose" ? { kind: "choose", registers: result.registers } : { kind: "link" })
  }, [pair])

  React.useEffect(() => {
    if (started.current) return
    started.current = true
    void plan()
  }, [plan])

  if (phase.kind === "link") return <DeviceNotConnected reason="unpaired" />

  if (phase.kind === "checking" || phase.kind === "pairing") {
    return (
      <div
        role="status"
        aria-label={phase.kind === "pairing" ? "Conectando la caja" : "Cargando"}
        className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-background p-4"
      >
        <PuntoLogo variant="mark" className="size-[35px] animate-pulse" />
        {phase.kind === "pairing" ? (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" />
            <span>Conectando la caja...</span>
          </div>
        ) : null}
      </div>
    )
  }

  return (
    <div className="fixed inset-0 z-50 flex flex-col items-center justify-center overflow-y-auto bg-background p-4">
      <Card className="w-full max-w-md">
        <CardContent className="flex flex-col items-center gap-6 p-6 text-center sm:p-8">
          <PuntoLogo variant="mark" className="size-12" />

          {phase.kind === "choose" ? (
            <>
              <div className="space-y-2">
                <h1 className="text-2xl font-semibold">Elegí la caja</h1>
              </div>
              <div className="flex w-full flex-col gap-2">
                {phase.registers.map((r) => (
                  <Button
                    key={r.registerId}
                    variant="outline"
                    size="lg"
                    // Alto para dos líneas (caja + sucursal) y dedo en tablet.
                    className="h-auto w-full justify-start gap-3 py-3 text-left"
                    onClick={() => void pair(r.registerId)}
                  >
                    <Store className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex min-w-0 flex-col">
                      <span className="truncate font-medium">{r.registerName}</span>
                      <span className="truncate text-sm text-muted-foreground">{r.outletName}</span>
                    </span>
                  </Button>
                ))}
              </div>
            </>
          ) : (
            <>
              <div className="space-y-2">
                <h1 className="text-2xl font-semibold">No se pudo conectar la caja</h1>
                <p className="text-sm text-muted-foreground">{phase.message}</p>
              </div>
              <div className="flex w-full flex-col gap-2">
                <Button size="lg" className="w-full" onClick={() => void plan()}>
                  Reintentar
                </Button>
                <Button size="lg" variant="outline" className="w-full" onClick={() => setPhase({ kind: "link" })}>
                  Usar un link de conexión
                </Button>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
