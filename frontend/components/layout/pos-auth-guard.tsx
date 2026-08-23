"use client"

import * as React from "react"
import { PosUnauthorizedSentinel } from "@/components/pos/pos-unauthorized-sentinel"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { WifiOff } from "lucide-react"
import { ApiError } from "@/lib/api-client"
import { getDeviceToken } from "@/lib/auth/device-token"
import { usePosBootstrap } from "@/hooks/use-pos-bootstrap"
import { RealtimeProvider } from "@/components/realtime-provider"
import { useRealtimeSync } from "@/hooks/use-realtime-sync"

const REVOKED_FLAG_KEY = "punto.device.revoked.pos"

/** Motivo del rechazo cuando hay/había token pero el server lo tumbó —
 * distinto de "unpaired" (nunca hubo token). Ver DeviceNotConnectedReason. */
type RejectReason = "revoked" | "incomplete" | null

/** Lee y consume (una sola vez) el flag que `posFetch` deja cuando detecta
 * `code: "session_revoked"` o `"device_incomplete"` en una respuesta 401 —
 * así el guard sabe POR QUÉ el token ausente/rechazado (revocación explícita,
 * dimensión faltante, o "nunca se parea") y muestra el copy correcto. */
function consumeRejectReason(): RejectReason {
  if (typeof window === "undefined") return null
  const code = window.sessionStorage.getItem(REVOKED_FLAG_KEY)
  if (code === null) return null
  window.sessionStorage.removeItem(REVOKED_FLAG_KEY)
  return code === "device_incomplete" ? "incomplete" : "revoked"
}

/**
 * Guard de auth exclusivo del POS. Verifica el Bearer token del device en
 * localStorage (`punto.device.token`, realm pos-app, 10 años).
 *
 * Árbol de decisión — RED / CACHE / NADA
 * ──────────────────────────────────────
 *   1. Sin token en localStorage        → DeviceNotConnected (sin round-trip)
 *   2. 401 del server                   → DeviceNotConnected (revoked/incomplete/unpaired)
 *   3. Bootstrap de RED                 → POS normal
 *   4. Sin red, con snapshot en IndexedDB → POS igual, en modo degradado
 *   5. Sin red y SIN snapshot           → pantalla bloqueante de reintento
 *
 * Los casos 3 y 4 son indistinguibles para este componente a propósito: la
 * degradación la resuelve `usePosBootstrap()`, que sirve el snapshot cuando la
 * red falla. Acá llega un `PosBootstrap` y la caja abre. El único bloqueo real
 * es el 5: un device que JAMÁS sincronizó no tiene catálogo, ni cajas, ni
 * correlativo — no hay nada que dejar operar.
 *
 * Esto es un cambio de comportamiento deliberado (2026-08-23). Antes,
 * CUALQUIER fallo no-401 pintaba un `fixed inset-0` sobre la caja entera; el
 * comentario decía "para no bloquear la caja" y hacía exactamente lo
 * contrario. Un corte de internet dejaba inoperable una caja que tenía todo
 * lo necesario para vender, contra la regla base del producto: lo que se
 * EMITE —factura, recibo, remisión, comanda— funciona siempre sin internet.
 *
 * El pairing se hace vía /connect/[id] generado por el admin en /settings/devices.
 */
export function PosAuthGuard({ children }: { children: React.ReactNode }) {
  // Check sincrónico: si no hay token en localStorage → DeviceNotConnected sin round-trip.
  // null = aún hidratando (SSR), continuar optimistamente al useQuery.
  const [hasLocalToken, setHasLocalToken] = React.useState<boolean | null>(null)
  const [rejectReason, setRejectReason] = React.useState<RejectReason>(null)
  React.useEffect(() => {
    const consumed = consumeRejectReason()
    if (consumed) setRejectReason(consumed)
    setHasLocalToken(getDeviceToken() !== null)
  }, [])

  // Una sola query de bootstrap para todo el POS (`["pos-bootstrap"]`). Antes
  // este guard tenía la suya (`["pos-bootstrap-auth"]`) con un `fetch` crudo,
  // en paralelo a la de `useCatalogSeed`: dos requests al endpoint más caro
  // del POS (5 llamadas upstream) en cada arranque, y —lo que importa acá—
  // dos caminos distintos hacia el mismo dato, de los cuales solo uno podía
  // aprender a degradar. Ahora la degradación offline vive en un único lugar.
  const { data, status, error, refetch } = usePosBootstrap()

  // El motivo del rechazo lo publica `posFetch` en sessionStorage cuando ve el
  // `code` del 401, y eso pasa DESPUÉS del montaje. Consumirlo solo en el
  // efecto de arranque dejaba el copy en "unpaired" cuando el device era en
  // realidad `revoked`/`incomplete`, que es el caso más común (el admin
  // desconecta el device con la caja abierta).
  React.useEffect(() => {
    if (status !== "error") return
    const consumed = consumeRejectReason()
    if (consumed) setRejectReason(consumed)
  }, [status, error])

  // Sin token en localStorage → DeviceNotConnected inmediato, sin round-trip.
  if (hasLocalToken === false) return <DeviceNotConnected reason={rejectReason ?? "unpaired"} />

  // Loading: render children optimistically — el POS tiene su propio LoadingScreen.
  if (status === "pending") return <>{children}</>

  if (status === "error") {
    if (error instanceof ApiError && error.status === 401) {
      return <DeviceNotConnected reason={rejectReason ?? "unpaired"} />
    }
    // Único bloqueo legítimo: no hay red Y no hay snapshot (device nuevo que
    // nunca completó un bootstrap). Con snapshot, `usePosBootstrap` ya lo
    // habría servido y esta rama no se alcanza.
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-background p-6">
        <Card className="w-full max-w-md">
          <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
            <div className="flex size-12 items-center justify-center rounded-full bg-destructive/10">
              <WifiOff className="size-6 text-destructive" />
            </div>
            <div className="space-y-1">
              <p className="text-base font-semibold">Sin conexión con el servidor</p>
              <p className="text-sm text-muted-foreground">
                Este dispositivo todavía no descargó los datos del comercio, así que
                no puede operar sin conexión. Conectate a internet y reintentá.
              </p>
            </div>
            <Button onClick={() => refetch()} size="lg" className="w-full">
              Reintentar
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  // Realtime (context/15): el POS TIENE que escuchar los cambios del tenant —
  // es la regla base del owner ("si anulo una factura todos los dispositivos
  // se actualizan a la vez"). Se monta acá y no vía `RealtimeWire`, que resuelve
  // el companyId con `useBootstrap()` (endpoint del panel, cookie): el POS es
  // realm device y va con Bearer, un cliente HTTP por realm. El companyId sale
  // del bootstrap del POS que este guard ya trajo, sin request extra.
  //
  // Hasta 2026-08-09 el listener solo se montaba en PanelAuthGuard, que cubre
  // (panel) y (admin) — el POS vive en (pos) con este guard, así que la caja
  // NUNCA escuchaba: el backend publicaba y del otro lado no había nadie.
  //
  // Offline: el companyId puede venir del snapshot. `RealtimeProvider` intenta
  // conectar y falla, que es lo correcto — reintenta solo y se engancha apenas
  // vuelve la red, sin que la caja se entere.
  return (
    <>
      <PosUnauthorizedSentinel />
      <RealtimeProvider
        companyId={data?.config?.companyId != null ? String(data.config.companyId) : null}
      >
        <PosRealtimeSync>{children}</PosRealtimeSync>
      </RealtimeProvider>
    </>
  )
}

/** Suscribe las invalidaciones con scope "pos" (ver use-realtime-sync). */
function PosRealtimeSync({ children }: { children: React.ReactNode }) {
  useRealtimeSync("pos")
  return <>{children}</>
}
