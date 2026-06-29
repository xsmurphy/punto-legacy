'use client'

import * as React from 'react'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'

export function OfflineBanner() {
  const [isOnline, setIsOnline] = React.useState(true)
  const pendingCount = useOfflineSyncStore((s) => s.pendingCount)
  const isSyncing = useOfflineSyncStore((s) => s.isSyncing)

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

  if (isOnline && !(isSyncing && pendingCount > 0)) return null

  return (
    <div className="shrink-0 border-b border-amber-500/20 bg-amber-500/10 px-3 py-2 text-center text-xs font-medium text-amber-900 dark:text-amber-100">
      {!isOnline
        ? 'Sin conexion — las ventas se guardaran y enviaran al volver online'
        : `Conectado · sincronizando ${pendingCount} venta${pendingCount !== 1 ? 's' : ''}`}
    </div>
  )
}
