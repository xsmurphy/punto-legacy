/**
 * El registro que ESTE dispositivo lleva del turno.
 *
 * Qué problema resuelve
 * ─────────────────────
 * Sin red, `GET /api/pos/drawer` no responde y Control de Caja no tiene ningún
 * total para mostrar. La primera versión de esto (context/51 §4) decidió no
 * mostrar ninguno, con un argumento razonable: el device no ve las ventas que
 * llegaron al servidor por otro camino, y un total corto en una pantalla de
 * arqueo hace que un faltante parezca cuadrar.
 *
 * Lo que cambió es el piso sobre el que apoya ese argumento. La tenencia de
 * caja es EXCLUSIVA y está implementada (mig 141/143, `register_lease`, y el
 * grant local con TTL de `register-tenancy.ts`): mientras este device tiene la
 * caja, ningún otro puede emitir en ella. Con eso, sus ventas del turno SON el
 * turno, salvo por huecos acotados —y los huecos que quedan se pueden detectar
 * y decir en pantalla en vez de callar el número entero. Ver
 * `local-shift-total.ts`, que es donde se calcula y se declaran los huecos.
 *
 * Por qué un registro propio y no las colas
 * ─────────────────────────────────────────
 * Una venta sale de `pendingSales` en cuanto sincroniza, y una venta hecha con
 * red nunca pasa por ahí. Un total leído de la cola solo vería lo que todavía
 * no se envió: bajaría a medida que vuelve la conexión, que es exactamente lo
 * que no puede hacer un número de arqueo. El journal, en cambio, anota el hecho
 * cuando ocurre y no lo borra cuando viaja.
 *
 * Reglas de este módulo
 * ─────────────────────
 * 1. **Anotar nunca puede romper lo que se está anotando.** Todo escribe
 *    best-effort: si IndexedDB no está (modo privado, cuota llena), la venta
 *    se emite igual y lo que se pierde es el total, no el comprobante.
 * 2. **Idempotente por `entryId`.** La clave es el `uid` de la venta o el id
 *    de la operación; anotar dos veces el mismo hecho no lo suma dos veces.
 * 3. **La poda es por turno.** Al abrir un turno se borra lo anterior: el
 *    journal responde "¿qué pasó en el turno en curso?", no lleva historia.
 */

import { getPosOfflineDB } from '@/lib/pos/offline-db'
import type {
  ShiftJournalKind,
  ShiftJournalPayment,
  ShiftJournalRow,
} from '@/lib/pos/offline-db'

export type { ShiftJournalKind, ShiftJournalPayment, ShiftJournalRow }

/**
 * Desde cuándo el journal viene anotando en esta caja. Vive en `snapshots`
 * porque es metadato del registro, no un hecho del turno.
 *
 * Importa para el honesto: un device que empezó a anotar a mitad del turno
 * (porque se actualizó la app, porque se le limpió la base) no puede presentar
 * su suma como si cubriera el turno entero.
 */
const sinceKey = (registerId: string) => `shift-journal:since:${registerId}`

/** Techo de antigüedad, por si un turno queda abierto para siempre en una caja olvidada. */
const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000

// ── Escritura ─────────────────────────────────────────────────────────────────

async function putEntry(row: ShiftJournalRow): Promise<void> {
  if (typeof indexedDB === 'undefined') return
  try {
    const db = await getPosOfflineDB()
    await db.put('shiftJournal', row)
    const existing = await db.get('snapshots', sinceKey(row.registerId))
    if (!existing) {
      await db.put('snapshots', {
        key: sinceKey(row.registerId),
        savedAt: new Date().toISOString(),
        payload: { since: row.date },
      })
    }
  } catch {
    // Ver regla 1 del docblock: anotar es una mejora del total, nunca un
    // requisito de la operación que se está anotando.
  }
}

/**
 * Anota una venta emitida por esta caja. Se llama en el ÚNICO lugar donde una
 * venta nace (`PayDialog`), en las dos ramas —la que posteó con red y la que
 * encoló sin ella—, porque para el arqueo son el mismo hecho.
 */
export async function recordSale(input: {
  registerId: string
  /** `uid` de la venta: la misma identidad que deduplica server-side. */
  uid: string
  /** Hora local del tenant, naive — la del momento en que se cobró. */
  date: string
  payments: ShiftJournalPayment[]
  internal?: boolean
}): Promise<void> {
  if (input.registerId === '' || input.uid === '') return
  const amount = input.payments.reduce((sum, p) => sum + (p.total || 0), 0)
  await putEntry({
    entryId: input.uid,
    registerId: input.registerId,
    kind: 'sale',
    date: input.date,
    amount,
    payments: input.payments,
    internal: input.internal === true,
    createdAt: new Date().toISOString(),
  })
}

/**
 * Anota un movimiento de caja (apertura, cierre, extracción, ingreso).
 *
 * La apertura, además, PODA: un turno nuevo empieza con el journal limpio de
 * lo anterior. Es lo que mantiene el store chico sin necesidad de un barrido
 * aparte, y lo que garantiza que el total del turno no arrastre el de ayer.
 */
export async function recordDrawerOp(input: {
  registerId: string
  entryId: string
  kind: Exclude<ShiftJournalKind, 'sale'>
  date: string
  amount: number
}): Promise<void> {
  if (input.registerId === '' || input.entryId === '') return
  if (input.kind === 'drawerOpen') {
    await pruneBefore(input.registerId, input.date)
  }
  await putEntry({
    entryId: input.entryId,
    registerId: input.registerId,
    kind: input.kind,
    date: input.date,
    amount: input.amount,
    createdAt: new Date().toISOString(),
  })
}

/**
 * Borra lo anotado ANTES de `date` en esta caja y reencuadra el `since`: a
 * partir de una apertura, el journal cubre el turno nuevo desde su primer
 * segundo, y eso deja de ser un hueco que declarar.
 */
export async function pruneBefore(registerId: string, date: string): Promise<void> {
  if (typeof indexedDB === 'undefined') return
  try {
    const db = await getPosOfflineDB()
    const all = await db.getAll('shiftJournal')
    const cutoffMs = Date.now() - MAX_AGE_MS
    for (const row of all) {
      const tooOld = new Date(row.createdAt).getTime() < cutoffMs
      const beforeShift = row.registerId === registerId && row.date < date
      if (tooOld || beforeShift) {
        await db.delete('shiftJournal', row.entryId)
      }
    }
    await db.put('snapshots', {
      key: sinceKey(registerId),
      savedAt: new Date().toISOString(),
      payload: { since: date },
    })
  } catch {
    /* ver regla 1 */
  }
}

// ── Lectura ───────────────────────────────────────────────────────────────────

/** Todo lo anotado para una caja, ordenado por el momento en que se operó. */
export async function readShiftJournal(registerId: string): Promise<ShiftJournalRow[]> {
  if (typeof indexedDB === 'undefined' || registerId === '') return []
  try {
    const db = await getPosOfflineDB()
    const all = await db.getAll('shiftJournal')
    return all
      .filter((r) => r.registerId === registerId)
      .sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0))
  } catch {
    return []
  }
}

/** Desde cuándo hay registro en esta caja (naive tenant-local), o `null`. */
export async function journalSince(registerId: string): Promise<string | null> {
  if (typeof indexedDB === 'undefined' || registerId === '') return null
  try {
    const db = await getPosOfflineDB()
    const row = await db.get('snapshots', sinceKey(registerId))
    const since = (row?.payload as { since?: string } | undefined)?.since
    return since ?? null
  } catch {
    return null
  }
}
