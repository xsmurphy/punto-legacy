import { create } from 'zustand'

interface OfflineSyncState {
  pendingCount: number
  isSyncing: boolean
  lastSyncAt: string | null
  setPendingCount: (n: number) => void
  setIsSyncing: (b: boolean) => void
  setLastSyncAt: (s: string) => void
}

export const useOfflineSyncStore = create<OfflineSyncState>((set) => ({
  pendingCount: 0,
  isSyncing: false,
  lastSyncAt: null,
  setPendingCount: (n) => set({ pendingCount: n }),
  setIsSyncing: (b) => set({ isSyncing: b }),
  setLastSyncAt: (s) => set({ lastSyncAt: s }),
}))
