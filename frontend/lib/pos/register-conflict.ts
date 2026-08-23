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

// `import type` a propósito en las dos: `register-tenancy.ts` importa de este
// módulo (`extractRegisterConflictInfo`), así que un import de VALOR cerraría
// un ciclo en runtime. Los tipos se borran al compilar — no hay ciclo real.
import type { TenancyDenyReason } from "@/lib/pos/offline-db"
import type { TenancyVerdictKind } from "@/lib/pos/register-tenancy"

export interface RegisterConflictInfo {
  holderDeviceId: string | null
  holderDeviceName: string | null
  expiresAt: string | null
  /**
   * Motivo server-side (`RegisterLeaseService::holderConflict()`). Antes el
   * front infería la causa de si `holderDeviceId` venía o no — eso distingue
   * "tomada" de "libre", pero mete en una sola bolsa tres situaciones de caja
   * libre con remedios distintos: me la revocaron, la cerré yo, nunca la tuve.
   * `null` solo para respuestas de un backend viejo.
   */
  reason: TenancyDenyReason | null
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
            reason?: string | null
          }
        }
      }
    | null
  const details = payload?.error?.details
  const rawReason = details?.reason
  return {
    holderDeviceId: details?.holderDeviceId || null,
    holderDeviceName: details?.holderDeviceName || null,
    expiresAt: details?.expiresAt || null,
    reason: KNOWN_REASONS.includes(rawReason as TenancyDenyReason)
      ? (rawReason as TenancyDenyReason)
      : null,
  }
}

/** Allowlist: un `reason` desconocido (backend viejo o nuevo) cae a `null` y
 *  el mensaje usa el camino defensivo, nunca renderiza un string crudo. */
const KNOWN_REASONS: readonly TenancyDenyReason[] = [
  'taken_by_other',
  'revoked',
  'released',
  'never_held',
]

/**
 * Título + cuerpo para la pantalla bloqueante (§7 de context/29), UNA causa
 * por mensaje.
 *
 * Hasta 2026-08-23 había dos textos: "la está usando otro dispositivo" y un
 * genérico "todavía no tomó esta caja". El resto de las causas —me la
 * revocaron mientras estaba sin red, la cerré yo, la confirmación venció— caían
 * todas en el genérico, que le pide al cajero "reintentá" incluso cuando
 * reintentar no puede funcionar. Cada rama de acá dice qué pasó Y qué hacer,
 * porque el remedio cambia: uno necesita a un admin, los otros solo conexión.
 *
 * `kind` viene del veredicto local (`evaluateGrant`) e `info` del 409 del
 * servidor; los dos caminos convergen acá para que el cajero lea lo mismo
 * venga de donde venga.
 */
export function registerConflictMessage(
  info: RegisterConflictInfo | null,
  expiresLabel: string | null,
  kind?: TenancyVerdictKind,
): { title: string; body: string } {
  // Otro device la tiene AHORA — el único caso que no se resuelve con
  // conexión. Prioridad sobre `kind`: es la causa más concreta que hay.
  if (info?.holderDeviceId || info?.reason === "taken_by_other") {
    const holderLabel = info?.holderDeviceName || "otro dispositivo"
    return {
      title: "Caja tomada por otro dispositivo",
      body: `Esta caja la está usando ${holderLabel}${expiresLabel ? ` — se libera ${expiresLabel}` : ""}. Para vender desde acá, pedile a un administrador que la libere en Ajustes → Sucursales → Cajas.`,
    }
  }

  if (info?.reason === "revoked") {
    return {
      title: "Liberaron esta caja",
      body: "Un administrador liberó esta caja mientras este dispositivo estaba sin conexión. Está libre: volvé a tomarla para seguir vendiendo.",
    }
  }

  if (info?.reason === "released") {
    return {
      title: "Esta caja se cerró",
      body: "La caja se cerró desde este dispositivo. Está libre: volvé a tomarla para seguir vendiendo.",
    }
  }

  // Sin `reason` del servidor, manda el veredicto local — el device sabe por
  // qué se está bloqueando a sí mismo aunque nunca haya podido preguntar.
  if (kind === "stale") {
    return {
      title: "Hace mucho que no se confirma esta caja",
      body: "Este dispositivo no puede verificar desde hace más de 12 horas que esta caja sigue siendo suya. Conectate a internet para confirmarla antes de emitir un comprobante.",
    }
  }

  if (kind === "other-register") {
    return {
      title: "Cambió la caja de este dispositivo",
      body: "La tenencia confirmada es de otra caja. Conectate a internet para tomar la caja actual antes de vender.",
    }
  }

  return {
    title: "Caja sin tenencia confirmada",
    body: "Este dispositivo todavía no tomó esta caja, así que no puede emitir comprobantes con su numeración. Conectate a internet para tomarla — si el problema sigue, pedile a un admin que la revise desde Ajustes → Sucursales → Cajas.",
  }
}
