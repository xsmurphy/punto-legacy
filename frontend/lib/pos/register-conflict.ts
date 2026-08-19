/**
 * Shape + mensaje del 409 de tenencia de caja (`register_lease`,
 * context/29-numeracion-y-exclusividad-de-caja.md §4).
 *
 * Compartido entre `useRegisterClaim` (bootstrap del POS,
 * `api/v1/register/claim.php`) y `PayDialog` (venta, `api/v1/sales.php` /
 * `offline-sync.php`) — los tres arman el 409 con
 * `RegisterLeaseService::holderConflict()` + `apiConflict()`, mismo shape de
 * `details`.
 *
 * BUG REAL detectado 2026-08-19: nada en el POS llamaba nunca a
 * `claim.php` (F2 quedó construido pero sin caller), así que
 * `register_lease` nunca tenía una fila para ninguna caja — CADA venta
 * chocaba con este 409, con `holderDeviceId: null` (nadie la tenía tomada,
 * simplemente nunca se había tomado). El código viejo ignoraba
 * `holderDeviceId` y mostraba siempre "la está usando otro dispositivo" —
 * mensaje falso cuando en realidad NINGÚN dispositivo la tiene. Acá se
 * distingue explícitamente el caso "tomada por alguien real" del caso "sin
 * tenencia activa todavía" (mismo distingo que ya hacía el mensaje de
 * `sales.php`, que el frontend simplemente no leía).
 */

export interface RegisterConflictInfo {
  holderDeviceId: string | null
  holderDeviceName: string | null
  expiresAt: string | null
}

/** Lee `err.payload.error.details` del envelope `{ok:false, error:{...}}`
 *  que arma `apiConflict()` en un 409. Defensivo: si el shape no calza
 *  (mensaje sin details), cae a "sin tenedor conocido" en vez de romper. */
export function extractRegisterConflictInfo(err: {
  payload: unknown
}): RegisterConflictInfo {
  const payload = err.payload as
    | {
        error?: {
          details?: {
            holderDeviceId?: string | null
            holderDeviceName?: string | null
            expiresAt?: string | null
          }
        }
      }
    | null
  const details = payload?.error?.details
  return {
    holderDeviceId: details?.holderDeviceId || null,
    holderDeviceName: details?.holderDeviceName || null,
    expiresAt: details?.expiresAt || null,
  }
}

/** Título + cuerpo para la pantalla bloqueante, según haya o no un tenedor
 *  real. `holderDeviceId` es la señal correcta — `holderDeviceName` puede
 *  venir vacío igual (device sin nombre) sin que eso signifique "libre". */
export function registerConflictMessage(
  info: RegisterConflictInfo | null,
  expiresLabel: string | null,
): { title: string; body: string } {
  if (info?.holderDeviceId) {
    const holderLabel = info.holderDeviceName || "otro dispositivo"
    return {
      title: "Caja tomada",
      body: `Esta caja la está usando ${holderLabel}${expiresLabel ? ` — se libera ${expiresLabel}` : ""}. No se puede vender sin numeración válida.`,
    }
  }
  return {
    title: "Caja sin tenencia activa",
    body: "Este dispositivo todavía no tomó esta caja. Reintentá — si el problema sigue, pedile a un admin que la revise desde Ajustes → Sucursales → Cajas.",
  }
}
