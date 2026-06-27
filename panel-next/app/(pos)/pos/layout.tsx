"use client"

/**
 * Layout del workspace de la caja.
 *
 * El bloque DERECHO (carrito / venta) es persistente — vive en este layout,
 * así que se mantiene montado (y conserva su estado) mientras el bloque
 * IZQUIERDO cambia según la ruta:
 *   /pos            → grilla de hotkeys (ProductArea)
 *   /pos/mesas      → módulo Mesas
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
  const { data: bootstrap } = useBootstrap()
  const autoLockApplied = React.useRef(false)
  if (!autoLockApplied.current && bootstrap) {
    autoLockApplied.current = true
    const userCount = bootstrap.userCount
    if (typeof userCount === "number" && userCount > 1) {
      useLockStore.getState().lock()
    }
  }

  if (!bootstrap) {
    return <PosLoadingScreen />
  }

  return (
    <div className="relative flex h-full w-full overflow-hidden">
      <OfflineBanner />
      <BeforeUnloadGuard />
      <OfflineSyncRunner />
      <div className="hidden flex-[7] overflow-hidden md:block">
        {children}
      </div>
      <div className="flex-1 overflow-hidden md:flex-[3]">
        <CartPanel />
      </div>
      <LockScreen />
    </div>
  )
}
