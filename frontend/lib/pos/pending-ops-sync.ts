/**
 * Motor de sincronización de la cola de operaciones (`pending-ops.ts`).
 *
 * Está separado del hook de React y del transporte HTTP a propósito: la lógica
 * que decide qué se manda, en qué orden, qué se reintenta y qué se da por
 * perdido es la parte que hay que poder probar, y probarla no debería requerir
 * ni un componente montado ni un servidor.
 *
 * El invariante que gobierna todo el archivo
 * ──────────────────────────────────────────
 * **Un canal se procesa en orden y se FRENA en la primera operación que no
 * salió bien.** No se saltea ninguna. Suena conservador y lo es, pero la
 * alternativa —seguir con las de atrás— es la que rompe cosas: en el canal
 * `drawer` significaría aplicar la apertura del turno de la tarde sobre una
 * caja que el servidor todavía cree abierta desde la mañana porque el cierre
 * quedó rechazado. Los canales entre sí no se frenan: que las impresoras estén
 * trabadas no tiene por qué demorar un ajuste.
 */

import {
  markOpFailed,
  markOpRetry,
  markOpSynced,
  markOpSyncing,
  opBackoffElapsed,
  peekAllOps,
  OPS_MAX_ATTEMPTS,
} from '@/lib/pos/pending-ops'
import type { PendingOpRow, PendingOpStream } from '@/lib/pos/pending-ops'

/**
 * Error de una operación, con la única clasificación que le importa al motor:
 * si reintentarlo tal cual tiene alguna chance de funcionar.
 *
 * `transient` es del transporte, no del motor: solo quien hizo la request sabe
 * si lo que falló fue la red (reintentable) o el servidor diciendo que no
 * (terminal). Adivinarlo acá, por el texto del error, es cómo se construye un
 * bucle de reintentos infinito contra un 422.
 */
export class PendingOpError extends Error {
  code: string
  transient: boolean

  constructor(code: string, message: string, transient: boolean) {
    super(message)
    this.name = 'PendingOpError'
    this.code = code
    this.transient = transient
  }
}

/** Manda una operación al servidor. Resuelve en éxito, tira `PendingOpError` si no. */
export type OpSender = (row: PendingOpRow) => Promise<void>

export interface SyncPendingOpsOptions {
  send: OpSender
  /**
   * Caja del device AHORA. Es el cerco: una operación de otra caja no se
   * aplica. Si viene vacío no se procesa nada — el POS sin caja activa no
   * tiene contexto para escribir (memoria
   * `project_pos_contexto_obligatorio`: nunca inventar la dimensión que falta).
   */
  activeRegisterId: string
  /** Inyectable para los tests. Por defecto, el reloj real. */
  now?: () => number
}

export interface SyncPendingOpsResult {
  synced: number
  failed: number
  retried: number
  /** Canales que quedaron frenados en esta pasada, con el motivo. */
  halted: { stream: PendingOpStream; reason: HaltReason }[]
}

export type HaltReason =
  | 'failed-head' // adelante hay una operación terminal sin resolver
  | 'backoff' // la próxima todavía está esperando su turno de reintento
  | 'register-changed' // la operación es de otra caja
  | 'error' // la operación de esta pasada falló

/**
 * Drena la cola. Devuelve el resumen de la pasada.
 *
 * No hace mutex ni chequea `navigator.onLine`: eso es responsabilidad del
 * llamador (el hook), que es quien conoce el ciclo de vida. Acá adentro se
 * asume que corre una sola pasada a la vez.
 */
export async function syncPendingOps(
  opts: SyncPendingOpsOptions,
): Promise<SyncPendingOpsResult> {
  const now = opts.now ?? (() => Date.now())
  const result: SyncPendingOpsResult = { synced: 0, failed: 0, retried: 0, halted: [] }

  if (opts.activeRegisterId === '') return result

  const all = await peekAllOps()
  const streams = [...new Set(all.map((r) => r.stream))]

  for (const stream of streams) {
    const rows = all.filter((r) => r.stream === stream)
    for (const row of rows) {
      // ── Cabeza terminal: el canal está trabado hasta que una persona
      // decida. No se saltea: ver el invariante del docblock.
      if (row.status === 'failed') {
        result.halted.push({ stream, reason: 'failed-head' })
        break
      }
      // `syncing` acá es una operación de un ciclo que murió a mitad de
      // camino. `reviveInterruptedOps()` (al arrancar) la devuelve a
      // `pending`; mientras tanto frena el canal en vez de mandarla de nuevo,
      // porque no sabemos si la primera llegó.
      if (row.status === 'syncing') {
        result.halted.push({ stream, reason: 'error' })
        break
      }
      if (!opBackoffElapsed(row, now())) {
        result.halted.push({ stream, reason: 'backoff' })
        break
      }

      // ── Cerco por caja ──────────────────────────────────────────────────
      // El device se movió a otra caja mientras esto estaba en cola. Aplicarlo
      // ahora escribiría sobre la caja equivocada: los ajustes son POR CAJA y
      // el endpoint saca el `registerId` del token, no del payload, así que el
      // servidor no tiene forma de notar el desvío. Se marca terminal con un
      // motivo que se entiende leyéndolo.
      if (row.registerId !== opts.activeRegisterId) {
        await markOpFailed(row.opId, {
          code: 'REGISTER_CHANGED',
          message: 'Se hizo en otra caja. Volvé a esa caja para enviarla, o descartala.',
        })
        result.failed += 1
        result.halted.push({ stream, reason: 'register-changed' })
        break
      }

      await markOpSyncing(row.opId)
      try {
        await opts.send(row)
        await markOpSynced(row.opId)
        result.synced += 1
      } catch (err) {
        const opErr =
          err instanceof PendingOpError
            ? err
            : new PendingOpError(
                'UNKNOWN',
                err instanceof Error ? err.message : 'Error desconocido',
                // Un error que el transporte no clasificó se trata como
                // TERMINAL. Al revés —reintentar lo que no entendemos— es el
                // camino corto a martillar el servidor con un payload que
                // nunca va a aceptar.
                false,
              )
        if (opErr.transient && row.attempts + 1 < OPS_MAX_ATTEMPTS) {
          await markOpRetry(row.opId, { code: opErr.code, message: opErr.message })
          result.retried += 1
        } else {
          await markOpFailed(row.opId, { code: opErr.code, message: opErr.message })
          result.failed += 1
        }
        result.halted.push({ stream, reason: 'error' })
        break
      }
    }
  }

  return result
}
