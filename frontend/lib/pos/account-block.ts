/**
 * "La cuenta del comercio no está al día" — el único rechazo del servidor que
 * NO puede matar nada de lo que la caja tiene encolado.
 *
 * ¿Por qué existe este módulo?
 * ────────────────────────────
 * La D8 de `context/34-admin-saas-plan.md` §F7 es un mandato explícito del
 * owner: **una venta encolada offline NUNCA se rechaza por cuenta impaga**.
 * Rechazarla sería el error más caro que puede cometer este sistema — el
 * comprobante ya se emitió, ya se imprimió y el cliente ya pagó.
 *
 * El job `plan-lifecycle` (F7/P2) escribe `company.blocked = 1` cinco días
 * después de que vence el plan, y ese flag lo enforcea `companyAccessDenial()`
 * en el embudo de auth de la API: TODA request del tenant pasa a responder 403.
 * Sin este módulo, ese 403 llegaba a las dos colas del POS como "el servidor
 * dijo que no" y las dos lo trataban como terminal:
 *
 * - la cola de VENTAS (`use-offline-sync.ts`) lo veía como caída de red, lo
 *   reintentaba 6 veces y después dejaba la venta en `failed`, con el botón
 *   "Descartar" al lado;
 * - la cola de OPERACIONES (`pending-ops-sync.ts`) lo clasificaba como
 *   `HTTP_403` no transitorio y lo marcaba `failed` en el primer intento,
 *   trabando el canal —que puede tener un CIERRE DE CAJA adentro—.
 *
 * La semántica correcta ya existía en el motor de operaciones: **espera**. No
 * cuenta intentos, no escribe error, frena el canal y vuelve sola. Este módulo
 * es lo que permite llegar a ella, porque para tratar un 403 distinto del resto
 * hay que poder RECONOCERLO.
 *
 * El mecanismo
 * ────────────
 * El backend manda el motivo en `error.details.reason` del envelope — el mismo
 * campo que ya usa `outlet_out_of_scope` en `lib/api-client.ts`, no un contrato
 * nuevo. `apiError($msg, 403, ['reason' => ...])` en `bootstrap.php` y
 * `apiAuthPosContext.php`; el resolver único es `companyAccessDenial()`.
 *
 * NO se adivina por el texto del mensaje. Un heurístico por string es
 * exactamente cómo se construye el bug de al lado: alcanza que alguien mejore
 * el copy del error para que una venta real vuelva a morir en la cola.
 *
 * Si el motivo NO viene (una versión vieja del backend, un proxy que se comió
 * el cuerpo), el 403 sigue siendo terminal como siempre. Es fail-closed hacia
 * el comportamiento anterior, no hacia una espera infinita silenciosa.
 */

/**
 * Motivos que significan "la cuenta del comercio no puede operar AHORA, pero
 * esto se arregla desde /admin y no tiene nada que ver con lo que la caja
 * mandó". Los dos son reversibles con una acción de una persona.
 *
 * `account_inactive` (status `cancelled`) NO está: un tenant dado de baja no
 * se destraba solo y una espera infinita ahí sería esconder el problema en vez
 * de mostrarlo.
 */
const WAITING_REASONS = new Set(['account_blocked', 'account_suspended'])

/**
 * Lo que ve el cajero. Dice el estado, no el mecanismo: "error 403" no es
 * accionable para nadie parado atrás de una caja, "esperando regularizar el
 * pago" sí — y le dice que su venta está guardada, no perdida.
 */
export const ACCOUNT_BLOCKED_NOTE = 'Esperando regularizar el pago'

/** Código con el que el motor identifica esta espera en logs y en la cola. */
export const ACCOUNT_BLOCKED_CODE = 'ACCOUNT_BLOCKED'

/**
 * ¿Este error es el 403 de cuenta impaga/suspendida?
 *
 * Recibe `unknown` a propósito: los dos call-sites lo llaman desde un `catch`,
 * donde TypeScript no promete nada. Acepta tanto un `ApiError` (que lleva el
 * envelope crudo en `payload`) como el envelope pelado, porque los BFF
 * `/api/pos/*` devuelven el JSON ya parseado.
 */
export function isAccountBlocked(err: unknown): boolean {
  if (!err || typeof err !== 'object') return false

  const status = (err as { status?: unknown }).status
  if (status !== 403) return false

  const payload = (err as { payload?: unknown }).payload
  return readReason(payload) || readReason(err)
}

function readReason(envelope: unknown): boolean {
  if (!envelope || typeof envelope !== 'object') return false
  const error = (envelope as { error?: unknown }).error
  if (!error || typeof error !== 'object') return false
  const details = (error as { details?: unknown }).details
  if (!details || typeof details !== 'object') return false
  const reason = (details as { reason?: unknown }).reason

  return typeof reason === 'string' && WAITING_REASONS.has(reason)
}
