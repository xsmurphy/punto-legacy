/**
 * Store global de estado de sincronización offline.
 *
 * S1 del feature offline-writes (2026-06-25).
 * Centraliza: cantidad de ventas pendientes, si hay sync en curso, y
 * cuándo fue la última sync exitosa.
 */

import { create } from 'zustand'

interface OfflineSyncState {
  pendingCount: number
  isSyncing: boolean
  lastSyncAt: string | null
  setPendingCount: (count: number) => void
  setIsSyncing: (syncing: boolean) => void
  setLastSyncAt: (at: string | null) => void
}

export const useOfflineSyncStore = create<OfflineSyncState>()((set) => ({
  pendingCount: 0,
  isSyncing: false,
  lastSyncAt: null,
  setPendingCount: (count) => set({ pendingCount: count }),
  setIsSyncing: (syncing) => set({ isSyncing: syncing }),
  setLastSyncAt: (at) => set({ lastSyncAt: at }),
}))
