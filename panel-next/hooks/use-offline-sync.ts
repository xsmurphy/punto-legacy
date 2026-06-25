'use client'

import * as React from 'react'
import { peekAll, markSynced, markFailed, markSyncing, getCount } from '@/lib/pos/offline-queue'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'
import { api } from '@/lib/api-client'

interface SyncResult {
  clientTempId: string
  ok: boolean
  transactionId?: string
  duplicated?: boolean
  error?: { code: string; message: string }
}

export function useOfflineSync() {
  const setPendingCount = useOfflineSyncStore((s) => s.setPendingCount)
  const setIsSyncing = useOfflineSyncStore((s) => s.setIsSyncing)
  const setLastSyncAt = useOfflineSyncStore((s) => s.setLastSyncAt)

  const syncRef = React.useRef(false)

  const runSync = React.useCallback(async () => {
    if (syncRef.current) return
    if (!navigator.onLine) return

    const pending = await peekAll()
    const toSync = pending.filter((r) => r.status === 'pending' || r.status === 'failed')
    if (toSync.length === 0) {
      const count = await getCount()
      setPendingCount(count)
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
        if (res.ok) {
          await markSynced(res.clientTempId)
        } else if (res.error) {
          await markFailed(res.clientTempId, res.error)
        }
      }

      setLastSyncAt(new Date().toISOString())
    } catch {
      for (const r of toSync) {
        await markFailed(r.clientTempId, { code: 'NETWORK_ERROR', message: 'Error de red al sincronizar' })
      }
    } finally {
      syncRef.current = false
      setIsSyncing(false)
      const count = await getCount()
      setPendingCount(count)
    }
  }, [setPendingCount, setIsSyncing, setLastSyncAt])

  React.useEffect(() => {
    void getCount().then(setPendingCount)
  }, [setPendingCount])

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
