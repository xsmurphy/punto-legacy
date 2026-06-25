"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { useQuery } from "@tanstack/react-query"
import { PosUnauthorizedSentinel } from "@/components/pos/pos-unauthorized-sentinel"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { WifiOff } from "lucide-react"
import type { PosBootstrap } from "@/lib/types/pos-bootstrap"

/**
 * Guard de auth exclusivo del POS. Lee la cookie `_jwt` (realm pos-app,
 * 10 años) — NO la cookie del panel `_jwt_panel`. Si `_jwt` expira o es
 * revocada → redirect a /pos-pair. NUNCA toca /login ni monta <AuthSentinel>.
 *
 * El BFF /api/pos/bootstrap acepta ambas cookies pero el POS es el único
 * realm durable: la sesión del panel (_jwt_panel, 24h) puede expirar sin
 * afectar la caja.
 *
 * Flujos cubiertos:
 *   1. Sin cookies → /pos → 401 → /pos-pair
 *   2. Solo _jwt_panel (sin _jwt) → 401 → /pos-pair
 *   3. Solo _jwt (sin _jwt_panel) → 200 → POS funciona
 *   4. Ambas cookies → 200 → POS funciona
 *   5. _jwt_panel expira mid-session → _jwt sigue válida → POS no se interrumpe
 *   6. Error transitorio (500, red) → UI de retry, NO redirige a /pos-pair
 */
export function PosAuthGuard({ children }: { children: React.ReactNode }) {
  const router = useRouter()

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

  React.useEffect(() => {
    if (status === "error" && (error as { status?: number })?.status === 401) {
      router.replace("/pos-pair")
    }
  }, [status, error, router])

  // Loading: render children optimistically — el POS tiene su propio
  // LoadingScreen (PosLoadingScreen) que se muestra mientras el catalog store
  // no está hidratado. No bloqueamos el render acá para evitar flash.
  if (status === "pending") return <>{children}</>

  if (status === "error") {
    const err = error as { status?: number }
    // 401 → el efecto de arriba redirige; nada que renderizar acá.
    if (err?.status === 401) return null
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

  // Bootstrap OK → montar PosUnauthorizedSentinel + children.
  // PosUnauthorizedSentinel escucha `pos:unauthorized` (emitido por api-client
  // cuando un request POS recibe 401 mid-session).
  return (
    <>
      <PosUnauthorizedSentinel />
      {children}
    </>
  )
}
