/**
 * Cola offline del POS — persiste ventas pendientes de sync en localStorage.
 *
 * S1 del feature offline-writes (2026-06-25).
 * Implementación mínima via localStorage para fase S1-S3.
 * Fase S4+ migrará a IndexedDB (Dexie) cuando la carga lo justifique.
 */

const QUEUE_KEY = 'pos:offline-queue'

export interface OfflineError {
  code: string
  message: string
}

export type OfflineSaleStatus = 'pending' | 'syncing' | 'failed'

export interface OfflineSaleRow {
  clientTempId: string
  leasedInvoiceNo: number | string | null
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  sale: any
  status: OfflineSaleStatus
  error?: OfflineError
  createdAt: string
  attempts: number
}

function readQueue(): OfflineSaleRow[] {
  if (typeof window === 'undefined') return []
  try {
    const raw = localStorage.getItem(QUEUE_KEY)
    return raw ? (JSON.parse(raw) as OfflineSaleRow[]) : []
  } catch {
    return []
  }
}

function writeQueue(rows: OfflineSaleRow[]): void {
  if (typeof window === 'undefined') return
  localStorage.setItem(QUEUE_KEY, JSON.stringify(rows))
}

/** Devuelve todas las ventas pendientes de sync. */
export async function peekAll(): Promise<OfflineSaleRow[]> {
  return readQueue()
}

/** Agrega una venta a la cola con status 'pending'. */
export async function enqueue(
  row: Omit<OfflineSaleRow, 'status' | 'attempts' | 'createdAt'>,
): Promise<void> {
  const rows = readQueue()
  rows.push({
    ...row,
    status: 'pending',
    attempts: 0,
    createdAt: new Date().toISOString(),
  })
  writeQueue(rows)
}

/** Marca una venta como sincronizada y la elimina de la cola. */
export async function markSynced(clientTempId: string): Promise<void> {
  const rows = readQueue().filter((r) => r.clientTempId !== clientTempId)
  writeQueue(rows)
}

/** Marca una venta como fallida con el error. */
export async function markFailed(
  clientTempId: string,
  error: OfflineError,
): Promise<void> {
  const rows = readQueue().map((r) =>
    r.clientTempId === clientTempId
      ? { ...r, status: 'failed' as OfflineSaleStatus, error, attempts: r.attempts + 1 }
      : r,
  )
  writeQueue(rows)
}

/** Marca una venta como en proceso de sincronización. */
export async function markSyncing(clientTempId: string): Promise<void> {
  const rows = readQueue().map((r) =>
    r.clientTempId === clientTempId ? { ...r, status: 'syncing' as OfflineSaleStatus } : r,
  )
  writeQueue(rows)
}

/** Descarta una venta de la cola (acción manual del operador). */
export async function discard(clientTempId: string): Promise<void> {
  const rows = readQueue().filter((r) => r.clientTempId !== clientTempId)
  writeQueue(rows)
}

/** Devuelve el número de ventas en la cola. */
export async function getCount(): Promise<number> {
  return readQueue().length
}
