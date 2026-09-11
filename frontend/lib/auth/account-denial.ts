/**
 * "La cuenta del comercio no puede operar" — el 403 que NO es un permiso.
 *
 * ¿Por qué existe este módulo?
 * ────────────────────────────
 * El job `plan-lifecycle` escribe `company.blocked = 1` y desde ese momento
 * TODA request del tenant responde 403: lo enforcea `companyAccessDenial()` en
 * el embudo de auth de la API (`apiAuthTenant()`), antes de mirar permisos.
 *
 * Sin este módulo el panel quedaba VACÍO: el bootstrap fallaba, cada listado
 * fallaba, y el usuario veía un panel muerto con 403s silenciosos en consola.
 * Nadie le decía que su cuenta estaba bloqueada ni qué hacer al respecto — que
 * es la única información que importa, porque los datos están intactos y se
 * arregla con una llamada.
 *
 * El mecanismo
 * ────────────
 * El motivo viaja en `error.details.reason` del envelope — el MISMO contrato
 * que ya usan `outlet_out_of_scope` (`lib/api-client.ts`) y la espera de la
 * cola del POS (`lib/pos/account-block.ts`). No se adivina por el texto del
 * mensaje: alcanzaría que alguien mejore el copy del error para que el panel
 * vuelva a quedar mudo.
 *
 * Y se distingue del 403 de PERMISOS a propósito. Un usuario sin permiso sobre
 * un endpoint también recibe 403, pero ahí el resto del panel funciona y la
 * pantalla completa sería una mentira. Ese 403 no trae `reason` y sigue
 * comportándose como siempre: fail-closed hacia el comportamiento anterior.
 *
 * Relación con `lib/pos/account-block.ts`
 * ───────────────────────────────────────
 * Ese módulo responde otra pregunta: "¿este 403 es una ESPERA para la cola de
 * la caja?" — y por eso excluye `account_inactive` (un tenant dado de baja no
 * se destraba solo, y esperarlo para siempre escondería el problema). Acá la
 * pregunta es "¿qué le muestro al humano que está mirando el panel?", y los
 * tres motivos tienen respuesta: una pantalla que explica el estado. Mismo
 * campo del sobre, dos preguntas distintas.
 */

/**
 * Los tres motivos que `companyAccessDenial()` (api/includes/functions.php)
 * puede devolver por una cuenta que existe pero no puede operar.
 *
 * `company_unknown` NO está: es un tenant inexistente, o sea una credencial
 * que apunta a la nada. Eso es un problema de sesión, no un estado de cuenta
 * que se le pueda explicar al usuario — y cae por el camino del login.
 */
export const ACCOUNT_DENIAL_REASONS = [
  "account_blocked",
  "account_suspended",
  "account_inactive",
] as const

export type AccountDenialReason = (typeof ACCOUNT_DENIAL_REASONS)[number]

/**
 * Evento global que emite el cliente HTTP del panel al ver uno de estos 403.
 *
 * Mismo patrón que `api:unauthorized`: el transporte no sabe nada de UI, avisa;
 * el shell del panel (`PanelAuthGuard`) escucha y decide qué pintar. Así el
 * bloqueo que llega A MITAD DE SESIÓN —el panel ya cargado, el job corriendo
 * mientras el usuario trabaja— llega al mismo estado que el del arranque, sin
 * un `if` en cada página.
 */
export const ACCOUNT_DENIED_EVENT = "api:account-denied"

export type AccountDeniedEventDetail = { reason: AccountDenialReason }

function isDenialReason(value: unknown): value is AccountDenialReason {
  return (
    typeof value === "string" &&
    (ACCOUNT_DENIAL_REASONS as readonly string[]).includes(value)
  )
}

/**
 * ¿Este error es el 403 de cuenta bloqueada/suspendida/inactiva? Devuelve el
 * motivo, no un booleano: el copy de la pantalla depende de cuál de los tres es.
 *
 * Recibe `unknown` a propósito — los call-sites lo llaman desde un `catch` o
 * desde el `error` de una query, donde TypeScript no promete nada. Acepta un
 * `ApiError` (que lleva el envelope crudo en `payload`) o el envelope pelado.
 *
 * El 403 es condición necesaria: un `reason` sin 403 no es este caso.
 */
export function readAccountDenialReason(err: unknown): AccountDenialReason | null {
  if (!err || typeof err !== "object") return null
  if ((err as { status?: unknown }).status !== 403) return null

  const payload = (err as { payload?: unknown }).payload
  return readReason(payload) ?? readReason(err)
}

/** Lee `error.details.reason` de un envelope `{ ok:false, error:{…} }`. */
function readReason(envelope: unknown): AccountDenialReason | null {
  if (!envelope || typeof envelope !== "object") return null
  const error = (envelope as { error?: unknown }).error
  if (!error || typeof error !== "object") return null
  const details = (error as { details?: unknown }).details
  if (!details || typeof details !== "object") return null
  const reason = (details as { reason?: unknown }).reason

  return isDenialReason(reason) ? reason : null
}

/**
 * Lee el motivo de un envelope ya parseado (sin `ApiError` alrededor), para el
 * punto del transporte donde todavía no se construyó el error.
 */
export function readAccountDenialFromEnvelope(
  status: number,
  envelope: unknown,
): AccountDenialReason | null {
  if (status !== 403) return null
  return readReason(envelope)
}
