/**
 * Cola offline del POS — persiste ventas pendientes de sync en IndexedDB (via idb).
 *
 * S2 del feature offline-writes (2026-06-25).
 *
 * IndexedDB aguanta volúmenes de MBs, es async (no bloquea el hilo), y
 * sobrevive reinicios del browser. A diferencia de localStorage (5 MB, sync,
 * pierde datos en privado/incógnito), IndexedDB es la storage correcta para
 * una cola de ventas offline.
 *
 * DB: 'punto-pos-offline', store: 'pendingSales', keyPath: 'clientTempId'
 */

import { openDB, type DBSchema, type IDBPDatabase } from 'idb'
import type { CreateSalePayload } from '@/lib/commands/create-sale'

export interface OfflineError {
  code: string
  message: string
}

export type OfflineSaleStatus = 'pending' | 'syncing' | 'failed'

export interface OfflineSaleRow {
  clientTempId: string
  leasedInvoiceNo: number
  sale: CreateSalePayload
  status: OfflineSaleStatus
  error?: OfflineError
  createdAt: string      // ISO
  lastAttemptAt?: string // ISO
  attempts: number
}

// ── Schema de la DB ───────────────────────────────────────────────────────────

interface PosOfflineDB extends DBSchema {
  pendingSales: {
    key: string
    value: OfflineSaleRow
  }
}

// ── Singleton de la DB ────────────────────────────────────────────────────────

let dbPromise: Promise<IDBPDatabase<PosOfflineDB>> | null = null

function getDB(): Promise<IDBPDatabase<PosOfflineDB>> {
  if (!dbPromise) {
    dbPromise = openDB<PosOfflineDB>('punto-pos-offline', 1, {
      upgrade(db) {
        if (!db.objectStoreNames.contains('pendingSales')) {
          db.createObjectStore('pendingSales', { keyPath: 'clientTempId' })
        }
      },
    })
  }
  return dbPromise
}

// ── API pública ───────────────────────────────────────────────────────────────

/** Agrega una venta a la cola con status 'pending'. */
export async function enqueue(
  row: Omit<OfflineSaleRow, 'status' | 'attempts' | 'createdAt'>,
): Promise<void> {
  const db = await getDB()
  await db.put('pendingSales', {
    ...row,
    status: 'pending',
    attempts: 0,
    createdAt: new Date().toISOString(),
  })
}

/** Devuelve todas las ventas pendientes de sync. */
export async function peekAll(): Promise<OfflineSaleRow[]> {
  const db = await getDB()
  return db.getAll('pendingSales')
}

/** Marca una venta como sincronizada y la elimina de la cola. */
export async function markSynced(clientTempId: string): Promise<void> {
  const db = await getDB()
  await db.delete('pendingSales', clientTempId)
}

/**
 * Marca una venta como fallida TERMINAL: NO se reintenta automáticamente.
 * Para errores de negocio no recuperables (ej. LEASE_EXPIRED — el número de
 * comprobante venció/ya se usó) o cuando se agotaron los reintentos transitorios.
 * Queda en la cola para que el operador la revise/descarte manualmente; el loop
 * de sync la ignora (solo reintenta 'pending'). Evita el bucle de reintento
 * infinito que generaba cientos de POSTs por cliente (auto-DDoS).
 */
export async function markFailed(
  clientTempId: string,
  error: OfflineError,
): Promise<void> {
  const db = await getDB()
  const row = await db.get('pendingSales', clientTempId)
  if (!row) return
  await db.put('pendingSales', {
    ...row,
    status: 'failed',
    error,
    attempts: row.attempts + 1,
    lastAttemptAt: new Date().toISOString(),
  })
}

/**
 * Reintento TRANSITORIO: vuelve la venta a 'pending' (reintentable) e incrementa
 * attempts + lastAttemptAt. Para errores recuperables (red, sync interrumpido).
 * El loop aplica backoff exponencial sobre lastAttemptAt/attempts.
 */
export async function markRetry(
  clientTempId: string,
  error: OfflineError,
): Promise<void> {
  const db = await getDB()
  const row = await db.get('pendingSales', clientTempId)
  if (!row) return
  await db.put('pendingSales', {
    ...row,
    status: 'pending',
    error,
    attempts: row.attempts + 1,
    lastAttemptAt: new Date().toISOString(),
  })
}

/** Marca una venta como en proceso de sincronización. */
export async function markSyncing(clientTempId: string): Promise<void> {
  const db = await getDB()
  const row = await db.get('pendingSales', clientTempId)
  if (!row) return
  await db.put('pendingSales', { ...row, status: 'syncing' })
}

/** Descarta una venta de la cola (acción manual del operador). */
export async function discard(clientTempId: string): Promise<void> {
  const db = await getDB()
  await db.delete('pendingSales', clientTempId)
}

/** Devuelve el número de ventas en la cola. */
export async function getCount(): Promise<number> {
  const db = await getDB()
  return db.count('pendingSales')
}
