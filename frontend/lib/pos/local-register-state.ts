/**
 * La vista LOCAL de lo que la caja tiene configurado y de si está abierta.
 *
 * El problema que resuelve
 * ───────────────────────
 * Sin red, `GET /api/pos/register-config` falla y la pantalla de Ajustes se
 * dibuja con los defaults del código: el cajero ve interruptores que no son los
 * suyos, y el bug que el owner reportó (los selectores de Sucursal y Caja en
 * blanco) es la versión más visible del mismo problema. Lo mismo con el estado
 * de la caja: `GET /api/pos/drawer?check=1` falla y la caja aparece cerrada
 * aunque el turno esté abierto desde la mañana.
 *
 * La fórmula
 * ──────────
 *     vista local = última verdad del servidor  +  lo que esta caja cambió y
 *                                                  todavía no pudo mandar
 *
 * Las dos mitades importan. Sin la primera no hay nada que mostrar tras un
 * reinicio; sin la segunda, el primer refetch que entre le revierte al cajero,
 * en pantalla, el interruptor que acaba de tocar — y eso se lee como "el
 * ajuste no anda", que es exactamente lo que se está arreglando.
 *
 * Dónde vive
 * ──────────
 * En el store `snapshots` de la IndexedDB del POS, con una clave por recurso y
 * caja. Es el mismo lugar donde ya vive el snapshot del bootstrap: datos
 * derivados del servidor, que se purgan cuando el device se desvincula. Lo que
 * NO es derivado —las operaciones que el cajero hizo y nadie recibió todavía—
 * vive en `pendingOps`, que sobrevive a la purga.
 */

import { getPosOfflineDB } from '@/lib/pos/offline-db'
import { pendingOpsOfKind } from '@/lib/pos/pending-ops'
import type { PendingOpRow } from '@/lib/pos/pending-ops'
import type { PosRegisterConfig } from '@/hooks/use-pos-config'
import type { Hotkey } from '@/lib/hotkeys/store'
import type { PrinterBinding } from '@/lib/hardware/printers/binding'

// ── Payloads de cada operación ────────────────────────────────────────────────
// Viven acá, con quien los aplica localmente, y los consume el transporte. El
// shape es el del body que espera el endpoint, sin traducción intermedia.

/**
 * PATCH de ajustes: SOLO las claves que el cajero tocó.
 *
 * Que sea un patch y no la config entera es la regla de conflicto, escrita en
 * el tipo: al sincronizar, el servidor mergea estas claves sobre lo que haya
 * guardado, así que un cambio hecho desde el panel en OTRA clave sobrevive.
 * Ver `context/51`.
 */
export type PosConfigPatch = Partial<PosRegisterConfig>

export interface HotkeysPayload {
  hotkeys: Hotkey[]
}

export interface DrawerOpPayload {
  amount: number
  /** Hora LOCAL del tenant, naive, del momento en que el cajero operó. */
  date: string
  note?: string
}

export interface PrinterBindingCreatePayload {
  registerId: string
  /**
   * La impresora completa, CON su `id` ya puesto: es un UUID que genera el
   * cliente, no el servidor. Eso es lo que permite editarla o borrarla antes
   * de que sincronice, y lo que vuelve idempotente el alta (ver
   * `pending-ops-transport.ts`).
   */
  binding: Omit<PrinterBinding, 'createdAt' | 'updatedAt'>
}

export interface PrinterBindingUpdatePayload {
  id: string
  patch: Partial<Omit<PrinterBinding, 'id' | 'createdAt' | 'updatedAt'>>
}

export interface PrinterBindingDeletePayload {
  id: string
}

// ── Claves del store `snapshots` ──────────────────────────────────────────────

const configKey = (registerId: string) => `pos-config:${registerId}`
const drawerKey = (registerId: string) => `drawer-state:${registerId}`
const bindingsKey = (registerId: string) => `printer-bindings:${registerId}`

/**
 * Toda escritura del cache local es best-effort, igual que el snapshot del
 * bootstrap: guardar es una mejora del PRÓXIMO arranque, nunca un requisito
 * del actual. Si IndexedDB no está (modo privado, cuota llena), la sesión
 * online tiene que seguir andando.
 */
async function putSnapshot(key: string, payload: unknown): Promise<void> {
  if (typeof indexedDB === 'undefined') return
  try {
    const db = await getPosOfflineDB()
    await db.put('snapshots', { key, savedAt: new Date().toISOString(), payload })
  } catch {
    /* ver docblock */
  }
}

async function getSnapshot<T>(key: string): Promise<T | null> {
  if (typeof indexedDB === 'undefined') return null
  try {
    const db = await getPosOfflineDB()
    const row = await db.get('snapshots', key)
    return row ? (row.payload as T) : null
  } catch {
    return null
  }
}

// ── Ajustes de la caja ────────────────────────────────────────────────────────

export async function saveLocalRegisterConfig(
  registerId: string,
  config: PosRegisterConfig,
): Promise<void> {
  if (registerId === '') return
  await putSnapshot(configKey(registerId), config)
}

export async function loadLocalRegisterConfig(
  registerId: string,
): Promise<PosRegisterConfig | null> {
  if (registerId === '') return null
  return getSnapshot<PosRegisterConfig>(configKey(registerId))
}

/**
 * Aplica sobre `base` los patches de ajustes que todavía no llegaron al
 * servidor, en el orden en que el cajero los hizo.
 *
 * Se usa en las DOS direcciones: sobre la respuesta fresca del servidor (para
 * que un refetch no revierta lo que está en cola) y sobre el cache local (para
 * que un reinicio sin red muestre lo último que el cajero eligió).
 */
export async function applyPendingConfigPatches(
  registerId: string,
  base: PosRegisterConfig,
): Promise<PosRegisterConfig> {
  const ops = await pendingOpsOfKind('posConfig', registerId)
  return ops.reduce<PosRegisterConfig>(
    (acc, op) => ({ ...acc, ...(op.payload as PosConfigPatch) }),
    base,
  )
}

// ── Hotkeys ───────────────────────────────────────────────────────────────────

/**
 * La grilla que el cajero editó sin red, si hay alguna en cola.
 *
 * A diferencia de los ajustes, los hotkeys se guardan como grilla ENTERA (el
 * endpoint no tiene forma parcial), así que la última encolada gana sobre lo
 * que responda el servidor. Se justifica en `context/51`: la grilla se edita
 * únicamente desde el POS, y un merge por slot armaría una disposición que
 * nadie diseñó.
 */
export async function pendingHotkeys(registerId: string): Promise<Hotkey[] | null> {
  const ops = await pendingOpsOfKind('hotkeys', registerId)
  const last = ops[ops.length - 1]
  return last ? (last.payload as HotkeysPayload).hotkeys : null
}

// ── Estado de la caja (abierta / cerrada) ─────────────────────────────────────

export interface LocalDrawerState {
  isOpen: boolean
  /** Fecha de apertura del turno en curso (naive tenant-local). */
  openDate: string | null
  openAmount: number
}

export const CLOSED_DRAWER: LocalDrawerState = { isOpen: false, openDate: null, openAmount: 0 }

export async function saveLocalDrawerState(
  registerId: string,
  state: LocalDrawerState,
): Promise<void> {
  if (registerId === '') return
  await putSnapshot(drawerKey(registerId), state)
}

export async function loadLocalDrawerState(
  registerId: string,
): Promise<LocalDrawerState | null> {
  if (registerId === '') return null
  return getSnapshot<LocalDrawerState>(drawerKey(registerId))
}

/**
 * Aplica las operaciones de caja en cola sobre un estado base.
 *
 * Exportada y pura para poder probarla: la secuencia "abrí, cerré, volví a
 * abrir, todo sin red" tiene que dar caja ABIERTA, y esa es exactamente la
 * clase de cosa que un rollup escrito a ojo se equivoca.
 *
 * Las extracciones e ingresos no cambian si la caja está abierta — mueven
 * plata dentro del turno.
 */
export function applyDrawerOps(
  base: LocalDrawerState,
  ops: PendingOpRow[],
): LocalDrawerState {
  return ops.reduce<LocalDrawerState>((acc, op) => {
    const payload = op.payload as DrawerOpPayload
    if (op.kind === 'drawerOpen') {
      return { isOpen: true, openDate: payload.date, openAmount: payload.amount }
    }
    if (op.kind === 'drawerClose') {
      return { ...CLOSED_DRAWER }
    }
    return acc
  }, base)
}

/**
 * Estado de caja que la UI debe mostrar: la verdad del servidor (o el último
 * cache, sin red) con las operaciones en cola aplicadas encima.
 */
export async function resolveDrawerState(
  registerId: string,
  serverState: LocalDrawerState | null,
): Promise<LocalDrawerState> {
  const base = serverState ?? (await loadLocalDrawerState(registerId)) ?? CLOSED_DRAWER
  const ops = [
    ...(await pendingOpsOfKind('drawerOpen', registerId)),
    ...(await pendingOpsOfKind('drawerClose', registerId)),
  ].sort((a, b) => a.seq - b.seq)
  return applyDrawerOps(base, ops)
}

// ── Impresoras (bindings por caja) ────────────────────────────────────────────

export async function saveLocalPrinterBindings(
  registerId: string,
  bindings: PrinterBinding[],
): Promise<void> {
  if (registerId === '') return
  await putSnapshot(bindingsKey(registerId), bindings)
}

export async function loadLocalPrinterBindings(
  registerId: string,
): Promise<PrinterBinding[] | null> {
  if (registerId === '') return null
  return getSnapshot<PrinterBinding[]>(bindingsKey(registerId))
}

/**
 * Aplica altas, ediciones y bajas de impresoras en cola sobre la lista base.
 *
 * El alta puede hacerlo el cliente porque el `id` lo genera el cliente (UUID),
 * no el servidor: la impresora que el cajero acaba de crear tiene desde el
 * primer momento el id definitivo, así que editarla o borrarla antes de que
 * sincronice funciona igual que si ya existiera. Ver `pending-ops-transport.ts`
 * para por qué eso además la vuelve idempotente.
 */
export function applyBindingOps(
  base: PrinterBinding[],
  ops: PendingOpRow[],
): PrinterBinding[] {
  return ops.reduce<PrinterBinding[]>((acc, op) => {
    if (op.kind === 'printerBindingCreate') {
      const { binding } = op.payload as PrinterBindingCreatePayload
      const row = { ...binding, createdAt: op.createdAt } as PrinterBinding
      return acc.some((b) => b.id === row.id) ? acc : [...acc, row]
    }
    if (op.kind === 'printerBindingUpdate') {
      const { id, patch } = op.payload as PrinterBindingUpdatePayload
      return acc.map((b) => (b.id === id ? { ...b, ...patch } : b))
    }
    if (op.kind === 'printerBindingDelete') {
      const { id } = op.payload as PrinterBindingDeletePayload
      return acc.filter((b) => b.id !== id)
    }
    return acc
  }, base)
}

/** Lista de impresoras que la UI debe mostrar, con lo pendiente ya aplicado. */
export async function resolvePrinterBindings(
  registerId: string,
  serverBindings: PrinterBinding[] | null,
): Promise<PrinterBinding[]> {
  const base = serverBindings ?? (await loadLocalPrinterBindings(registerId)) ?? []
  const ops = [
    ...(await pendingOpsOfKind('printerBindingCreate', registerId)),
    ...(await pendingOpsOfKind('printerBindingUpdate', registerId)),
    ...(await pendingOpsOfKind('printerBindingDelete', registerId)),
  ].sort((a, b) => a.seq - b.seq)
  return applyBindingOps(base, ops)
}
