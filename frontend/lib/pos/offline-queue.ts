/**
 * Cola offline del POS — ventas emitidas que todavía no llegaron al backend.
 *
 * S2 del feature offline-writes (2026-06-25).
 *
 * IndexedDB aguanta volúmenes de MBs, es async (no bloquea el hilo), y
 * sobrevive reinicios del browser. A diferencia de localStorage (5 MB, sync,
 * pierde datos en privado/incógnito), IndexedDB es la storage correcta para
 * una cola de ventas offline.
 *
 * La base y el schema los define `lib/pos/offline-db.ts` — este módulo solo
 * opera sobre el store `pendingSales`. Antes abría `punto-pos-offline` por su
 * cuenta; cuando apareció un segundo store (el snapshot del bootstrap, para
 * que la caja arranque sin red) esa apertura duplicada pasó a ser un conflicto
 * de versiones esperando a suceder. Ver el docblock de `offline-db.ts`.
 */

import { getPosOfflineDB as getDB } from '@/lib/pos/offline-db'

// Los tipos de la fila viven con el schema, pero se re-exportan acá porque
// los call-sites (`use-offline-sync`, `sync-queue-dialog`) los importan de
// este módulo desde antes de que existiera `offline-db.ts`.
export type {
  OfflineError,
  OfflineSaleStatus,
  OfflineSaleRow,
} from '@/lib/pos/offline-db'

import type { OfflineError, OfflineSaleRow } from '@/lib/pos/offline-db'

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
 * Para errores de negocio no recuperables (ej. REGISTER_NOT_HELD — la caja
 * ya no es de este device) o cuando se agotaron los reintentos transitorios.
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

/**
 * Rechazos de tenencia que dejan la caja LIBRE — recuperables sin que nadie
 * intervenga (incidente 2026-08-23).
 *
 * Antes existía un solo `REGISTER_NOT_HELD`, terminal para todas las causas,
 * así que una venta ya emitida e impresa quedaba muerta en la cola incluso con
 * la caja libre y el device pudiendo retomarla. Ahora el backend distingue la
 * causa (`RegisterLeaseService::conflictMessage()`) y estos dos códigos
 * significan "nadie tiene la caja": el device la vuelve a tomar y la MISMA
 * venta, con el MISMO número, sincroniza sola. Si ese número ya no estuviera
 * libre, `uq_transaction_expedition_invoiceno` (mig 145) lo frena como
 * NUMBER_TAKEN — nunca se duplica un comprobante por reintentar.
 *
 * `REGISTER_TAKEN` NO está acá: otro device tiene la caja y eso no se destraba
 * solo. Esa venta queda 'failed', con el nombre de quién la tiene y qué hacer,
 * para que el operador la resuelva — nunca se descarta sola.
 *
 * Vive en este módulo (dueño de la cola) y no en el hook, porque tanto el loop
 * de sync como el diálogo de la cola necesitan la misma lista.
 */
export const TENANCY_RECOVERABLE_CODES = new Set(['REGISTER_RELEASED', 'REGISTER_NEVER_HELD'])

/**
 * Devuelve a la cola las ventas que fallaron por una tenencia que YA se
 * recuperó — el rescate del caso inevitable (incidente 2026-08-23).
 *
 * Escenario: el device tenía la caja, se fue sin red, un admin se la liberó
 * mientras tanto, el cajero vendió e imprimió, y al sincronizar el servidor
 * rechazó la venta. La caja quedó LIBRE, así que apenas el device la vuelve a
 * tomar (`ensureTenancy()` en el loop de sync) esa venta es perfectamente
 * válida otra vez, con su mismo número. Sin esto se quedaba en 'failed' para
 * siempre, esperando que alguien abriera el diálogo y apretara reintentar —
 * una venta ya cobrada e impresa dependiendo de que un humano se acuerde.
 *
 * `attempts`/`lastAttemptAt` se resetean: el backoff exponencial mide fallas
 * transitorias de red, y acá lo que cambió es una CONDICIÓN del mundo, no un
 * reintento más de lo mismo. Sin el reset, una venta con varios intentos
 * encima esperaría hasta 30 minutos para reintentar algo que ya funciona.
 *
 * No toca `REGISTER_TAKEN` (otro device tiene la caja: no se destraba solo) ni
 * ningún otro error de negocio.
 *
 * @returns cuántas ventas volvieron a 'pending'.
 */
export async function revivePendingAfterTenancy(): Promise<number> {
  const db = await getDB()
  const all = await db.getAll('pendingSales')
  const revivable = all.filter(
    (r) => r.status === 'failed' && r.error != null && TENANCY_RECOVERABLE_CODES.has(r.error.code),
  )
  for (const row of revivable) {
    await db.put('pendingSales', {
      ...row,
      status: 'pending',
      error: undefined,
      attempts: 0,
      lastAttemptAt: undefined,
    })
  }
  return revivable.length
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

/** Devuelve el número de ventas en la cola (cualquier status). */
export async function getCount(): Promise<number> {
  const db = await getDB()
  return db.count('pendingSales')
}

/**
 * Devuelve el número de ventas en status 'failed' — TERMINALES, no se
 * reintentan solas (ver `markFailed`). Es la señal de "esto necesita que
 * alguien lo mire": a diferencia de 'pending'/'syncing', que se resuelven
 * solas al volver la conexión, una fallida se queda ahí para siempre si
 * nadie abre `SyncQueueDialog` y decide reintentar o descartar.
 */
export async function getFailedCount(): Promise<number> {
  const db = await getDB()
  const all = await db.getAll('pendingSales')
  return all.filter((r) => r.status === 'failed').length
}
