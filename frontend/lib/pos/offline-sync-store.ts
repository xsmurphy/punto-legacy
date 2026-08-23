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
   * entra a Menú → Ventas pendientes y decide reintentar o descartar. Es la
   * señal "esto no se va a resolver solo" — el indicador de estado del
   * carrito la usa para no dejarla morir en silencio (context/08 §53).
   */
  failedCount: number
  /**
   * Operaciones de CONFIGURACIÓN y de CAJA en cola — ajustes, hotkeys,
   * impresoras, apertura y cierre (`lib/pos/pending-ops.ts`). Contador aparte
   * del de ventas porque no son la misma clase de cosa: una venta en cola es
   * un comprobante ya emitido e impreso, una operación en cola es un cambio
   * que el cajero hizo y todavía no viajó. Se cuentan por separado y se
   * muestran juntas en el indicador único.
   */
  pendingOpsCount: number
  /**
   * Subconjunto TERMINAL de `pendingOpsCount`. Cuando ahí adentro hay un
   * cierre de caja, esto es plata esperando a que alguien la mire — por eso
   * escala al estado destructivo del indicador igual que una venta fallida.
   */
  failedOpsCount: number
  isSyncing: boolean
  lastSyncAt: string | null
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
  setPendingOpsCount: (count: number) => void
  setFailedOpsCount: (count: number) => void
  setIsSyncing: (syncing: boolean) => void
  setLastSyncAt: (at: string | null) => void
  setCatalogSource: (fromCache: boolean, cachedAt: string | null) => void
}

export const useOfflineSyncStore = create<OfflineSyncState>()((set) => ({
  pendingCount: 0,
  failedCount: 0,
  pendingOpsCount: 0,
  failedOpsCount: 0,
  isSyncing: false,
  lastSyncAt: null,
  catalogFromCache: false,
  catalogCachedAt: null,
  setPendingCount: (count) => set({ pendingCount: count }),
  setFailedCount: (count) => set({ failedCount: count }),
  setPendingOpsCount: (count) => set({ pendingOpsCount: count }),
  setFailedOpsCount: (count) => set({ failedOpsCount: count }),
  setIsSyncing: (syncing) => set({ isSyncing: syncing }),
  setLastSyncAt: (at) => set({ lastSyncAt: at }),
  setCatalogSource: (fromCache, cachedAt) =>
    set({ catalogFromCache: fromCache, catalogCachedAt: fromCache ? cachedAt : null }),
}))
