import { getDeviceToken } from "@/lib/auth/device-token"

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
 */
export function posFetch(input: string, init: RequestInit = {}): Promise<Response> {
  const headers = new Headers(init.headers)
  const token = getDeviceToken()
  if (token && !headers.has("Authorization")) {
    headers.set("Authorization", `Bearer ${token}`)
  }
  return fetch(input, { ...init, credentials: "include", headers })
}
