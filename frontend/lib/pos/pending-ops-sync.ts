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
  markOpWaiting,
  opBackoffElapsed,
  peekAllOps,
  OPS_MAX_ATTEMPTS,
} from '@/lib/pos/pending-ops'
import type { PendingOpRow, PendingOpStream } from '@/lib/pos/pending-ops'

/**
 * Error de una operación, con la clasificación que le importa al motor.
 *
 * TRES disposiciones, no dos:
 *
 * - **terminal** (`transient=false`): el servidor dijo que no y va a seguir
 *   diciendo que no. Queda `failed`, visible, para que una persona decida.
 * - **transitorio** (`transient=true`): red o 5xx. Reintento con backoff y tope
 *   de `OPS_MAX_ATTEMPTS`.
 * - **espera** (`waiting=true`): el servidor rechazó por una condición que no
 *   es de esta operación y que se resuelve sola cuando alguien la arregla del
 *   otro lado. No cuenta intentos, no escribe error, no se agota.
 *
 * La tercera existe por la D8 (`context/34-admin-saas-plan.md` §F7): con la
 * cuenta del comercio impaga, el backend responde 403 a TODO, y sin esta
 * disposición ese 403 caía en "terminal" y dejaba ventas y cierres de caja
 * marcados como fallidos con el botón de descartar al lado. La semántica de
 * espera ya existía en el motor para el gate pre-vuelo (`OpGate`); esto es lo
 * que la hace alcanzable también DESPUÉS de la respuesta.
 *
 * `waiting` gana sobre `transient`: una espera no es un reintento con otro
 * nombre, justamente porque no gasta la vida de la operación.
 *
 * Quién clasifica sigue siendo el TRANSPORTE, no el motor: solo quien hizo la
 * request sabe qué pasó. Adivinarlo acá, por el texto del error, es cómo se
 * construye un bucle de reintentos infinito contra un 422.
 */
export class PendingOpError extends Error {
  code: string
  transient: boolean
  waiting: boolean

  constructor(code: string, message: string, transient: boolean, waiting = false) {
    super(message)
    this.name = 'PendingOpError'
    this.code = code
    this.transient = transient
    this.waiting = waiting
  }
}

/**
 * Manda una operación al servidor. Resuelve en éxito, tira `PendingOpError` si
 * no. Lo que resuelva se le pasa tal cual a `onApplied` — es como el cierre de
 * caja recupera el arqueo que el servidor calculó.
 */
export type OpSender = (row: PendingOpRow) => Promise<unknown>

/**
 * ¿Se puede mandar ESTA operación ahora? Distinta pregunta que "¿falló?".
 *
 * Existe por un caso concreto y de plata: el cierre de caja no puede aplicarse
 * antes de que las ventas del turno hayan sincronizado. Si se aplica primero,
 * el servidor cierra el turno sin esas ventas y el arqueo que devuelve está
 * corto — no por un error de nadie, sino por el orden en que drenaron dos colas
 * independientes.
 *
 * Es una ESPERA, no un fallo: frena el canal sin contar un intento y sin dejar
 * marca de error. Contarlo como intento agotaría los reintentos de un cierre
 * que nunca salió a la red y lo dejaría terminal por haber esperado.
 */
export type OpGate = (row: PendingOpRow) => Promise<string | null>

/** Se llama DESPUÉS de que la operación se aplicó, con lo que respondió el servidor. */
export type OpAppliedHook = (row: PendingOpRow, result: unknown) => Promise<void> | void

export interface SyncPendingOpsOptions {
  send: OpSender
  /**
   * Caja del device AHORA. Es el cerco: una operación de otra caja no se
   * aplica. Si viene vacío no se procesa nada — el POS sin caja activa no
   * tiene contexto para escribir (memoria
   * `project_pos_contexto_obligatorio`: nunca inventar la dimensión que falta).
   */
  activeRegisterId: string
  /** Ver `OpGate`. Sin gate, todo lo que esté en cola sale. */
  canSend?: OpGate
  /** Ver `OpAppliedHook`. Lo que tire acá adentro no revierte la operación. */
  onApplied?: OpAppliedHook
  /** Inyectable para los tests. Por defecto, el reloj real. */
  now?: () => number
}

export interface SyncPendingOpsResult {
  synced: number
  failed: number
  retried: number
  /** Canales que quedaron frenados en esta pasada, con el motivo. */
  halted: { stream: PendingOpStream; reason: HaltReason }[]
  /** Motivos de espera, por operación, para poder decirlos en pantalla. */
  waiting: { opId: string; reason: string }[]
}

export type HaltReason =
  | 'failed-head' // adelante hay una operación terminal sin resolver
  | 'backoff' // la próxima todavía está esperando su turno de reintento
  | 'register-changed' // la operación es de otra caja
  | 'waiting' // todavía no corresponde mandarla (ver `OpGate`)
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
  const result: SyncPendingOpsResult = {
    synced: 0,
    failed: 0,
    retried: 0,
    halted: [],
    waiting: [],
  }

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

      // ── Espera ──────────────────────────────────────────────────────────
      // No es un fallo: la operación está bien y todavía no le toca. No se
      // cuentan intentos ni se escribe error — solo se frena el canal, que es
      // lo correcto porque lo que viene detrás depende de esto.
      if (opts.canSend) {
        const wait = await opts.canSend(row)
        if (wait !== null) {
          result.waiting.push({ opId: row.opId, reason: wait })
          result.halted.push({ stream, reason: 'waiting' })
          break
        }
      }

      await markOpSyncing(row.opId)
      try {
        const sendResult = await opts.send(row)
        await markOpSynced(row.opId)
        result.synced += 1
        try {
          await opts.onApplied?.(row, sendResult)
        } catch {
          // La operación YA se aplicó en el servidor y ya salió de la cola.
          // Un problema al anotar su resultado no puede revertir ninguna de
          // las dos cosas ni frenar el canal.
        }
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
        // ── Espera post-respuesta ─────────────────────────────────────────
        // El servidor contestó, pero lo que rechazó no es esta operación: es
        // el estado de la cuenta del comercio (D8, `lib/pos/account-block.ts`).
        // Se trata igual que la espera pre-vuelo del gate — sin contar
        // intento, sin escribir error, frenando el canal — porque contar
        // intentos acá dejaría un cierre de caja en `failed` por el solo hecho
        // de que el comercio tardó en pagar.
        if (opErr.waiting) {
          await markOpWaiting(row.opId)
          result.waiting.push({ opId: row.opId, reason: opErr.message })
          result.halted.push({ stream, reason: 'waiting' })
          break
        }
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
