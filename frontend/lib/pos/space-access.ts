/**
 * ¿Puede ESTE operador intervenir ESTE espacio? — espejo de front del guard de
 * exclusividad de mesa por mozo.
 *
 * ── Es un ESPEJO, no la autorización ────────────────────────────────────────
 *
 * La autoridad es y sigue siendo `api/lib/Spaces/SpaceOwnershipGuard.php`, que
 * corre en el SERVICE (no en el endpoint) y por lo tanto vale para los tres
 * callers que hoy mutan una sesión. Nada de lo que decida este archivo habilita
 * ni bloquea nada: si el front se equivoca a favor, el backend responde 403.
 *
 * Existe por una regla de UX del owner —el impedimento vive en el CONTROL de la
 * acción, no en una banda ni en un 403 sorpresa— y por eso tiene que replicar
 * el orden EXACTO del guard, incluida su válvula de escape. Si cambia
 * `SpaceOwnershipGuard::assert`, hay que cambiar esta función en el mismo
 * commit: un espejo desactualizado es peor que no tener espejo, porque apaga
 * acciones que el backend aceptaría (o al revés).
 *
 * ── Por qué hace falta el permiso del operador ──────────────────────────────
 *
 * El guard tiene TRES salidas a favor: la mesa no tiene mozo, la mesa es tuya,
 * o tenés `pos.space.override`. Las dos primeras el front las puede espejar con
 * datos que ya tiene; la tercera no, porque el rol de la persona no viaja en el
 * roster del bootstrap (proyección deliberada a id/name/pinhash). Por eso los
 * permisos llegan por `/v1/unlock-pin` y viven en el lock store — ver el
 * docblock de `operatorPermissions` en `lib/pos/lock-store.ts`.
 */

/** Clave del catálogo que destraba la mesa ajena (espejo de la constante PHP). */
export const SPACE_OVERRIDE_PERMISSION = "pos.space.override"

export interface SpaceAccessInput {
  /** Sesión abierta del espacio, o `null` si está libre. */
  session: { waiterId: string | null } | null
  /** Persona identificada en la caja (match local del PIN). */
  activeUser: { id: string; name: string } | null
  /** Afirmación firmada por el server. `null` = desbloqueo offline. */
  operatorToken: string | null
  /** Permisos `pos.*` del operador, emitidos junto al token. */
  permissions: string[]
  /** Nombre del mozo dueño de la mesa, ya resuelto contra el roster. */
  waiterName: string | null
}

export interface SpaceAccess {
  allowed: boolean
  /** Motivo para mostrar en el control. `null` cuando `allowed`. */
  reason: string | null
}

const ALLOWED: SpaceAccess = { allowed: true, reason: null }

/**
 * @returns `allowed` con `reason: null`, o bloqueado con el motivo ya redactado
 *          para pintar en el ítem/tooltip/toast.
 *
 * Sin sesión devuelve `allowed`: la exclusividad no aplica a un espacio libre.
 * Que la mayoría de las acciones igual no tengan sentido ahí es otra pregunta
 * —"¿hay algo sobre lo que operar?"— y la resuelve el caller, que es el único
 * que sabe qué acción está evaluando.
 */
export function evaluateSpaceAccess(input: SpaceAccessInput): SpaceAccess {
  const { session, activeUser, operatorToken, permissions, waiterName } = input

  if (!session) return ALLOWED

  const waiterId = (session.waiterId ?? "").trim()
  // Mesa sin mozo asignado: no es de nadie, la opera cualquiera. Asignar el
  // mozo ES lo que activa la exclusividad — no hay un segundo flag.
  if (waiterId === "") return ALLOWED

  // Fail-closed, igual que el guard: "no sé quién sos" no puede resolverse a
  // favor, porque sería la forma trivial de saltear la regla.
  if (activeUser === null) {
    return { allowed: false, reason: "Desbloqueá con tu PIN para operar este espacio." }
  }

  // El dueño de la mesa, siempre.
  if (waiterId === activeUser.id) return ALLOWED

  // De acá para abajo solo pasa quien tenga el override, y el backend solo se
  // lo va a reconocer a quien mande la afirmación firmada. Sin token, aunque el
  // permiso esté en el store, la request terminaría en 403: la excepción se
  // evalúa contra el rol del OPERADOR probado, no contra lo que diga el
  // cliente. Cortar acá evita ofrecer una acción que ya sabemos que falla.
  if (operatorToken === null) {
    return {
      allowed: false,
      reason:
        "Sin identidad verificada: volvé a desbloquear con conexión para intervenir mesas de otro mozo.",
    }
  }

  // La válvula de escape del encargado: sin ella la regla se termina evadiendo
  // compartiendo el PIN del dueño.
  if (permissions.includes(SPACE_OVERRIDE_PERMISSION)) return ALLOWED

  return {
    allowed: false,
    reason: `La atiende ${waiterName ?? "otro mozo"}. Necesitás permiso para intervenir mesas de otro mozo.`,
  }
}
