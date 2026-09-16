/**
 * Desbloqueo sin PIN de la caja (context/72 §9.3, D-P2): qué hace el POS
 * cuando `soleOperator()` dice que la sucursal tiene un solo usuario.
 *
 * Dos pasos, con la MISMA semántica que el PIN de `lock-screen.tsx`:
 *
 *   1. Local e inmediato: se desbloquea a nombre de ese usuario. Funciona sin
 *      red, igual que el match local del PIN.
 *   2. Best-effort contra el servidor (`/api/pos/unlock-sole`): trae la
 *      afirmación firmada de operador y sus permisos. Sin red quedan vacíos,
 *      como en el PIN offline.
 *
 * Diferencia deliberada con el PIN: si el servidor responde que la regla NO se
 * cumple (`pin_required` — el roster cacheado quedó viejo y ya son dos) o
 * afirma a OTRA persona, la caja se vuelve a bloquear. Con PIN el match local
 * ya prueba quién es; sin PIN la única prueba es "no hay nadie más", y esa la
 * decide el servidor cuando se lo puede consultar.
 */

export interface SoleUnlockState {
  locked: boolean
  soleOperator: boolean
  activeUser: { id: string; name: string } | null
}

export interface SoleUnlockDeps {
  /** POST al BFF con el Bearer del device (`posFetch`). */
  post: () => Promise<Response>
  /** Estado FRESCO del store (no el de un render viejo). */
  getState: () => SoleUnlockState
  unlockAsSoleOperator: (user: { id: string; name: string }) => void
  setOperatorToken: (token: string | null) => void
  setOperatorPermissions: (permissions: string[]) => void
  /**
   * El servidor dijo que la regla no se cumple: bloquear y NO volver a
   * desbloquear sin PIN en esta carga (si no, el roster cacheado de uno lo
   * reabriría en el acto). La próxima carga decide con el roster nuevo.
   */
  denySoleOperator: () => void
}

interface UnlockResponse {
  ok?: boolean
  user?: { id?: string } | null
  operatorToken?: string | null
  permissions?: unknown
  error?: { reason?: string | null } | null
}

export async function unlockAsSoleOperator(
  user: { id: string; name: string },
  deps: SoleUnlockDeps,
): Promise<void> {
  deps.unlockAsSoleOperator(user)
  const requestedBy = user.id

  let res: Response
  try {
    res = await deps.post()
  } catch {
    return // sin red: queda el desbloqueo local, sin afirmación
  }
  const json = (await res.json().catch(() => null)) as UnlockResponse | null

  // La respuesta llega tarde: el estado pudo cambiar mientras viajaba (se
  // bloqueó, desbloqueó otra persona con PIN). Mismo guard que el PIN — una
  // respuesta en vuelo no resucita ni reasigna una afirmación.
  const now = deps.getState()
  if (now.locked || !now.soleOperator || now.activeUser?.id !== requestedBy) return

  if (!res.ok || !json?.ok) {
    if (res.status === 403 && json?.error?.reason === "pin_required") {
      deps.denySoleOperator()
    }
    return
  }
  if (json.user?.id !== requestedBy) {
    deps.denySoleOperator()
    return
  }
  if (typeof json.operatorToken === "string") deps.setOperatorToken(json.operatorToken)
  if (Array.isArray(json.permissions)) {
    deps.setOperatorPermissions(json.permissions.filter((p): p is string => typeof p === "string"))
  }
}
