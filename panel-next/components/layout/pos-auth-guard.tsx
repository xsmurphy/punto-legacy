"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { useQuery } from "@tanstack/react-query"
import { PosUnauthorizedSentinel } from "@/components/pos/pos-unauthorized-sentinel"
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
 */
export function PosAuthGuard({ children }: { children: React.ReactNode }) {
  const router = useRouter()

  const { status } = useQuery<PosBootstrap>({
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
        throw new Error(`BFF error ${res.status}`)
      }
      return res.json()
    },
    retry: false,
    staleTime: 4 * 60 * 1000, // 4 min — el BFF es pesado (5 upstream calls)
  })

  React.useEffect(() => {
    if (status === "error") {
      router.replace("/pos-pair")
    }
  }, [status, router])

  // Loading: render children optimistically — el POS tiene su propio
  // LoadingScreen (PosLoadingScreen) que se muestra mientras el catalog store
  // no está hidratado. No bloqueamos el render acá para evitar flash.
  if (status === "pending") return <>{children}</>

  // 401 / error → efecto de arriba redirige, nada que renderizar.
  if (status === "error") return null

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
