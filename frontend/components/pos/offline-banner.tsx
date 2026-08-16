'use client'

import * as React from 'react'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'
import { useNumberingLeaseStore } from '@/lib/pos/numbering-lease-store'
import { cn } from '@/lib/utils'

/** Mismo umbral que `LOW_WATER_MARK` en `numbering-lease.ts` — duplicado a
 *  propósito (import circular si uno importara al otro solo por esta
 *  constante) en vez de un módulo compartido para un solo número. */
const LEASE_LOW_WATER_MARK = 20

export function OfflineBanner() {
  const [isOnline, setIsOnline] = React.useState(true)
  const pendingCount = useOfflineSyncStore((s) => s.pendingCount)
  const isSyncing = useOfflineSyncStore((s) => s.isSyncing)
  const failedCount = useOfflineSyncStore((s) => s.failedCount)
  const setQueueDialogOpen = useOfflineSyncStore((s) => s.setQueueDialogOpen)
  const leaseRemaining = useNumberingLeaseStore((s) => s.remaining)

  React.useEffect(() => {
    setIsOnline(navigator.onLine)
    const handleOnline = () => setIsOnline(true)
    const handleOffline = () => setIsOnline(false)
    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)
    return () => {
      window.removeEventListener('online', handleOnline)
      window.removeEventListener('offline', handleOffline)
    }
  }, [])

  // failedCount > 0 es TERMINAL (context/08 §53): no se resuelve solo al
  // volver la conexión, así que este banner se muestra SIEMPRE que haya
  // fallidas — incluso online y sin sync en curso — para que no quede
  // escondida detrás del indicador chico del carrito. Es clickeable: abre
  // el mismo `SyncQueueDialog` que el indicador del carrito.
  if (failedCount > 0) {
    return (
      <button
        onClick={() => setQueueDialogOpen(true)}
        className="shrink-0 border-b border-destructive/30 bg-destructive/10 px-3 py-2 text-center text-xs font-medium text-destructive transition-colors hover:bg-destructive/20"
      >
        {failedCount} venta{failedCount !== 1 ? 's' : ''} no se pudo sincronizar — tocá para revisar y reintentar
      </button>
    )
  }

  // Arriendo de numeración bajo/agotado (context/08 §53, escalado por el
  // owner 2026-08-16) — segunda prioridad, debajo de fallidas (que ya son
  // ventas emitidas con un problema real) pero por ENCIMA del aviso
  // genérico de offline/sync: quedarse sin números bloquea la PRÓXIMA venta
  // fiscal, con o sin conexión en ese momento, así que el cajero necesita
  // verlo con anticipación (mientras `remaining` todavía es > 0) para
  // reconectar antes de que llegue a 0, no un bloqueo sorpresa a mitad de
  // turno. `remaining === 0` es lectura válida (lease vacío o vencido) — se
  // distingue de `null` (todavía no se calculó, ver `primeLeaseStatus`).
  if (leaseRemaining !== null && leaseRemaining < LEASE_LOW_WATER_MARK) {
    return (
      <div
        className={cn(
          'shrink-0 border-b px-3 py-2 text-center text-xs font-medium',
          leaseRemaining === 0
            ? 'border-destructive/30 bg-destructive/10 text-destructive'
            : 'border-amber-500/20 bg-amber-500/10 text-amber-900 dark:text-amber-100',
        )}
      >
        {leaseRemaining === 0
          ? 'Sin números de comprobante disponibles — conectate a internet para renovar'
          : `Quedan ${leaseRemaining} números de comprobante — conectate para renovar`}
      </div>
    )
  }

  if (isOnline && !(isSyncing && pendingCount > 0)) return null

  return (
    <div className="shrink-0 border-b border-amber-500/20 bg-amber-500/10 px-3 py-2 text-center text-xs font-medium text-amber-900 dark:text-amber-100">
      {!isOnline
        ? 'Sin conexion — las ventas se guardaran y enviaran al volver online'
        : `Conectado · sincronizando ${pendingCount} venta${pendingCount !== 1 ? 's' : ''}`}
    </div>
  )
}
