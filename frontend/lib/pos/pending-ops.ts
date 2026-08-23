/**
 * Cola de OPERACIONES pendientes del POS — la generalización de la cola de
 * ventas (`offline-queue.ts`) a todo lo demás que la caja puede hacer sin red.
 *
 * Por qué una cola nueva y no reusar `pendingSales`
 * ────────────────────────────────────────────────
 * `pendingSales` es una cola de VENTAS: su fila tiene `invoiceNo`, su payload
 * es un `CreateSalePayload`, y su loop de sync postea un lote entero a
 * `/v1/offline-sync`. Nada de eso aplica a "el cajero apagó el teclado
 * virtual". Meterlas juntas obligaría a que cada fila fuera una unión de dos
 * cosas sin nada en común y a que el loop ramificara por tipo en cada paso.
 *
 * Lo que SÍ se reusa es la forma probada de esa cola —encolar / peek / marcar
 * sincronizado / marcar fallido / reintentar / descartar, con backoff
 * exponencial y estados terminales— y la MISMA base de IndexedDB, en un store
 * aparte (`offline-db.ts` sigue siendo el único dueño del schema).
 *
 * Las tres reglas que hacen que esto sea seguro
 * ─────────────────────────────────────────────
 * 1. **Canal FIFO** (`stream`). Dentro de un canal las operaciones se aplican
 *    en orden y de a una, y la primera que falla FRENA el canal. Un cierre de
 *    caja que el servidor rechazó no puede quedar atrás mientras la apertura
 *    del turno siguiente se aplica encima.
 * 2. **Cerco por caja** (`registerId`). Una operación encolada para la caja A
 *    nunca se aplica si el device pasó a ser la caja B.
 * 3. **Idempotencia** (`opId` + endpoints idempotentes). Reintentar una
 *    operación cuya respuesta se perdió no puede duplicar su efecto.
 *
 * La regla de conflicto (caja vs panel) NO vive acá sino en cómo se arma el
 * payload: los ajustes se encolan como PATCH de los campos que el cajero tocó,
 * nunca como copia entera de la config. Ver `context/51`.
 */

import { getPosOfflineDB as getDB } from '@/lib/pos/offline-db'
import type {
  OfflineError,
  PendingOpKind,
  PendingOpRow,
  PendingOpStream,
} from '@/lib/pos/offline-db'

export type {
  OfflineError,
  PendingOpKind,
  PendingOpRow,
  PendingOpStatus,
  PendingOpStream,
} from '@/lib/pos/offline-db'

// ── Backoff ───────────────────────────────────────────────────────────────────

/**
 * Mismos números que la cola de ventas (`use-offline-sync.ts`): 30 s de base,
 * duplicando, con techo de 30 min y 6 intentos. No hay motivo para que una
 * caja reintente un ajuste con otra cadencia que una venta, y dos cadencias
 * distintas serían dos cosas para entender en vez de una.
 */
export const OPS_MAX_ATTEMPTS = 6
const BASE_BACKOFF_MS = 30_000
const MAX_BACKOFF_MS = 30 * 60_000

/** ¿Venció el backoff exponencial desde el último intento de esta operación? */
export function opBackoffElapsed(row: PendingOpRow, now: number = Date.now()): boolean {
  if (!row.lastAttemptAt) return true
  const delay = Math.min(MAX_BACKOFF_MS, BASE_BACKOFF_MS * 2 ** Math.max(0, row.attempts - 1))
  return now - new Date(row.lastAttemptAt).getTime() >= delay
}

// ── Alta ──────────────────────────────────────────────────────────────────────

export interface EnqueueOpInput {
  kind: PendingOpKind
  stream: PendingOpStream
  registerId: string
  payload: unknown
  label: string
  /**
   * Fusión con la operación pendiente anterior del mismo `kind`, si la hay.
   *
   * Existe por un caso concreto: el cajero abre Ajustes sin red y toca cinco
   * interruptores. Sin fusionar, eso son cinco operaciones en cola, cinco
   * requests al volver la conexión y cinco líneas en la lista de pendientes
   * para describir un solo acto ("cambié los ajustes"). Con `mergePayload`
   * queda una.
   *
   * Solo fusiona contra una operación en estado `pending` (una `syncing` ya
   * salió a la red y una `failed` es un problema que el cajero tiene que ver
   * como tal, no algo para tapar con un cambio nuevo).
   */
  mergePayload?: (prev: unknown, next: unknown) => unknown
}

/** Identidad de la operación. `crypto.randomUUID` existe en todo browser que corra el POS. */
function newOpId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  // Runners sin WebCrypto (jsdom viejo, node sin globalThis.crypto). No es
  // criptografía: alcanza con que no colisione dentro de una cola de decenas.
  return `op-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

/**
 * Encola una operación. Devuelve la fila resultante — que puede ser una fila
 * NUEVA o la anterior ya fusionada (ver `mergePayload`).
 *
 * Todo el alta ocurre dentro de UNA transacción `readwrite`: el `seq` se
 * calcula leyendo el máximo actual y escribiendo en el mismo tick, así que dos
 * encolados concurrentes no pueden quedarse con el mismo número.
 */
export async function enqueueOp(input: EnqueueOpInput): Promise<PendingOpRow> {
  const db = await getDB()
  const tx = db.transaction('pendingOps', 'readwrite')
  const store = tx.objectStore('pendingOps')
  const all = await store.getAll()

  if (input.mergePayload) {
    const mergeable = all
      .filter(
        (r) =>
          r.kind === input.kind &&
          r.registerId === input.registerId &&
          r.status === 'pending',
      )
      .sort((a, b) => b.seq - a.seq)[0]
    if (mergeable) {
      const merged: PendingOpRow = {
        ...mergeable,
        payload: input.mergePayload(mergeable.payload, input.payload),
        label: input.label,
        // El backoff se reinicia: lo que hay en la cola ya no es lo que falló,
        // es una operación distinta que todavía no se intentó nunca.
        attempts: 0,
        lastAttemptAt: undefined,
        error: undefined,
      }
      await store.put(merged)
      await tx.done
      return merged
    }
  }

  const seq = all.reduce((max, r) => Math.max(max, r.seq), 0) + 1
  const row: PendingOpRow = {
    opId: newOpId(),
    kind: input.kind,
    stream: input.stream,
    seq,
    registerId: input.registerId,
    payload: input.payload,
    label: input.label,
    status: 'pending',
    createdAt: new Date().toISOString(),
    attempts: 0,
  }
  await store.put(row)
  await tx.done
  return row
}

// ── Lectura ───────────────────────────────────────────────────────────────────

/** Todas las operaciones en cola, en orden de encolado. */
export async function peekAllOps(): Promise<PendingOpRow[]> {
  const db = await getDB()
  const all = await db.getAll('pendingOps')
  return all.sort((a, b) => a.seq - b.seq)
}

/** Operaciones de un canal, en orden. */
export async function peekOpsByStream(stream: PendingOpStream): Promise<PendingOpRow[]> {
  const all = await peekAllOps()
  return all.filter((r) => r.stream === stream)
}

/**
 * Operaciones de un `kind` que todavía NO llegaron al servidor, en orden.
 *
 * Es lo que necesita cada lectura para no mostrar datos viejos: la vista
 * correcta de la config de la caja no es "lo que dice el servidor" sino "lo
 * que dice el servidor, más lo que esta caja cambió y todavía no mandó". Sin
 * esto, un refetch en el momento equivocado le revierte al cajero, en
 * pantalla, un interruptor que él acaba de tocar.
 *
 * Incluye `failed`: una operación fallida sigue siendo un cambio que el cajero
 * hizo y que la caja está respetando localmente. Descartarla es una decisión
 * explícita suya desde la lista de pendientes.
 */
export async function pendingOpsOfKind(
  kind: PendingOpKind,
  registerId: string,
): Promise<PendingOpRow[]> {
  const all = await peekAllOps()
  return all.filter((r) => r.kind === kind && r.registerId === registerId)
}

/** Cantidad total de operaciones en cola (cualquier estado). */
export async function getOpsCount(): Promise<number> {
  const db = await getDB()
  return db.count('pendingOps')
}

/**
 * Operaciones TERMINALES: el servidor las rechazó y no se reintentan solas.
 * Es la señal de "esto necesita que alguien lo mire" — y cuando la operación
 * es un cierre de caja, lo que necesita que alguien mire es plata.
 */
export async function getFailedOpsCount(): Promise<number> {
  const all = await peekAllOps()
  return all.filter((r) => r.status === 'failed').length
}

/** ¿Hay alguna operación de caja (apertura/cierre/movimiento) sin sincronizar? */
export async function hasPendingDrawerOps(): Promise<boolean> {
  const all = await peekAllOps()
  return all.some((r) => r.stream === 'drawer')
}

// ── Transiciones ──────────────────────────────────────────────────────────────

async function patchOp(
  opId: string,
  patch: (row: PendingOpRow) => PendingOpRow,
): Promise<void> {
  const db = await getDB()
  const row = await db.get('pendingOps', opId)
  if (!row) return
  await db.put('pendingOps', patch(row))
}

/** La operación salió a la red. */
export async function markOpSyncing(opId: string): Promise<void> {
  await patchOp(opId, (row) => ({ ...row, status: 'syncing' }))
}

/** El servidor la aceptó: sale de la cola. */
export async function markOpSynced(opId: string): Promise<void> {
  const db = await getDB()
  await db.delete('pendingOps', opId)
}

/**
 * Falla TERMINAL: no se reintenta sola. Queda en la cola, visible, hasta que
 * alguien la reintente o la descarte a mano. Nunca desaparece en silencio —
 * ese es todo el punto para un cierre de caja.
 */
export async function markOpFailed(opId: string, error: OfflineError): Promise<void> {
  await patchOp(opId, (row) => ({
    ...row,
    status: 'failed',
    error,
    attempts: row.attempts + 1,
    lastAttemptAt: new Date().toISOString(),
  }))
}

/** Falla TRANSITORIA (red): vuelve a `pending` y el backoff decide cuándo reintentar. */
export async function markOpRetry(opId: string, error: OfflineError): Promise<void> {
  await patchOp(opId, (row) => ({
    ...row,
    status: 'pending',
    error,
    attempts: row.attempts + 1,
    lastAttemptAt: new Date().toISOString(),
  }))
}

/**
 * Reintento MANUAL desde la lista de pendientes: vuelve a `pending` con el
 * contador en cero.
 *
 * El reset no es cosmético. El backoff mide "cuánto hace que esto falla solo";
 * cuando una persona aprieta reintentar es porque cambió algo del mundo (volvió
 * la red, un admin liberó la caja), y hacerla esperar veinte minutos por
 * intentos viejos sería castigarla por haber esperado.
 */
export async function retryOp(opId: string): Promise<void> {
  await patchOp(opId, (row) => ({
    ...row,
    status: 'pending',
    error: undefined,
    attempts: 0,
    lastAttemptAt: undefined,
  }))
}

/** Descarte MANUAL: el operador decide que esa operación no va. */
export async function discardOp(opId: string): Promise<void> {
  const db = await getDB()
  await db.delete('pendingOps', opId)
}

/**
 * Devuelve a `pending` las operaciones que quedaron en `syncing` de un ciclo
 * anterior — el proceso murió con la request en vuelo (tab cerrado, crash).
 *
 * Se llama al arrancar. Sin esto una operación puede quedarse en `syncing`
 * para siempre: ningún ciclo la toca (solo se procesan las `pending`) y además
 * BLOQUEA su canal, porque el canal se frena en la primera fila que no está
 * lista. Un cierre de caja atascado así se lleva puesto todo lo que venga
 * detrás.
 *
 * @returns cuántas se rescataron.
 */
export async function reviveInterruptedOps(): Promise<number> {
  const all = await peekAllOps()
  const stuck = all.filter((r) => r.status === 'syncing')
  for (const row of stuck) {
    await markOpRetry(row.opId, {
      code: 'INTERRUPTED',
      message: 'Sincronización interrumpida — reintentando',
    })
  }
  return stuck.length
}
