import { openDB, type DBSchema, type IDBPDatabase } from 'idb'
import type { CreateSalePayload } from '@/lib/commands/create-sale'

interface OfflineSaleRow {
  clientTempId: string
  leasedInvoiceNo: number
  sale: CreateSalePayload
  status: 'pending' | 'syncing' | 'failed'
  error?: { code: string; message: string }
  createdAt: string
  lastAttemptAt?: string
  attempts: number
}

export type { OfflineSaleRow }

interface PosOfflineDB extends DBSchema {
  pendingSales: {
    key: string
    value: OfflineSaleRow
  }
}

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
  return dbPromise as Promise<IDBPDatabase<PosOfflineDB>>
}

export async function enqueue(row: Omit<OfflineSaleRow, 'status' | 'attempts' | 'createdAt'>): Promise<void> {
  const db = await getDB()
  await db.put('pendingSales', {
    ...row,
    status: 'pending',
    attempts: 0,
    createdAt: new Date().toISOString(),
  })
}

export async function peekAll(): Promise<OfflineSaleRow[]> {
  const db = await getDB()
  return db.getAll('pendingSales')
}

export async function markSynced(clientTempId: string): Promise<void> {
  const db = await getDB()
  await db.delete('pendingSales', clientTempId)
}

export async function markFailed(clientTempId: string, error: { code: string; message: string }): Promise<void> {
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

export async function markSyncing(clientTempId: string): Promise<void> {
  const db = await getDB()
  const row = await db.get('pendingSales', clientTempId)
  if (!row) return
  await db.put('pendingSales', { ...row, status: 'syncing' })
}

export async function discard(clientTempId: string): Promise<void> {
  const db = await getDB()
  await db.delete('pendingSales', clientTempId)
}

export async function getCount(): Promise<number> {
  const db = await getDB()
  return db.count('pendingSales')
}
