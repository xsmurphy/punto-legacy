import { getDeviceToken, clearDeviceToken, type DeviceModule } from "@/lib/auth/device-token"
import { getSharedQueryClient } from "@/lib/auth/query-client-singleton"
import { moduleLogout } from "@/lib/auth/module-logout"
import { useLockStore } from "@/lib/pos/lock-store"

/** Header de la afirmación de operador. Espejo de `OperatorAssertion::HEADER`. */
const OPERATOR_TOKEN_HEADER = "X-Operator-Token"

/**
 * Fetch autenticado para los BFF routes `/api/pos/*`.
 *
 * Inyecta el Bearer token del device POS (localStorage) en cada request, igual
 * que `lib/api-client.ts` hace para `/api/v1/*`. Sin esto, el BFF solo reenvía
 * la cookie `_jwt_panel` y la API autentica como realm `panel` (registerId=''),
 * lo que rompe toda mutación de caja (403) y los devices POS puros (401).
 *
 * Devuelve el `Response` crudo — cada caller mantiene su propio parsing de
 * envelope. Preserva cualquier header que el caller ya haya seteado.
 *
 * `module` (default `"pos"`) elige el slot de token namespaced. La Estación de
 * Impresión (module `"print"`, context/26) también usa los BFF `/api/pos/*`
 * (print de red) pero NO tiene token de POS: sin este parámetro el Bearer
 * salía vacío y el BFF respondía 401. El default mantiene todos los
 * call-sites del POS sin cambios.
 *
 * Sesión revocada por el admin (`authSessionRevokeByDevice`,
 * `api/includes/auth_session.php`): la API responde 401 con
 * `code: "session_revoked"` en el envelope. Detectamos ese caso puntual acá
 * — el único punto por el que pasan todas las requests de un device — y
 * limpiamos el token de ESE `module` para que el guard correspondiente
 * (`PosAuthGuard` en POS) pierda el token en su próximo check y muestre la
 * pantalla de reconexión. Cualquier OTRO 401 (token vencido por otras razones,
 * error transitorio) NO limpia nada: desemparejar el device ante un error que
 * no sea la revocación explícita sería peor que el bug que esto arregla.
 *
 * `res.clone()` porque el caller original todavía necesita leer el body de
 * la response cruda que devolvemos — sin clonar, el primer `.json()` deja el
 * stream consumido y el caller se queda sin body.
 */
export async function posFetch(
  input: string,
  init: RequestInit = {},
  module: DeviceModule = "pos",
): Promise<Response> {
  const headers = new Headers(init.headers)
  const token = getDeviceToken(module)
  if (token && !headers.has("Authorization")) {
    headers.set("Authorization", `Bearer ${token}`)
  }

  // Afirmación de operador (`X-Operator-Token`): QUIÉN está usando la caja, en
  // contraposición al Bearer de arriba, que dice QUÉ terminal es. Van juntas y
  // son distintas — el Bearer autentica, esta solo identifica a la persona, y
  // el backend nunca acepta la segunda sin la primera.
  //
  // Se adjunta acá, en el wrapper compartido, y no en los call-sites que hoy
  // la necesitan (Espacios): el mismo criterio por el que el Bearer vive acá.
  // Un header de identidad que cada llamada decide si manda termina ausente
  // justo en la llamada nueva que alguien agregó sin saber que existía, y el
  // síntoma sería un 403 incomprensible en vez de un bug visible.
  //
  // Solo para el módulo `pos`: la Estación de Impresión y las pantallas de
  // cliente no tienen operador humano detrás.
  if (module === "pos" && !headers.has(OPERATOR_TOKEN_HEADER)) {
    const operatorToken = useLockStore.getState().operatorToken
    if (operatorToken) headers.set(OPERATOR_TOKEN_HEADER, operatorToken)
  }
  const res = await fetch(input, { ...init, credentials: "include", headers })

  if (res.status === 401) {
    try {
      const payload = (await res.clone().json()) as { code?: string } | null
      const code = payload?.code
      // `session_revoked`: el device ya no existe o fue revocado explícitamente
      // (api/includes/auth_session.php, api/bootstrap.php, apiAuthPosContext.php).
      // `device_incomplete`: el device EXISTE pero le falta una dimensión
      // obligatoria (outlet/register) — pareo a medias o caja liberada después
      // del pareo (DeviceAuth::requireCompleteContext(), mismo guard en ambos
      // resolvers pos-app). Ambos casos son "esta sesión no puede operar", pero
      // el cajero necesita un mensaje distinto ("te desconectaron" no es lo
      // mismo que "este dispositivo nunca terminó de configurarse") — por eso
      // el flag guarda el code crudo en vez de un booleano.
      if (code === "session_revoked" || code === "device_incomplete") {
        // Flag efímero para que `PosAuthGuard` sepa POR QUÉ no hay token —
        // sin esto, no hay forma de distinguir el motivo una vez que el token
        // ya se limpió, y el guard mostraría el copy equivocado ("pedí un
        // link de conexión" en vez del motivo real).
        if (typeof window !== "undefined") {
          window.sessionStorage.setItem(`punto.device.revoked.${module}`, code)
        }
        const qc = getSharedQueryClient()
        if (module === "pos" && qc) {
          // Cleanup COMPLETO de la sesión del módulo (token + catálogo + carrito
          // + hotkeys + lock + query cache). Este es el único punto del front que
          // sabe, con la señal explícita del server, que la sesión del device
          // murió — antes el cleanup completo colgaba del interceptor 401 del
          // cliente de PANEL, que no es dueño de esta credencial y lo disparaba
          // ante 401 que nada tenían que ver con el device (ver api-client.ts).
          moduleLogout(qc)
          // Empuja al guard a re-chequear ya, sin esperar el poll de 60s.
          qc.invalidateQueries({ queryKey: ["pos-bootstrap-auth"] })
        } else {
          clearDeviceToken(module)
        }
      }
    } catch {
      // Body no era JSON parseable (ej. HTML de error de infra) — no es
      // nuestro caso, seguir normal.
    }
  }

  return res
}
