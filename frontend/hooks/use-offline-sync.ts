'use client'

import * as React from 'react'
import { peekAll, markSynced, markFailed, markRetry, markSyncing, getCount, getFailedCount, type OfflineSaleRow } from '@/lib/pos/offline-queue'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'
import { posApi as api } from '@/lib/api/pos-client'

interface SyncResult {
  clientTempId: string
  ok: boolean
  transactionId?: string
  duplicated?: boolean
  error?: { code: string; message: string }
}

// Solo estos errores son TRANSITORIOS (se reintentan). Cualquier otro —en
// particular LEASE_EXPIRED y validaciones de negocio— es TERMINAL: reintentarlo
// nunca va a funcionar y generaba un bucle infinito de POSTs (auto-DDoS con N
// cajas). Los terminales quedan en 'failed' para revisión manual del operador.
const RETRYABLE_CODES = new Set(['NETWORK_ERROR', 'INTERRUPTED'])
const MAX_ATTEMPTS = 6
const BASE_BACKOFF_MS = 30_000
const MAX_BACKOFF_MS = 30 * 60_000

/** ¿Pasó el backoff exponencial (sobre attempts/lastAttemptAt) desde el último intento? */
function backoffElapsed(row: OfflineSaleRow): boolean {
  if (!row.lastAttemptAt) return true
  const delay = Math.min(MAX_BACKOFF_MS, BASE_BACKOFF_MS * 2 ** Math.max(0, row.attempts - 1))
  return Date.now() - new Date(row.lastAttemptAt).getTime() >= delay
}

/** Aplica el resultado de un error: reintento transitorio (con tope) o falla terminal. */
async function applyError(clientTempId: string, attempts: number, error: { code: string; message: string }): Promise<void> {
  if (RETRYABLE_CODES.has(error.code) && attempts + 1 < MAX_ATTEMPTS) {
    await markRetry(clientTempId, error)
  } else {
    await markFailed(clientTempId, error)
  }
}

export function useOfflineSync() {
  const setPendingCount = useOfflineSyncStore((s) => s.setPendingCount)
  const setFailedCount = useOfflineSyncStore((s) => s.setFailedCount)
  const setIsSyncing = useOfflineSyncStore((s) => s.setIsSyncing)
  const setLastSyncAt = useOfflineSyncStore((s) => s.setLastSyncAt)

  const syncRef = React.useRef(false)

  const refreshCounts = React.useCallback(async () => {
    const [count, failed] = await Promise.all([getCount(), getFailedCount()])
    setPendingCount(count)
    setFailedCount(failed)
  }, [setPendingCount, setFailedCount])

  const runSync = React.useCallback(async () => {
    if (syncRef.current) return
    if (!navigator.onLine) return

    const pending = await peekAll()
    // Solo reintentamos 'pending' cuyo backoff ya venció. 'failed' es TERMINAL
    // (no se reintenta) → así LEASE_EXPIRED & co. no generan el bucle infinito.
    const toSync = pending.filter((r) => r.status === 'pending' && backoffElapsed(r))
    if (toSync.length === 0) {
      await refreshCounts()
      return
    }

    syncRef.current = true
    setIsSyncing(true)
    try {
      await Promise.all(toSync.map((r) => markSyncing(r.clientTempId)))

      const body = {
        sales: toSync.map((r) => ({
          clientTempId: r.clientTempId,
          leasedInvoiceNo: r.leasedInvoiceNo,
          sale: r.sale,
        })),
      }

      const response = await api.post<{ results: SyncResult[] }>('/v1/offline-sync', body)
      const results: SyncResult[] = response?.results ?? []

      for (const res of results) {
        if (res.ok || res.duplicated) {
          await markSynced(res.clientTempId)
        } else if (res.error) {
          const attempts = toSync.find((r) => r.clientTempId === res.clientTempId)?.attempts ?? 0
          await applyError(res.clientTempId, attempts, res.error)
        }
      }

      setLastSyncAt(new Date().toISOString())
    } catch {
      // Falla de toda la request (red/servidor) → transitorio: reintento con tope.
      for (const r of toSync) {
        await applyError(r.clientTempId, r.attempts, { code: 'NETWORK_ERROR', message: 'Error de red al sincronizar' })
      }
    } finally {
      syncRef.current = false
      setIsSyncing(false)
      await refreshCounts()
    }
  }, [refreshCounts, setIsSyncing, setLastSyncAt])

  // Boot-time cleanup (P1 code-review): si el proceso crasheó mid-flight
  // (tab cerrado, error JS durante el sync), los items quedan en 'syncing'.
  // Al montar los volvemos a 'pending' (vía markRetry, transitorio) para que el
  // próximo ciclo los reintente — respetando el tope de attempts/backoff.
  React.useEffect(() => {
    async function bootCleanup() {
      const all = await peekAll()
      const stuck = all.filter((r) => r.status === 'syncing')
      await Promise.all(
        stuck.map((r) =>
          applyError(r.clientTempId, r.attempts, {
            code: 'INTERRUPTED',
            message: 'Sync interrumpido — reintentando',
          }),
        ),
      )
      await refreshCounts()
    }
    void bootCleanup()
  }, [refreshCounts])

  React.useEffect(() => {
    const interval = setInterval(() => { void runSync() }, 30_000)
    return () => clearInterval(interval)
  }, [runSync])

  React.useEffect(() => {
    const handler = () => { void runSync() }
    window.addEventListener('online', handler)
    return () => window.removeEventListener('online', handler)
  }, [runSync])
}
