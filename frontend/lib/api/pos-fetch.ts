import { getDeviceToken, clearDeviceToken, type DeviceModule } from "@/lib/auth/device-token"
import { getSharedQueryClient } from "@/lib/auth/query-client-singleton"

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
  const res = await fetch(input, { ...init, credentials: "include", headers })

  if (res.status === 401) {
    try {
      const payload = (await res.clone().json()) as { code?: string } | null
      if (payload?.code === "session_revoked") {
        // Flag efímero para que `PosAuthGuard` sepa POR QUÉ no hay token —
        // sin esto, no hay forma de distinguir "revocado recién" de "nunca
        // se parea" una vez que el token ya se limpió, y el guard mostraría
        // el copy equivocado ("pedí un link de conexión" en vez de "fuiste
        // desconectado por un admin").
        if (typeof window !== "undefined") {
          window.sessionStorage.setItem(`punto.device.revoked.${module}`, "1")
        }
        clearDeviceToken(module)
        if (module === "pos") {
          // Empuja al guard a re-chequear ya, sin esperar el poll de 60s.
          getSharedQueryClient()?.invalidateQueries({ queryKey: ["pos-bootstrap-auth"] })
        }
      }
    } catch {
      // Body no era JSON parseable (ej. HTML de error de infra) — no es
      // nuestro caso, seguir normal.
    }
  }

  return res
}
