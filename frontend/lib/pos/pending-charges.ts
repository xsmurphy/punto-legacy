/**
 * Cobros ONLINE-ONLY con resultado ambiguo — "¿esto ya se cobró?".
 *
 * ── El hueco que cierra ─────────────────────────────────────────────────────
 *
 * El cobro de un espacio, de una orden o un cobro parcial NO se encola offline:
 * necesita al servidor en el momento (cierra la sesión, marca la orden, registra
 * el pago en la cuenta del espacio). Cuando ese POST termina en timeout, caída de
 * red o 5xx, el POS no sabe si la venta quedó registrada. Antes el uid de la
 * venta vivía atado a la APERTURA del diálogo: si el cajero cerraba y volvía a
 * abrir el cobro salía un uid nuevo, y si el primer intento SÍ había llegado se
 * emitía una segunda venta — y una segunda factura ante SIFEN.
 *
 * ── El modelo ───────────────────────────────────────────────────────────────
 *
 * El uid pasa a estar atado al COBRO PENDIENTE, no al diálogo. Tras un resultado
 * ambiguo se persiste {objeto cobrado, uid, payload, qué hacer después} en la
 * IndexedDB del POS (sobrevive al cierre del diálogo y a recargar la página).
 * Antes de emitir otro cobro sobre ese objeto se RESUELVE consultando al
 * servidor por uid (`GET /v1/sales?uid=`) — mismo patrón "reconsultar antes de
 * reintentar" que ya usa la emisión de FE-PY:
 *
 *   - registrada     → se muestra ESA venta como éxito; no se vuelve a cobrar.
 *   - no registrada  → se reintenta con el MISMO uid (la unicidad del servidor
 *                      ataja una carrera con el intento original aún en vuelo).
 *   - sin respuesta  → NO se deja emitir: sin saber, un segundo cobro podría ser
 *                      un cobro duplicado.
 *
 * Si el carrito cambió entre intentos, gana la venta ya registrada: se muestra
 * esa y nunca se emite una segunda en silencio. Si no estaba registrada, el
 * reintento lleva el carrito actual con el mismo uid.
 *
 * La venta SIMPLE no pasa por acá: ante el mismo resultado ambiguo se encola
 * offline con su uid y la cola ya es idempotente por uid.
 */

import { getPosOfflineDB } from '@/lib/pos/offline-db'
import type {
  ChargeFollowups,
  ChargeTarget,
  PendingChargeRow,
  RegisteredSale,
} from '@/lib/pos/offline-db'
import type { CreateSalePayload } from '@/lib/commands/create-sale'
import type { SettlementIntent } from '@/lib/cart/store'

export type { ChargeFollowups, ChargeTarget, PendingChargeRow, RegisteredSale }

/**
 * Un intento sin registro pasado este tiempo desde el último intento ambiguo
 * ya no puede estar en vuelo en el servidor: se descarta y el próximo cobro
 * sale con uid nuevo. Muy por encima de cualquier timeout de request del backend.
 */
export const UNRESOLVED_TTL_MS = 15 * 60_000

/**
 * Un cobro que se encontró registrado y nadie volvió a abrir (el objeto se cerró
 * desde otra caja, o nunca se retomó) se poda pasado este tiempo.
 */
export const RESOLVED_TTL_MS = 24 * 60 * 60_000

/**
 * Consulta al servidor por uid. `null` = el servidor respondió que NO existe
 * (404). Cualquier otra cosa —red, 5xx, 401— TIRA: "no pude saber" no es
 * "no existe".
 */
export type SaleLookup = (uid: string) => Promise<RegisteredSale | null>

export type PendingChargeResolution =
  | { kind: 'none' }
  | { kind: 'registered'; row: PendingChargeRow; sale: RegisteredSale }
  | { kind: 'not-registered'; row: PendingChargeRow }
  | { kind: 'unverifiable'; row: PendingChargeRow }

/** Objeto cobrado a partir del estado del carrito, o `null` si es venta simple. */
export function chargeTargetFor(cart: {
  settlementIntent: SettlementIntent | null
  sessionParentId: string | null
  orderParentId: string | null
}): ChargeTarget | null {
  if (cart.settlementIntent) {
    return { kind: 'space-settlement', sessionId: cart.settlementIntent.sessionId }
  }
  if (cart.sessionParentId) return { kind: 'space-session', sessionId: cart.sessionParentId }
  if (cart.orderParentId) return { kind: 'order', orderId: cart.orderParentId }
  return null
}

/**
 * Clave del cobro pendiente. El cobro parcial y el total de una MISMA sesión
 * comparten clave a propósito: son cobros sobre el mismo saldo, y uno pendiente
 * tiene que resolverse antes de cobrar cualquier otra parte.
 */
export function chargeKey(target: ChargeTarget): string {
  switch (target.kind) {
    case 'space-settlement':
    case 'space-session':
      return `space:${target.sessionId}`
    case 'order':
      return `order:${target.orderId}`
  }
}

function isIdbAvailable(): boolean {
  return typeof indexedDB !== 'undefined'
}

/**
 * Registra (o refresca) el cobro pendiente tras un resultado ambiguo. Conserva
 * `createdAt` si ya existía y NUNCA cambia el uid de un pendiente vivo: el uid
 * es la identidad del cobro mientras no se sepa qué pasó con él.
 */
export async function recordAmbiguousCharge(input: {
  target: ChargeTarget
  uid: string
  payload: CreateSalePayload
  followups: ChargeFollowups
  now?: number
}): Promise<void> {
  const db = await getPosOfflineDB()
  const key = chargeKey(input.target)
  const nowIso = new Date(input.now ?? Date.now()).toISOString()
  const prev = await db.get('pendingCharges', key)
  if (prev && prev.uid !== input.uid) {
    // Defensa: el llamador siempre reusa el uid del pendiente. Si llega otro,
    // se conserva el viejo — pisarlo perdería el rastro del intento original.
    console.error('[pending-charges] uid distinto sobre un cobro pendiente vivo; se conserva el original', key)
    return
  }
  await db.put('pendingCharges', {
    key,
    target: input.target,
    uid: input.uid,
    payload: input.payload,
    followups: input.followups,
    createdAt: prev?.createdAt ?? nowIso,
    lastAttemptAt: nowIso,
  })
}

export async function getPendingCharge(target: ChargeTarget): Promise<PendingChargeRow | undefined> {
  if (!isIdbAvailable()) return undefined
  const db = await getPosOfflineDB()
  return db.get('pendingCharges', chargeKey(target))
}

/** El cobro quedó resuelto (venta confirmada y mostrada): fuera de la lista. */
export async function clearPendingCharge(target: ChargeTarget): Promise<void> {
  if (!isIdbAvailable()) return
  const db = await getPosOfflineDB()
  await db.delete('pendingCharges', chargeKey(target))
}

/**
 * Resuelve el cobro pendiente de un objeto ANTES de emitir otro sobre él.
 *
 * Una fila ya encontrada registrada (por el diálogo o por el sync) se resuelve
 * sin red: la venta existe y eso no cambia.
 */
export async function resolvePendingCharge(
  target: ChargeTarget,
  lookup: SaleLookup,
): Promise<PendingChargeResolution> {
  const row = await getPendingCharge(target)
  if (!row) return { kind: 'none' }
  if (row.resolvedSale) return { kind: 'registered', row, sale: row.resolvedSale }

  let sale: RegisteredSale | null
  try {
    sale = await lookup(row.uid)
  } catch {
    return { kind: 'unverifiable', row }
  }
  if (!sale) return { kind: 'not-registered', row }

  const resolved: PendingChargeRow = { ...row, resolvedSale: sale, resolvedAt: new Date().toISOString() }
  const db = await getPosOfflineDB()
  await db.put('pendingCharges', resolved)
  return { kind: 'registered', row: resolved, sale }
}

/**
 * Qué hacer con un cobro online-only sobre `target`, decidido ANTES de emitir.
 *
 *   - `emit`            → cobrar con `uid`: el del pendiente si lo había y no
 *                         estaba registrado (mismo uid = la unicidad del
 *                         servidor ataja el intento original si seguía en
 *                         vuelo), o `freshUid` si no había nada pendiente.
 *   - `show-registered` → la venta ya existe: mostrarla, NO cobrar.
 *   - `block`           → no se pudo saber: NO cobrar.
 *
 * `onVerifying` avisa cuando hay que salir a preguntarle al servidor, para que
 * la UI muestre el impedimento mientras tanto.
 */
export type ChargeAttemptPlan =
  | { action: 'emit'; uid: string }
  | { action: 'show-registered'; row: PendingChargeRow; sale: RegisteredSale }
  | { action: 'block' }

export async function planChargeAttempt(
  target: ChargeTarget,
  lookup: SaleLookup,
  freshUid: string,
  onVerifying?: (pendingUid: string) => void,
): Promise<ChargeAttemptPlan> {
  const pending = await getPendingCharge(target)
  if (!pending) return { action: 'emit', uid: freshUid }
  if (!pending.resolvedSale) onVerifying?.(pending.uid)

  const resolution = await resolvePendingCharge(target, lookup)
  switch (resolution.kind) {
    case 'none':
      return { action: 'emit', uid: freshUid }
    case 'not-registered':
      return { action: 'emit', uid: resolution.row.uid }
    case 'registered':
      return { action: 'show-registered', row: resolution.row, sale: resolution.sale }
    case 'unverifiable':
      return { action: 'block' }
  }
}

/**
 * Pasada del loop de sync: que ningún pendiente quede huérfano.
 *
 *   - sin resolver → se consulta; si está registrado se anota la venta (quien
 *     reabra ese cobro ve el éxito aunque no tenga red); si no lo está y pasó
 *     `UNRESOLVED_TTL_MS` desde el último intento, se descarta.
 *   - registrado y sin reabrir por `RESOLVED_TTL_MS` → se poda.
 *
 * NO corre los pasos posteriores (cerrar el espacio, marcar la orden): eso lo
 * hace el diálogo al mostrar la venta, que es donde el cajero lo ve. Y NUNCA
 * borra un pendiente registrado antes de tiempo: un diálogo abierto sobre ese
 * objeto que no lo encontrara emitiría un cobro nuevo.
 */
export async function reconcilePendingCharges(lookup: SaleLookup, now: number = Date.now()): Promise<void> {
  if (!isIdbAvailable()) return
  const db = await getPosOfflineDB()
  const rows = await db.getAll('pendingCharges')
  for (const row of rows) {
    if (row.resolvedSale) {
      const since = Date.parse(row.resolvedAt ?? row.lastAttemptAt)
      if (now - since >= RESOLVED_TTL_MS) await db.delete('pendingCharges', row.key)
      continue
    }
    let sale: RegisteredSale | null
    try {
      sale = await lookup(row.uid)
    } catch {
      continue // sin respuesta: se reintenta en la próxima pasada
    }
    // La consulta tarda: mientras tanto el diálogo pudo resolver, limpiar o
    // volver a intentar ESTE cobro. Se escribe solo si la fila sigue siendo la
    // misma que se consultó — escribir la copia vieja resucitaría un pendiente
    // ya limpiado y el próximo cobro de ese objeto mostraría una venta ajena.
    const current = await db.get('pendingCharges', row.key)
    if (!current || current.uid !== row.uid || current.lastAttemptAt !== row.lastAttemptAt || current.resolvedSale) {
      continue
    }
    if (sale) {
      await db.put('pendingCharges', { ...current, resolvedSale: sale, resolvedAt: new Date(now).toISOString() })
    } else if (now - Date.parse(current.lastAttemptAt) >= UNRESOLVED_TTL_MS) {
      await db.delete('pendingCharges', row.key)
    }
  }
}
