"use client"

import * as React from "react"
import { useQuery } from "@tanstack/react-query"
import { PosUnauthorizedSentinel } from "@/components/pos/pos-unauthorized-sentinel"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { WifiOff } from "lucide-react"
import type { PosBootstrap } from "@/lib/types/pos-bootstrap"
import { getDeviceToken } from "@/lib/auth/device-token"
import { RealtimeProvider } from "@/components/realtime-provider"
import { useRealtimeSync } from "@/hooks/use-realtime-sync"

const REVOKED_FLAG_KEY = "punto.device.revoked.pos"

/** Lee y consume (una sola vez) el flag que `posFetch` deja cuando detecta
 * `code: "session_revoked"` en una respuesta 401 — así el guard sabe si el
 * token ausente es por revocación explícita o por "nunca se parea". */
function consumeRevokedFlag(): boolean {
  if (typeof window === "undefined") return false
  const revoked = window.sessionStorage.getItem(REVOKED_FLAG_KEY) === "1"
  if (revoked) window.sessionStorage.removeItem(REVOKED_FLAG_KEY)
  return revoked
}

/**
 * Guard de auth exclusivo del POS. Verifica el Bearer token del device en
 * localStorage (`punto.device.token`, realm pos-app, 10 años). Si el token
 * está ausente, expirado o revocado → muestra <DeviceNotConnected /> con
 * instrucciones para re-parear desde /settings/devices del panel.
 *
 * Flujos cubiertos:
 *   1. Sin token en localStorage → DeviceNotConnected inmediato (sin round-trip)
 *   2. Token presente pero inválido/revocado → BFF retorna 401 → DeviceNotConnected
 *   3. Token válido → POS funciona
 *   4. Error transitorio (500, red) → UI de retry, NO bloquea
 *
 * El pairing se hace vía /connect/[id] generado por el admin en /settings/devices.
 */
export function PosAuthGuard({ children }: { children: React.ReactNode }) {
  // Check sincrónico: si no hay token en localStorage → DeviceNotConnected sin round-trip.
  // null = aún hidratando (SSR), continuar optimistamente al useQuery.
  const [hasLocalToken, setHasLocalToken] = React.useState<boolean | null>(null)
  const [revoked, setRevoked] = React.useState(false)
  React.useEffect(() => {
    if (consumeRevokedFlag()) setRevoked(true)
    setHasLocalToken(getDeviceToken() !== null)
  }, [])

  const { data, status, error, refetch } = useQuery<PosBootstrap>({
    queryKey: ["pos-bootstrap-auth"],
    queryFn: async () => {
      const headers: Record<string, string> = {}
      const deviceToken = getDeviceToken()
      if (deviceToken) {
        headers["Authorization"] = `Bearer ${deviceToken}`
      }
      const res = await fetch("/api/pos/bootstrap", {
        credentials: "include",
        cache: "no-store",
        headers,
      })
      if (res.status === 401) {
        // El body trae `code: "session_revoked"` cuando el admin desconectó
        // el device explícitamente (ver api/includes/auth_session.php) — lo
        // distinguimos de "nunca se parea" para mostrar el copy correcto.
        const payload = await res
          .clone()
          .json()
          .catch(() => null) as { code?: string } | null
        if (payload?.code === "session_revoked") setRevoked(true)
        throw Object.assign(new Error("DEVICE_UNAUTHORIZED"), { status: 401 })
      }
      if (!res.ok) {
        throw Object.assign(new Error(`BFF error ${res.status}`), { status: res.status })
      }
      return res.json()
    },
    retry: 1,
    refetchOnWindowFocus: true,
    // Solo pollear mientras haya token — tras moduleLogout() el token se borra
    // y refetchInterval vuelve false para no generar 401s en loop.
    refetchInterval: () => (getDeviceToken() !== null ? 60_000 : false),
    staleTime: 4 * 60 * 1000, // 4 min — el BFF es pesado (5 upstream calls)
  })

  // Sin token en localStorage → DeviceNotConnected inmediato, sin round-trip.
  if (hasLocalToken === false) return <DeviceNotConnected reason={revoked ? "revoked" : "unpaired"} />

  // Loading: render children optimistically — el POS tiene su propio LoadingScreen.
  if (status === "pending") return <>{children}</>

  if (status === "error") {
    const err = error as { status?: number }
    if (err?.status === 401) {
      return <DeviceNotConnected reason={revoked ? "revoked" : "unpaired"} />
    }
    // Error transitorio (500, red) → UI de retry para no bloquear la caja.
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
                Verificá tu conexión a internet y reintentá.
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
