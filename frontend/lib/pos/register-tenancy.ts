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
 * pierda — la caja queda LIBRE (nadie más la tomó) y desde 2026-09-09 el
 * servidor acepta ese lote encolado SIN pedir tenencia, con el mismo número
 * (ver "DRENAR ≠ VENDER" en `api/v1/offline-sync.php`). El único desenlace
 * realmente terminal es que OTRO device ya la haya tomado, y ahí la venta
 * espera en la cola con el nombre de quien la tiene y qué hacer, nunca se
 * descarta sola.
 *
 * Lo que el device NO recupera solo es el derecho a EMITIR: tras una
 * liberación de admin tiene que venir alguien y tocar "Tomar caja" acá. El
 * servidor lo hace cumplir (`RegisterLeaseService::isAdminRevoked()`), no
 * este archivo.
 *
 * Estando ONLINE la pérdida de tenencia no espera al TTL ni al próximo latido:
 * `use-realtime-sync.ts` escucha la entity `register-lease` y fuerza un
 * `refreshTenancy()` en el momento.
 *
 * Confirmar no es tomar (2026-09-01, cerrado el 2026-09-09)
 * ────────────────────────────────────────────────────────
 * Todo lo de arriba es CONFIRMACIÓN: preguntarle al servidor si esta caja
 * sigue siendo de este device. TOMAR una caja libre es otra cosa, y hoy ya no
 * pasa sola por NINGÚN camino: el único call-site que adquiere es el botón
 * "Tomar caja" (`RegisterTakenPhase`, pay-dialog.tsx), con
 * `{ acquire: 'operator' }`.
 *
 * En 2026-09-01 quedaban dos adquirientes y se sacó el latido. El que quedaba
 * —`ensureTenancy()`, el drenaje de la cola— produjo el incidente del
 * 2026-09-09: el ciclo de sync corre cada 30 s y lo llamaba SIEMPRE, con cola
 * vacía incluida, así que la tablet se apropiaba de la caja apenas el admin la
 * liberaba desde el panel. El owner liberó dos veces y la tablet la retomó sola
 * las dos; desde su teléfono la caja se veía ocupada para siempre.
 *
 * La corrección de fondo no fue del cliente: el servidor dejó de exigir
 * tenencia para SUBIR lo ya emitido, así que el drenaje no necesita adquirir
 * nada, y además VETA la re-adquisición automática del dispositivo que un admin
 * liberó. Un cliente arreglado no alcanzaba — la tablet puede estar offline con
 * un bundle viejo y re-adquirir al reconectar.
 */

import { getPosOfflineDB } from '@/lib/pos/offline-db'
import type { TenancyDenyReason, TenancyGrantRow } from '@/lib/pos/offline-db'
import { posApi } from '@/lib/api/pos-client'
import { ApiError } from '@/lib/api-client'
import { extractRegisterConflictInfo } from '@/lib/pos/register-conflict'
import { useTenancyStore } from '@/lib/pos/tenancy-store'
import { useCatalogStore } from '@/lib/catalog/store'
import { tenantNow } from '@/lib/format-date'

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
 *   - `free`        — el servidor dijo que NO la tiene, pero la caja está
 *                     LIBRE: nadie la tomó. Remedio: TOMARLA — un acto del
 *                     cajero, no del sistema.
 *   - `denied`      — el servidor dijo que NO: otro device la tiene.
 *                     Remedio: que la liberen.
 *   - `stale`       — la tuvo, pero la confirmación venció (>12 h sin red).
 *                     Remedio: conectarse.
 *   - `other-register` — el grant guardado es de OTRA caja (el device se
 *                     movió de caja sin volver a confirmar). Remedio:
 *                     conectarse.
 *
 * `free` es NUEVO (2026-09-01) y existe porque el servidor dejó de tomar la
 * caja solo. Antes, un 409 de `claim.php` únicamente podía significar "la
 * tiene otro" —si estaba libre, el endpoint se la quedaba— así que un solo
 * `denied` alcanzaba. Ahora el latido pregunta sin tomar, y "libre pero no la
 * tengo" llega hasta acá: es un estado con remedio propio (tocar un botón) y
 * mezclarlo con `denied` mandaría al cajero a buscar un admin que no hace
 * falta.
 */
export type TenancyVerdictKind =
  | 'ok'
  | 'never'
  | 'free'
  | 'denied'
  | 'stale'
  | 'other-register'

export interface TenancyVerdict {
  kind: TenancyVerdictKind
  /** Único campo que el call-site necesita para decidir si deja emitir. */
  canIssue: boolean
  /**
   * ¿Tiene sentido ofrecerle al cajero un botón para TOMAR la caja?
   *
   * Se deriva acá, en la función pura, y no en cada JSX: es la misma pregunta
   * en el CTA del cobro, en el mensaje corto del botón y en la pantalla
   * bloqueante, y las tres tienen que contestarla igual. `false` solo cuando
   * la caja ya es de este device (`ok`, no hay nada que tomar) o cuando la
   * tiene OTRO — el único desenlace que el device no puede resolver solo.
   *
   * NO promete que el claim vaya a entrar: entre la última confirmación y el
   * toque del cajero alguien más pudo tomarla. Promete que pedirla es la
   * acción correcta.
   */
  canAcquire: boolean
  holderDeviceId: string | null
  holderDeviceName: string | null
  /** Motivo server-side del rechazo, cuando lo hay. */
  denyReason: TenancyDenyReason | null
  /**
   * QUIÉN cerró la última tenencia (`'admin:{contactId}'` | `'device:…'`), solo
   * con `denyReason: 'revoked'`. Lo consume `releasedByAdmin()` en
   * `register-conflict.ts` para decir "un administrador liberó esta caja" en
   * vez de un genérico — y para avisar que el aparato NO la retoma solo.
   */
  releasedBy: string | null
  /** ISO de la última confirmación conocida, para poder decir desde cuándo. */
  confirmedAt: string | null
}

const NO_GRANT: TenancyVerdict = {
  kind: 'never',
  canIssue: false,
  // Sin grant, el remedio es pedir la caja: puede estar libre y este device
  // todavía no lo sabe. Si la tiene otro, el claim lo dirá con un 409.
  canAcquire: true,
  holderDeviceId: null,
  holderDeviceName: null,
  denyReason: null,
  releasedBy: null,
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
    releasedBy: grant.releasedBy ?? null,
    confirmedAt: grant.confirmedAt,
  }

  // Un grant de otra caja no dice NADA sobre ésta. Pasa cuando el device se
  // reasigna de caja (`/v1/active-register`) y todavía no volvió a confirmar.
  if (grant.registerId !== registerId) {
    return { ...base, kind: 'other-register', canIssue: false, canAcquire: true }
  }

  if (grant.status === 'denied') {
    // El `denyReason` del servidor es lo que separa "la tiene otro" de "está
    // libre y no la tengo". `taken_by_other` es el único terminal; los otros
    // tres (`released`/`revoked`/`never_held`) dejan la caja disponible.
    //
    // `denyReason: null` (backend viejo, o un 409 sin `details` legible) cae en
    // el lado conservador de CADA pregunta por separado: no emite —eso nunca
    // se relaja— pero sí deja pedir la caja, porque el claim explícito es
    // inofensivo: si la tiene otro, vuelve un 409 y el veredicto se corrige
    // con información fresca.
    const takenByOther =
      grant.denyReason === 'taken_by_other' || grant.holderDeviceId !== null
    return {
      ...base,
      kind: takenByOther ? 'denied' : 'free',
      canIssue: false,
      canAcquire: !takenByOther,
    }
  }

  // Reloj del device: puede estar corrido. Una `confirmedAt` en el FUTURO se
  // trata como vencida en vez de como eternamente fresca — el modo de fallo
  // seguro es no dejar emitir, no dejar emitir para siempre.
  const confirmed = new Date(grant.confirmedAt).getTime()
  if (Number.isNaN(confirmed) || confirmed > now || now - confirmed > TENANCY_TTL_MS) {
    // Vencido no es lo mismo que perdido: lo más probable es que la tenencia
    // server-side siga siendo de este device (no vence sola) y solo falte
    // reconfirmarla. Pedirla explícito es exactamente el remedio.
    return { ...base, kind: 'stale', canIssue: false, canAcquire: true }
  }

  return { ...base, kind: 'ok', canIssue: true, canAcquire: false }
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
/**
 * Desde cuándo corre ESTA tenencia, en hora local del tenant (naive).
 *
 * `claim.php` devuelve el MISMO `registerLeaseId` en cada latido mientras la
 * tenencia siga siendo la misma fila de `register_lease`, así que un id que no
 * cambió significa "sigo teniendo la caja desde antes" y hay que conservar el
 * `heldSince` original. Un id nuevo (o ninguno anterior) es una tenencia nueva:
 * empieza ahora.
 *
 * Si el grant anterior se perdió —base limpiada, device re-pareado— el
 * `heldSince` se re-estampa en el presente aunque la tenencia server-side sea
 * vieja. Eso hace que el device se declare con MENOS cobertura de la que tiene
 * y muestre una advertencia de más, que es el lado seguro del error: la
 * alternativa sería afirmar que vio un tramo del turno que no vio.
 */
function nextHeldSince(
  prev: TenancyGrantRow | null,
  registerId: string,
  registerLeaseId: string | null,
): string {
  const now = tenantNow(useCatalogStore.getState().config?.timezone)
  if (
    prev &&
    prev.status === 'held' &&
    prev.registerId === registerId &&
    prev.registerLeaseId !== null &&
    prev.registerLeaseId === registerLeaseId &&
    typeof prev.heldSince === 'string' &&
    prev.heldSince !== ''
  ) {
    return prev.heldSince
  }
  return now
}

/**
 * Desde cuándo este device tiene la caja, si la tiene. `null` cuando no hay
 * grant, es de otra caja, o el grant es anterior a que existiera el campo.
 */
export async function tenancyHeldSince(registerId: string): Promise<string | null> {
  const grant = await readGrant()
  if (!grant || grant.registerId !== registerId || grant.status !== 'held') return null
  return grant.heldSince ?? null
}

/**
 * Qué puede hacer esta llamada con la tenencia.
 *
 *   - `false`      — solo preguntar. Latido, evento `online`, evento realtime
 *                    `register-lease` y montaje del workspace.
 *   - `'operator'` — el cajero tocó "Tomar caja" EN este aparato. Único valor
 *                    que toma la caja, y el único que levanta el veto del
 *                    administrador (ver abajo).
 *
 * `true` sigue siendo válido en el protocolo —es lo que mandan los bundles
 * viejos que todavía están en la calle— y el servidor lo trata como una
 * adquisición AUTOMÁTICA, sujeta al veto. Este cliente ya no lo emite: desde
 * 2026-09-09 no queda ningún camino automático que tome la caja.
 */
export type TenancyAcquireIntent = false | true | 'operator'

export interface RefreshTenancyOptions {
  /**
   * ¿Esta llamada puede TOMAR la caja si está libre, o solo preguntar?
   *
   * Default `false`, y el default importa: hasta 2026-09-01 cada latido de
   * `useRegisterClaim` (5 min) tomaba la caja sin querer, así que un POS
   * abierto se la volvía a llevar apenas otro dispositivo la liberaba. El
   * cajero del segundo aparato veía la caja liberarse y seguía sin poder
   * facturar, sin nada en pantalla que explicara por qué.
   *
   * Desde 2026-09-09 queda UN SOLO call-site que toma la caja: el botón "Tomar
   * caja" de la pantalla de bloqueo del cobro (`RegisterTakenPhase`,
   * pay-dialog.tsx), con `'operator'`. `ensureTenancy()` —el drenaje de la cola
   * offline— dejó de adquirir: el servidor ya acepta una venta YA EMITIDA con
   * la caja libre, sin pedir tenencia (ver "DRENAR ≠ VENDER" en
   * `api/v1/offline-sync.php`). Era el último camino automático que tomaba la
   * caja, y por ahí se colaba el bug del owner.
   *
   * Ojo: el default del SERVIDOR es el contrario (`acquire` ausente ⇒
   * adquisición automática, por compatibilidad con bundles viejos), por eso acá
   * viaja SIEMPRE explícito en el body en vez de omitirse cuando es `false`.
   */
  acquire?: TenancyAcquireIntent
}

export async function refreshTenancy(
  registerId: string,
  opts: RefreshTenancyOptions = {},
): Promise<TenancyVerdict> {
  // `false` explícito cuando no se pidió nada: omitirlo haría que el servidor
  // aplique su default de compatibilidad (adquirir), justo al revés.
  const acquire: TenancyAcquireIntent = opts.acquire ?? false
  try {
    const res = await posApi.post<ClaimResponse>('/v1/register/claim', { acquire })
    const prev = await readGrant()
    const confirmedRegisterId = res?.registerId || registerId
    const row: TenancyGrantRow = {
      key: TENANCY_KEY,
      // La caja que el servidor confirmó manda sobre la que este device cree
      // tener: si divergen, el grant tiene que describir lo confirmado. Con esa
      // divergencia `evaluateGrant()` devuelve `other-register` y no deja
      // emitir, que es el desenlace correcto.
      registerId: confirmedRegisterId,
      status: 'held',
      confirmedAt: new Date().toISOString(),
      heldSince: nextHeldSince(prev, confirmedRegisterId, res?.registerLeaseId ?? null),
      registerLeaseId: res?.registerLeaseId ?? null,
      denyReason: null,
      holderDeviceId: null,
      holderDeviceName: null,
      releasedBy: null,
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
        // Denegada: no hay tenencia, no hay "desde cuándo".
        heldSince: null,
        registerLeaseId: null,
        denyReason: info.reason ?? 'taken_by_other',
        holderDeviceId: info.holderDeviceId,
        holderDeviceName: info.holderDeviceName,
        releasedBy: info.releasedBy,
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
 * "¿Cómo está la tenencia de esta caja AHORA?" — lo que el drenaje de la cola
 * offline consulta antes de postear un lote.
 *
 * NO ADQUIERE (cambio del 2026-09-09). Hasta este cambio pasaba
 * `{ acquire: true }` y era el ÚNICO camino automático que tomaba la caja. Lo
 * hacía por una razón real —el servidor exigía tenencia activa para aceptar una
 * venta ya emitida— pero el efecto en producción fue el bug del owner: el ciclo
 * de sync corre cada 30 s, llamaba acá SIEMPRE (incluso con la cola vacía) y la
 * tablet se apropiaba de la caja apenas el admin la liberaba desde el panel.
 * Liberó dos veces y la tablet la retomó sola las dos.
 *
 * El requisito desapareció en el servidor, que es donde correspondía atacarlo:
 * `offline-sync.php` acepta una venta YA EMITIDA cuando la caja está LIBRE, sin
 * pedir tenencia (ver "DRENAR ≠ VENDER" ahí). El comprobante impreso sigue
 * subiendo —§53 intacta— y ya nadie toma la caja por su cuenta. Lo único que
 * queda terminal es que OTRO dispositivo la tenga tomada, y para eso este
 * veredicto sigue haciendo falta.
 *
 * Si el veredicto vigente ya es `ok`, no habla con el servidor (barato, se
 * puede llamar en cada ciclo de sync).
 */
export async function ensureTenancy(registerId: string): Promise<TenancyVerdict> {
  const current = useTenancyStore.getState().verdict
  if (current?.kind === 'ok') return current
  if (typeof navigator !== 'undefined' && !navigator.onLine) {
    return current ?? hydrateTenancy(registerId)
  }
  return refreshTenancy(registerId, { acquire: false })
}
