"use client"

/**
 * Layout del workspace de la caja.
 *
 * El bloque DERECHO (carrito / venta) es persistente — vive en este layout,
 * así que se mantiene montado (y conserva su estado) mientras el bloque
 * IZQUIERDO cambia según la ruta:
 *   /pos            → grilla de hotkeys (ProductArea)
 *   /pos/espacios   → módulo Espacios
 *   /pos/ordenes    → módulo Órdenes
 *   /pos/calendario → módulo Calendario
 *
 * Auth: el outletId+registerId del device viven en la fila `device`, decididos
 * por el admin al generar el link de invitación. NO hay selector runtime — el
 * device opera siempre con el contexto fijo de su pairing. Para cambiar caja,
 * el admin revoca el device y genera un link nuevo (o el operador lo cambia
 * desde Ajustes del POS, slice futuro).
 *
 * Responsive: en mobile el bloque izquierdo se oculta (solo carrito).
 */

import * as React from "react"
import { CartPanel } from "@/components/register/cart-panel"
import { LockScreen } from "@/components/register/lock-screen"
import { PosLoadingScreen } from "@/components/register/pos-loading-screen"
import { useCatalogSeed } from "@/hooks/use-catalog-seed"
import { useHotkeys } from "@/hooks/use-hotkeys"
import { usePosHotkeys } from "@/hooks/use-pos-hotkeys"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { posApi } from "@/lib/api/pos-client"
import { useLockStore } from "@/lib/pos/lock-store"
import { useCartStore } from "@/lib/cart/store"
import { useRealtimeSync } from "@/hooks/use-realtime-sync"
import { useOfflineSync } from "@/hooks/use-offline-sync"
import { OfflineBanner } from "@/components/pos/offline-banner"

function OfflineSyncRunner() {
  useOfflineSync()
  return null
}

function BeforeUnloadGuard() {
  const lineCount = useCartStore((s) => s.lines.length)

  React.useEffect(() => {
    if (lineCount === 0) return
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ""
    }
    window.addEventListener("beforeunload", handler)
    return () => window.removeEventListener("beforeunload", handler)
  }, [lineCount])

  return null
}

export default function PosWorkspaceLayout({
  children,
}: {
  children: React.ReactNode
}) {
  useRealtimeSync("pos")
  useCatalogSeed()
  useHotkeys()
  usePosHotkeys()

  // Auto-lock al arrancar si hay >1 operador (sin flash entre paints).
  // El flag vive en el lock-store (no en un useRef local) para sobrevivir
  // remounts del layout — Next puede invalidar la cache al navegar entre
  // rutas hijas (/pos → /pos/guardadas) y un useRef se resetearía, volviendo
  // a lockear cada vez. Incidente 2026-06-28.
  // Bootstrap del layout del POS: multi-realm en backend, pero este layout
  // SIEMPRE corre en contexto de device — inyecta posApi (Bearer) en vez
  // del default `api` (cookie panel), ver invariante en lib/api-client.ts.
  const { data: bootstrap } = useBootstrap({ client: posApi })
  if (bootstrap && !useLockStore.getState().autoLockDone) {
    useLockStore.getState().markAutoLockDone()
    const userCount = bootstrap.userCount
    if (typeof userCount === "number" && userCount > 1) {
      useLockStore.getState().lock()
    }
  }

  if (!bootstrap) {
    return <PosLoadingScreen />
  }

  return (
    <div className="relative flex h-full w-full flex-col overflow-hidden">
      {/* Banner full-width ARRIBA. Antes era hijo directo del flex-row de
          paneles → se renderizaba como una franja vertical (toda la altura)
          entre el contenido y el carrito, parpadeando en cada ciclo de sync.
          Ahora el tope es flex-col: banner arriba, paneles en una fila debajo. */}
      <OfflineBanner />
      <BeforeUnloadGuard />
      <OfflineSyncRunner />
      <div className="flex min-h-0 flex-1 overflow-hidden">
        <div className="hidden flex-[7] overflow-hidden md:block">
          {children}
        </div>
        <div className="flex-1 overflow-hidden md:flex-[3]">
          <CartPanel />
        </div>
      </div>
      <LockScreen />
    </div>
  )
}
