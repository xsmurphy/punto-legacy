'use client'

/**
 * Banda de ventas que NO se van a sincronizar solas.
 *
 * Alcance acotado (2026-08-23) al único estado TERMINAL: `failedCount > 0`.
 * Los estados transitorios —sin conexión, sincronizando— se mudaron a
 * `OfflineStatusPill`, que flota sobre el workspace y no lo desplaza: como
 * banda, aparecían y desaparecían solos en cada ciclo de sync y empujaban
 * toda la caja hacia abajo, contra la regla de posiciones estables del POS.
 *
 * Esto SÍ se queda como banda, a propósito: `failedCount > 0` es terminal
 * (context/08 §53, `markFailed` en `offline-queue.ts`), no se resuelve al
 * volver la conexión, y el owner pidió que no quedara escondido detrás del
 * indicador chico del carrito. Un estado raro, persistente y que exige
 * intervención humana justifica ocupar lugar en el layout; un parpadeo de red
 * no.
 */

import * as React from 'react'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'

export function OfflineBanner() {
  const failedCount = useOfflineSyncStore((s) => s.failedCount)
  const setQueueDialogOpen = useOfflineSyncStore((s) => s.setQueueDialogOpen)

  if (failedCount === 0) return null

  return (
    <button
      onClick={() => setQueueDialogOpen(true)}
      className="shrink-0 border-b border-destructive/30 bg-destructive/10 px-3 py-2 text-center text-xs font-medium text-destructive transition-colors hover:bg-destructive/20"
    >
      {failedCount} venta{failedCount !== 1 ? 's' : ''} no se pudo sincronizar — tocá para revisar y reintentar
    </button>
  )
}
