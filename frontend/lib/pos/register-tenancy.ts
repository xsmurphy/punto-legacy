/**
 * Tenencia de caja del lado del DEVICE — el derecho a emitir, sabido sin red.
 *
 * El incidente (owner, 2026-08-23)
 * ────────────────────────────────
 * Un cajero vendió sin conexión, imprimió el ticket, el cliente se fue; al
 * volver la red el sync rechazó la venta con "la caja fue liberada, tomada por
 * otro dispositivo, o cerrada". La caja ya estaba tomada por otro device ANTES
 * de esa venta, y el POS lo dejó vender igual porque offline no verificaba
 * nada: el único gate vivía en el 409 del POST online (`PayDialog`), y sin red
 * no hay POST. Un documento emitido que el sistema después repudia es lo peor
 * que puede pasar en una caja.
 *
 * El principio (context/08 §53, memoria del proyecto): lo que se EMITE
 * funciona sin internet. Pero emitir con una caja que no es mía no es "operar
 * offline" — es emitir un documento inválido. La regla correcta es que el
 * device sepa ANTES de vender si tiene derecho, no que lo descubra al
 * sincronizar.
 *
 * El modelo
 * ─────────
 * La tenencia se resuelve ONLINE (`/v1/register/claim`, `register_lease`,
 * context/29 §4) y se PERSISTE localmente con su hora de confirmación: un
 * "grant". El device no consulta al servidor para decidir si puede vender —
 * consulta lo último que el servidor le dijo. Tres veredictos:
 *
 *   - `held` y fresco  → puede emitir, con o sin conexión.
 *   - `denied`         → NO puede emitir, aunque esté offline. Este es
 *                        exactamente el caso del incidente: el claim del
 *                        arranque trajo un 409, y ahora eso sí bloquea.
 *   - ausente o vencido→ NO puede emitir. Sin confirmación no hay derecho.
 *
 * Vigencia (TTL): 12 horas
 * ────────────────────────
 * La tenencia server-side NO vence (context/29 §4) — lo que vence acá es la
 * CONFIANZA del device en una afirmación que no puede reverificar. El TTL
 * corre contra `confirmedAt`, que se renueva en cada latido con red
 * (`HEARTBEAT_MS`), así que en operación normal nunca llega a correr: solo
 * avanza durante un corte.
 *
 * Por qué 12 h y no menos: tiene que cubrir un corte de internet que dure toda
 * la jornada sin dejar la caja sin vender — ese es el compromiso offline-first
 * y un TTL de minutos u horas lo rompería. Por qué no más: 12 h significa "en
 * algún momento de hoy este device habló con el servidor". Una tablet que
 * estuvo días apagada, o que se mudó de local, no puede despertarse y empezar
 * a emitir bajo una tenencia que nadie verificó desde entonces — y el único
 * modo de perder la tenencia es una acción explícita de admin, que el admin ve
 * y decide, así que 12 h de exposición es una ventana chica y acotada.
 *
 * Lo que este modelo NO puede prevenir
 * ────────────────────────────────────
 * El device tenía la caja, se fue sin red, y MIENTRAS TANTO un admin se la
 * quitó desde el panel. Sin red no hay forma de enterarse: ese conflicto es
 * inevitable, y el diseño lo asume. Lo que sí garantiza es que la venta no se
 * pierda — la caja queda LIBRE (nadie más la tomó), así que el device la
 * vuelve a tomar y la venta encolada se sincroniza sola con el mismo número
 * (`REGISTER_RELEASED`, ver `RegisterLeaseService::conflictMessage()` y
 * `use-offline-sync.ts`). El único desenlace realmente terminal es que OTRO
 * device ya la haya tomado, y ahí la venta espera en la cola con el nombre de
 * quien la tiene y qué hacer, nunca se descarta sola.
 *
 * Estando ONLINE la pérdida de tenencia no espera al TTL ni al próximo latido:
 * `use-realtime-sync.ts` escucha la entity `register-lease` y fuerza un
 * `refreshTenancy()` en el momento.
 */

import { getPosOfflineDB } from '@/lib/pos/offline-db'
import type { TenancyDenyReason, TenancyGrantRow } from '@/lib/pos/offline-db'
import { posApi } from '@/lib/api/pos-client'
import { ApiError } from '@/lib/api-client'
import { extractRegisterConflictInfo } from '@/lib/pos/register-conflict'
import { useTenancyStore } from '@/lib/pos/tenancy-store'

export type { TenancyDenyReason }

/** Clave única del grant dentro del store `tenancy`. */
const TENANCY_KEY = 'register-tenancy'

/** Ver "Vigencia (TTL)" en el docblock del módulo — 12 horas. */
export const TENANCY_TTL_MS = 12 * 60 * 60 * 1000

/**
 * Cada cuánto se re-confirma la tenencia con el servidor. `claim.php` es
 * idempotente (200 sin tocar nada si este device ya es el tenedor), así que un
 * latido es una lectura barata bajo advisory lock. 5 minutos mantiene
 * `confirmedAt` tan fresco que el TTL solo empieza a correr cuando la red se
 * cae de verdad.
 */
export const HEARTBEAT_MS = 5 * 60 * 1000

/**
 * Por qué el veredicto no es un booleano: cada motivo tiene un remedio
 * distinto y el cajero necesita saber cuál le tocó.
 *
 *   - `ok`          — puede emitir.
 *   - `never`       — este device nunca confirmó tenencia sobre esta caja
 *                     (arranque offline sin haber hecho claim jamás, o device
 *                     recién pareado). Remedio: conectarse.
 *   - `denied`      — el servidor dijo que NO: otro device la tiene.
 *                     Remedio: que la liberen.
 *   - `stale`       — la tuvo, pero la confirmación venció (>12 h sin red).
 *                     Remedio: conectarse.
 *   - `other-register` — el grant guardado es de OTRA caja (el device se
 *                     movió de caja sin volver a confirmar). Remedio:
 *                     conectarse.
 */
export type TenancyVerdictKind =
  | 'ok'
  | 'never'
  | 'denied'
  | 'stale'
  | 'other-register'

export interface TenancyVerdict {
  kind: TenancyVerdictKind
  /** Único campo que el call-site necesita para decidir si deja emitir. */
  canIssue: boolean
  holderDeviceId: string | null
  holderDeviceName: string | null
  /** Motivo server-side del `denied`, cuando lo hay. */
  denyReason: TenancyDenyReason | null
  /** ISO de la última confirmación conocida, para poder decir desde cuándo. */
  confirmedAt: string | null
}

const NO_GRANT: TenancyVerdict = {
  kind: 'never',
  canIssue: false,
  holderDeviceId: null,
  holderDeviceName: null,
  denyReason: null,
  confirmedAt: null,
}

/**
 * Traduce un grant persistido a veredicto. Función pura: toda la política de
 * vigencia vive acá y se puede testear sin IndexedDB ni red.
 */
export function evaluateGrant(
  grant: TenancyGrantRow | null,
  registerId: string,
  now: number = Date.now(),
): TenancyVerdict {
  if (!grant) return NO_GRANT

  const base = {
    holderDeviceId: grant.holderDeviceId,
    holderDeviceName: grant.holderDeviceName,
    denyReason: grant.denyReason,
    confirmedAt: grant.confirmedAt,
  }

  // Un grant de otra caja no dice NADA sobre ésta. Pasa cuando el device se
  // reasigna de caja (`/v1/active-register`) y todavía no volvió a confirmar.
  if (grant.registerId !== registerId) {
    return { ...base, kind: 'other-register', canIssue: false }
  }

  if (grant.status === 'denied') {
    return { ...base, kind: 'denied', canIssue: false }
  }

  // Reloj del device: puede estar corrido. Una `confirmedAt` en el FUTURO se
  // trata como vencida en vez de como eternamente fresca — el modo de fallo
  // seguro es no dejar emitir, no dejar emitir para siempre.
  const confirmed = new Date(grant.confirmedAt).getTime()
  if (Number.isNaN(confirmed) || confirmed > now || now - confirmed > TENANCY_TTL_MS) {
    return { ...base, kind: 'stale', canIssue: false }
  }

  return { ...base, kind: 'ok', canIssue: true }
}

// ── Persistencia ──────────────────────────────────────────────────────────────

async function readGrant(): Promise<TenancyGrantRow | null> {
  if (typeof indexedDB === 'undefined') return null
  try {
    const db = await getPosOfflineDB()
    return (await db.get('tenancy', TENANCY_KEY)) ?? null
  } catch {
    // Base inaccesible (modo privado, cuota, corrupción). Sin grant legible no
    // hay derecho demostrable: el veredicto cae a `never` y la caja no emite.
    // Fail-closed a propósito — el riesgo del otro lado es un comprobante
    // duplicado.
    return null
  }
}

async function writeGrant(row: TenancyGrantRow): Promise<void> {
  if (typeof indexedDB === 'undefined') return
  try {
    const db = await getPosOfflineDB()
    await db.put('tenancy', row)
  } catch {
    // No poder persistir NO invalida la sesión en curso: el store en memoria
    // ya tiene el veredicto y la caja sigue operando. Lo que se pierde es la
    // confirmación para el próximo arranque sin red.
  }
}

/**
 * Hidrata el store en memoria desde IndexedDB. Se llama una vez al montar el
 * workspace, ANTES de cualquier intento de cobro — así un arranque sin red
 * arranca con el veredicto correcto en vez de con "todavía no sé".
 */
export async function hydrateTenancy(registerId: string): Promise<TenancyVerdict> {
  const grant = await readGrant()
  const verdict = evaluateGrant(grant, registerId)
  useTenancyStore.getState().setVerdict(verdict)
  return verdict
}

// ── Confirmación contra el servidor ───────────────────────────────────────────

interface ClaimResponse {
  registerLeaseId: string
  /** La caja que el SERVIDOR confirmó — ver el docblock del apiOk() de
   *  `claim.php`. Opcional para tolerar un backend anterior a ese cambio. */
  registerId?: string
}

/**
 * Pide/confirma la tenencia al servidor y PERSISTE el resultado.
 *
 * Tres desenlaces, y el tercero es el que importa:
 *   - 200  → grant `held`, `confirmedAt` = ahora. El reloj del TTL se reinicia.
 *   - 409  → grant `denied` con el tenedor real. El device queda bloqueado
 *            para emitir, también sin red — el fix del incidente.
 *   - red  → NO se toca el grant guardado. Un corte de conexión no puede
 *            revocar un derecho que el servidor ya confirmó; para eso está el
 *            TTL. Sobrescribir acá convertiría cada microcorte en una caja
 *            bloqueada, que es justo lo contrario de offline-first.
 */
export async function refreshTenancy(registerId: string): Promise<TenancyVerdict> {
  try {
    const res = await posApi.post<ClaimResponse>('/v1/register/claim', {})
    const row: TenancyGrantRow = {
      key: TENANCY_KEY,
      // La caja que el servidor confirmó manda sobre la que este device cree
      // tener: si divergen, el grant tiene que describir lo confirmado. Con esa
      // divergencia `evaluateGrant()` devuelve `other-register` y no deja
      // emitir, que es el desenlace correcto.
      registerId: res?.registerId || registerId,
      status: 'held',
      confirmedAt: new Date().toISOString(),
      registerLeaseId: res?.registerLeaseId ?? null,
      denyReason: null,
      holderDeviceId: null,
      holderDeviceName: null,
    }
    await writeGrant(row)
    const verdict = evaluateGrant(row, registerId)
    useTenancyStore.getState().setVerdict(verdict)
    return verdict
  } catch (err) {
    if (err instanceof ApiError && err.status === 409) {
      const info = extractRegisterConflictInfo(err)
      const row: TenancyGrantRow = {
        key: TENANCY_KEY,
        registerId,
        status: 'denied',
        confirmedAt: new Date().toISOString(),
        registerLeaseId: null,
        denyReason: info.reason ?? 'taken_by_other',
        holderDeviceId: info.holderDeviceId,
        holderDeviceName: info.holderDeviceName,
      }
      await writeGrant(row)
      const verdict = evaluateGrant(row, registerId)
      useTenancyStore.getState().setVerdict(verdict)
      return verdict
    }

    // 4xx que no sea 409 (timbrado vencido, caja no seleccionada, sesión
    // muerta) tampoco revoca el grant guardado: son condiciones del servidor
    // que el device no puede evaluar offline, y el gate real para esas ya vive
    // en otro lado. Se devuelve el veredicto vigente, sin escribir.
    return hydrateTenancy(registerId)
  }
}

/**
 * "¿Puedo emitir AHORA?" — el punto de entrada de todo lo que necesita el
 * derecho, incluido el drenaje de la cola offline.
 *
 * Si el veredicto vigente ya es `ok`, no habla con el servidor (barato, se
 * puede llamar en cada ciclo de sync). Si NO lo es y hay conexión, intenta
 * confirmarlo — y ese intento es lo que recupera sola la venta del caso
 * inevitable: la caja quedó libre, el device la vuelve a tomar, la cola drena.
 */
export async function ensureTenancy(registerId: string): Promise<TenancyVerdict> {
  const current = useTenancyStore.getState().verdict
  if (current?.kind === 'ok') return current
  if (typeof navigator !== 'undefined' && !navigator.onLine) {
    return current ?? hydrateTenancy(registerId)
  }
  return refreshTenancy(registerId)
}
