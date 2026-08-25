/**
 * Storage del secreto de sesión de pairing, por invitación.
 *
 * ── Qué es ──────────────────────────────────────────────────────────────────
 *
 * El link `/connect/{id}` es público: viaja por WhatsApp y queda en el chat.
 * Por eso el id de la invitación NO alcanza como identidad. En la primera
 * apertura el backend genera un secreto (mig 171), lo devuelve UNA sola vez en
 * claro y guarda sólo su sha256. Este módulo lo persiste del lado del
 * dispositivo; a partir de ahí, cada reload y cada poll lo presentan.
 *
 * Eso es lo que distingue "el navegador legítimo recargando la página" de "un
 * segundo navegador con el mismo link": el primero tiene el secreto, el
 * segundo no. Sin este mecanismo, cualquiera con el link podía adherirse a la
 * invitación y canjear un token para la misma caja — dos dispositivos en una
 * caja rompen la exclusividad del punto de expedición
 * (context/29-numeracion-y-exclusividad-de-caja.md).
 *
 * ── Por qué localStorage y no una cookie ────────────────────────────────────
 *
 * El pairing tiene que funcionar dentro de la PWA instalada, que en iOS tiene
 * su propio almacén separado de Safari. `localStorage` es el mismo almacén que
 * ya usa el Bearer del device (`lib/auth/device-token.ts`), así que el secreto
 * y el token que produce viven y mueren juntos: si la app instalada no tiene
 * token, tampoco tiene secreto, y pedir un link nuevo es la respuesta correcta
 * en vez de un canje reutilizable.
 *
 * Es de un solo uso por diseño: apenas el canje entrega el token, el secreto
 * ya no sirve para nada y se borra.
 */

const KEY_PREFIX = "punto.pairing.secret"

function storageKey(invitationId: string): string {
  return `${KEY_PREFIX}.${invitationId}`
}

export function getPairingSecret(invitationId: string): string | null {
  if (typeof window === "undefined") return null
  try {
    return window.localStorage.getItem(storageKey(invitationId))
  } catch {
    // Safari en modo privado puede tirar en localStorage. Sin secreto el
    // backend responde 409 y el usuario pide un link nuevo: fail-closed.
    return null
  }
}

export function setPairingSecret(invitationId: string, secret: string): void {
  if (typeof window === "undefined") return
  try {
    window.localStorage.setItem(storageKey(invitationId), secret)
  } catch {
    // idem: no romper el flujo por no poder persistir
  }
}

export function clearPairingSecret(invitationId: string): void {
  if (typeof window === "undefined") return
  try {
    window.localStorage.removeItem(storageKey(invitationId))
  } catch {
    // best-effort
  }
}
