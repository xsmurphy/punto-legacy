"use client"

import * as React from "react"
import { useQuery } from "@tanstack/react-query"
import { PosUnauthorizedSentinel } from "@/components/pos/pos-unauthorized-sentinel"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { WifiOff } from "lucide-react"
import type { PosBootstrap } from "@/lib/types/pos-bootstrap"

/**
 * Guard de auth exclusivo del POS. Lee la cookie `_jwt` (realm pos-app, 10 años)
 * — NO la cookie del panel. Si `_jwt` ausente, expirada o revocada → muestra
 * <DeviceNotConnected /> (pantalla con instrucciones para que el admin genere
 * un link de conexión desde /settings/devices).
 *
 * El BFF /api/pos/bootstrap acepta ambas cookies pero el POS es el único realm
 * durable: la sesión del panel (_jwt_panel, 24h) puede expirar sin afectar la caja.
 *
 * Flujos cubiertos:
 *   1. Sin _jwt → 401 → DeviceNotConnected
 *   2. _jwt válida → POS funciona
 *   3. Error transitorio (500, red) → UI de retry, NO bloquea
 *
 * El viejo /pos-pair (form de contraseña admin) fue eliminado. El único pairing
 * vigente es el invitation-based vía /connect/[id] generado por el admin.
 */
export function PosAuthGuard({ children }: { children: React.ReactNode }) {
  const { status, error, refetch } = useQuery<PosBootstrap>({
    queryKey: ["pos-bootstrap-auth"],
    queryFn: async () => {
      const res = await fetch("/api/pos/bootstrap", {
        credentials: "include",
        cache: "no-store",
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
