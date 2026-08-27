/**
 * Storage del Bearer token del realm `panel`.
 *
 * ── Por qué existe (decisión del owner 2026-08-26, context/54) ──────────────
 * El panel autenticaba por cookie `_jwt_panel`. Una cookie viaja SOLA en toda
 * request same-origin: nadie la elige, el browser la adjunta. Como panel y
 * `/pos` se usan en el MISMO navegador, el server recibía DOS credenciales (la
 * cookie del panel + el Bearer del device) y tenía que adivinar cuál usar —
 * cuatro incidentes de sesión cruzada en dos meses (2026-07-19, 08-24, 08-25,
 * 08-26), con deslogueos del POS en la caja.
 *
 * Con el panel en Bearer, cada cliente HTTP lee SU clave y la adjunta a
 * propósito. El browser deja de mandar credenciales por su cuenta, así que
 * "llevo dos y el server elige mal" deja de ser expresable — no queda mitigado.
 *
 * ── La separación con el device ─────────────────────────────────────────────
 * Este módulo es la ÚNICA fuente del token de panel, y `lib/auth/device-token.ts`
 * la única del token de device. Claves distintas, módulos distintos, sin helper
 * genérico que resuelva "el token" (no debe existir: un `getToken()` sin realm
 * es exactamente cómo vuelve el bug). El guard
 * `lib/auth/__tests__/realm-token-separation.test.ts` falla en CI si el cliente
 * del panel importa el del device o al revés.
 *
 * ── localStorage y no sessionStorage ────────────────────────────────────────
 * A propósito: `sessionStorage` moriría al cerrar la pestaña y obligaría a
 * re-loguearse en cada arranque — un deslogueo más, justo lo que este cambio
 * viene a eliminar. La vida de la sesión NO la define el storage: la define el
 * server (`auth_session.expiresAt`, `PANEL_JWT_TTL`, 24h hoy), igual que con la
 * cookie. Guardar el token acá no lo hace durar un minuto más; el server lo
 * rechaza igual cuando vence, y la revocación desde `/settings/sessions` sigue
 * siendo inmediata.
 */

const STORAGE_KEY = "punto.panel.token"

export function getPanelToken(): string | null {
  if (typeof window === "undefined") return null
  try {
    return window.localStorage.getItem(STORAGE_KEY)
  } catch {
    // Modo incógnito / storage bloqueado: sin token, el guard manda al login.
    return null
  }
}

export function setPanelToken(token: string): void {
  if (typeof window === "undefined") return
  try {
    window.localStorage.setItem(STORAGE_KEY, token)
  } catch {
    // No romper el login si el browser no deja escribir: la sesión vive lo que
    // dure la pestaña en memoria del módulo que la haya leído.
  }
}

export function clearPanelToken(): void {
  if (typeof window === "undefined") return
  try {
    window.localStorage.removeItem(STORAGE_KEY)
  } catch {
    // ignore
  }
}
