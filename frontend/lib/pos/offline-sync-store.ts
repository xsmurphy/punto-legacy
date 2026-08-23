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
  /**
   * `true` cuando el catálogo con el que la caja está operando salió del
   * snapshot de IndexedDB (`lib/pos/bootstrap-cache.ts`) y no de la red.
   *
   * Vive acá y no dentro de la query de react-query a propósito: el dato
   * cacheado bajo `["pos-bootstrap"]` tiene que seguir siendo un `PosBootstrap`
   * pelado (hay call-sites que lo leen con `qc.getQueryData`, ej.
   * `transactions-list.tsx`), así que la PROCEDENCIA viaja al costado en vez
   * de envolver el payload.
   *
   * Lo consumen el indicador de estado de la caja y `useCatalogSeed`, que no
   * debe primar marcas de agua de sync con datos que no vinieron del server.
   */
  catalogFromCache: boolean
  /** ISO de cuándo se guardó el snapshot en uso. `null` si el catálogo es de red. */
  catalogCachedAt: string | null
  setPendingCount: (count: number) => void
  setFailedCount: (count: number) => void
  setIsSyncing: (syncing: boolean) => void
  setLastSyncAt: (at: string | null) => void
  setQueueDialogOpen: (open: boolean) => void
  setCatalogSource: (fromCache: boolean, cachedAt: string | null) => void
}

export const useOfflineSyncStore = create<OfflineSyncState>()((set) => ({
  pendingCount: 0,
  failedCount: 0,
  isSyncing: false,
  lastSyncAt: null,
  queueDialogOpen: false,
  catalogFromCache: false,
  catalogCachedAt: null,
  setPendingCount: (count) => set({ pendingCount: count }),
  setFailedCount: (count) => set({ failedCount: count }),
  setIsSyncing: (syncing) => set({ isSyncing: syncing }),
  setLastSyncAt: (at) => set({ lastSyncAt: at }),
  setQueueDialogOpen: (open) => set({ queueDialogOpen: open }),
  setCatalogSource: (fromCache, cachedAt) =>
    set({ catalogFromCache: fromCache, catalogCachedAt: fromCache ? cachedAt : null }),
}))
