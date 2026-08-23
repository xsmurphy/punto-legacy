'use client'

/**
 * Ciclo de vida de la cola de operaciones (`lib/pos/pending-ops.ts`): rescate
 * al arrancar, drenado periódico y drenado inmediato al volver la conexión.
 *
 * Es el gemelo de `use-offline-sync.ts` (la cola de ventas) y sigue su misma
 * forma a propósito, para que haya un solo patrón que entender. Lo que NO
 * comparte es la lógica de tenencia: una venta necesita el derecho a emitir un
 * comprobante fiscal con un número; apagar el teclado virtual de esta caja, no.
 * Atar los ajustes a la tenencia dejaría a un cajero sin poder configurar su
 * propia caja porque un admin se la liberó desde el panel.
 *
 * El mutex se toma ANTES del primer `await`: el tick del intervalo y el evento
 * `online` caen casi juntos en una reconexión, y sin eso las dos pasadas
 * armarían el mismo lote. Misma carrera, y misma solución, que en la cola de
 * ventas.
 *
 * Se monta UNA vez, en el layout del POS.
 */

import * as React from 'react'
import { useQueryClient } from '@tanstack/react-query'
import {
  getFailedOpsCount,
  getOpsCount,
  reviveInterruptedOps,
} from '@/lib/pos/pending-ops'
import { syncPendingOps } from '@/lib/pos/pending-ops-sync'
import { sendPendingOp } from '@/lib/pos/pending-ops-transport'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'
import { useCatalogStore } from '@/lib/catalog/store'
import { DRAWER_KEYS } from '@/hooks/use-drawer'

const SYNC_INTERVAL_MS = 30_000

export function usePendingOpsSync() {
  const qc = useQueryClient()
  const setPendingOpsCount = useOfflineSyncStore((s) => s.setPendingOpsCount)
  const setFailedOpsCount = useOfflineSyncStore((s) => s.setFailedOpsCount)

  const syncRef = React.useRef(false)

  const refreshCounts = React.useCallback(async () => {
    const [count, failed] = await Promise.all([getOpsCount(), getFailedOpsCount()])
    setPendingOpsCount(count)
    setFailedOpsCount(failed)
  }, [setPendingOpsCount, setFailedOpsCount])

  const runSync = React.useCallback(async () => {
    if (syncRef.current) return
    if (typeof navigator !== 'undefined' && !navigator.onLine) return
    syncRef.current = true
    try {
      const activeRegisterId = useCatalogStore.getState().activeRegisterId
      const result = await syncPendingOps({ send: sendPendingOp, activeRegisterId })

      // Con algo aplicado en el servidor, el cache de react-query quedó viejo.
      // Se invalida SOLO si hubo cambios: un invalidate en cada tick de 30 s
      // sería un refetch permanente de toda la config de la caja.
      if (result.synced > 0) {
        qc.invalidateQueries({ queryKey: ['pos-config'] })
        qc.invalidateQueries({ queryKey: ['pos-hotkeys'] })
        qc.invalidateQueries({ queryKey: ['printer-bindings'] })
        qc.invalidateQueries({ queryKey: DRAWER_KEYS.status })
        qc.invalidateQueries({ queryKey: DRAWER_KEYS.summary })
        qc.invalidateQueries({ queryKey: DRAWER_KEYS.hourly })
      }
    } catch {
      // El motor ya marca cada operación con su resultado; una excepción que
      // se le escape es de la plomería (IndexedDB inaccesible) y se reintenta
      // en el próximo ciclo. Nunca se pierde una operación por esto.
    } finally {
      syncRef.current = false
      await refreshCounts()
    }
  }, [qc, refreshCounts])

  // Rescate de arranque: lo que quedó en `syncing` de un proceso que murió a
  // mitad de camino vuelve a la cola. Sin esto una operación atascada frena su
  // canal para siempre — y si el canal es `drawer`, lo que queda trabado
  // detrás es un cierre de caja.
  React.useEffect(() => {
    async function boot() {
      await reviveInterruptedOps()
      await refreshCounts()
      void runSync()
    }
    void boot()
    // Solo al montar: `runSync` cambia de identidad con el queryClient y no
    // queremos re-disparar el rescate por eso.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  React.useEffect(() => {
    const interval = setInterval(() => {
      void runSync()
    }, SYNC_INTERVAL_MS)
    return () => clearInterval(interval)
  }, [runSync])

  React.useEffect(() => {
    const handler = () => {
      void runSync()
    }
    window.addEventListener('online', handler)
    return () => window.removeEventListener('online', handler)
  }, [runSync])
}
