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
  React.useEffect(() => {
    setHasLocalToken(getDeviceToken() !== null)
  }, [])

  const { status, error, refetch } = useQuery<PosBootstrap>({
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
        throw Object.assign(new Error("DEVICE_UNAUTHORIZED"), { status: 401 })
      }
      if (!res.ok) {
        throw Object.assign(new Error(`BFF error ${res.status}`), { status: res.status })
      }
      return res.json()
    },
    retry: false,
    staleTime: 4 * 60 * 1000, // 4 min — el BFF es pesado (5 upstream calls)
  })

  // Sin token en localStorage → DeviceNotConnected inmediato, sin round-trip.
  if (hasLocalToken === false) return <DeviceNotConnected />

  // Loading: render children optimistically — el POS tiene su propio LoadingScreen.
  if (status === "pending") return <>{children}</>

  if (status === "error") {
    const err = error as { status?: number }
    if (err?.status === 401) {
      return <DeviceNotConnected />
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

  return (
    <>
      <PosUnauthorizedSentinel />
      {children}
    </>
  )
}
