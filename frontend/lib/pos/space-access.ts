/**
 * ¿Puede ESTE operador intervenir ESTE espacio? — espejo de front del guard de
 * exclusividad de espacio por mozo.
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
 * El guard tiene tres salidas a favor: el espacio no tiene mozo, el espacio es tuyo,
 * o tenés `pos.space.override`. Las dos primeras el front las puede espejar con
 * datos que ya tiene; la tercera no, porque el rol de la persona no viaja en el
 * roster del bootstrap (proyección deliberada a id/name/pinhash). Por eso los
 * permisos llegan por `/v1/unlock-pin` y viven en el lock store — ver el
 * docblock de `operatorPermissions` en `lib/pos/lock-store.ts`.
 *
 * ── Por qué el TOKEN se chequea ANTES que "el espacio es tuyo" ─────────────────
 *
 * Porque para el backend la identidad del operador ES el token, no el match
 * local del PIN. `SpaceOwnershipGuard::assert` compara el `waiterId` contra
 * `$operator['userId']`, que sale de la `OperatorAssertion` firmada; sin token
 * ese id es `null` y la comparación con el dueño NO puede dar verdadera —
 * termina en "Identificate con tu PIN". El `activeUser` del front es un dato
 * que el browser eligió: sirve para saludar y atribuir, jamás para autorizar.
 *
 * Espejar el orden al revés (dueño primero) es exactamente el bug que este
 * archivo tuvo hasta 2026-08-25: si el POST a `/api/pos/unlock` se cae con el
 * device online, el mozo veía sus acciones HABILITADAS sobre su propio espacio y
 * se comía un 403 al tocarlas — justo el 403 sorpresa que este espejo existe
 * para evitar. No reordenar "porque el dueño obviamente puede".
 */

/** Clave del catálogo que destraba el espacio ajeno (espejo de la constante PHP). */
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
  /** Nombre del mozo dueño del espacio, ya resuelto contra el roster. */
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
  // Espacio sin mozo asignado: no es de nadie, lo opera cualquiera. Asignar el
  // mozo ES lo que activa la exclusividad — no hay un segundo flag.
  if (waiterId === "") return ALLOWED

  // Fail-closed, igual que el guard: "no sé quién sos" no puede resolverse a
  // favor, porque sería la forma trivial de saltear la regla.
  if (activeUser === null) {
    return { allowed: false, reason: "Desbloqueá con tu PIN para operar este espacio." }
  }

  // Sin token no hay identidad que el backend reconozca, y eso alcanza TAMBIÉN
  // al dueño del espacio: el guard compara el `waiterId` contra el id que sale de
  // la afirmación firmada, así que sin ella ni el propio mozo pasa (ver el
  // docblock). Cortar acá evita ofrecer acciones que ya sabemos que terminan en
  // 403 — el caso real es un `/api/pos/unlock` que falló con el device online.
  if (operatorToken === null) {
    return {
      allowed: false,
      reason:
        "Sin identidad verificada: volvé a desbloquear con conexión para operar un espacio asignado.",
    }
  }

  // El dueño del espacio, siempre — ya con identidad probada.
  if (waiterId === activeUser.id) return ALLOWED

  // La válvula de escape del encargado: sin ella la regla se termina evadiendo
  // compartiendo el PIN del dueño.
  if (permissions.includes(SPACE_OVERRIDE_PERMISSION)) return ALLOWED

  return {
    allowed: false,
    reason: `La atiende ${waiterName ?? "otro mozo"}. Necesitás permiso para intervenir espacios de otro mozo.`,
  }
}
