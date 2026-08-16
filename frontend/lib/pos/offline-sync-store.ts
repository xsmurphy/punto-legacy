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
  /**
   * Subconjunto TERMINAL de `pendingCount` (status 'failed' — ver
   * `offline-queue.ts` `markFailed`). No se reintenta solo: si `failedCount`
   * > 0, hay una venta que ya se emitió e imprimió pero el backend la
   * rechazó (o hubo un error de datos), y se queda ahí hasta que alguien
   * abre `SyncQueueDialog` y decide reintentar o descartar. Es la señal
   * "esto no se va a resolver solo" — `OfflineBanner` y el indicador del
   * carrito la usan para no dejarla morir en silencio (context/08 §53).
   */
  failedCount: number
  isSyncing: boolean
  lastSyncAt: string | null
  /** Controla `SyncQueueDialog` desde cualquier punto de entrada (banner, indicador del carrito). */
  queueDialogOpen: boolean
  setPendingCount: (count: number) => void
  setFailedCount: (count: number) => void
  setIsSyncing: (syncing: boolean) => void
  setLastSyncAt: (at: string | null) => void
  setQueueDialogOpen: (open: boolean) => void
}

export const useOfflineSyncStore = create<OfflineSyncState>()((set) => ({
  pendingCount: 0,
  failedCount: 0,
  isSyncing: false,
  lastSyncAt: null,
  queueDialogOpen: false,
  setPendingCount: (count) => set({ pendingCount: count }),
  setFailedCount: (count) => set({ failedCount: count }),
  setIsSyncing: (syncing) => set({ isSyncing: syncing }),
  setLastSyncAt: (at) => set({ lastSyncAt: at }),
  setQueueDialogOpen: (open) => set({ queueDialogOpen: open }),
}))
