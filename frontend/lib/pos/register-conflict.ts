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
import type { TenancyVerdict, TenancyVerdictKind } from "@/lib/pos/register-tenancy"

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
  /**
   * QUIÉN liberó la última tenencia (`'admin:{contactId}'` | `'device:…'`),
   * tal como lo manda `RegisterLeaseService::holderConflict()`. Solo llega con
   * `reason: 'revoked'`. `null` para un backend viejo o cualquier otra causa.
   */
  releasedBy: string | null
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
            releasedBy?: string | null
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
    // Nunca se renderiza crudo: solo se le pregunta si empieza con `admin:`
    // (ver `releasedByAdmin()`), así que un valor inesperado no puede llegar a
    // la pantalla.
    releasedBy: typeof details?.releasedBy === 'string' ? details.releasedBy : null,
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
    // El texto ya NO afirma "mientras estabas sin conexión": la liberación del
    // admin llega igual con el POS conectado —es el caso del incidente del
    // owner, que liberó desde el panel con la tablet online— y mandar al cajero
    // a revisar la red lo hace buscar un problema que no existe.
    //
    // Y nombra el veto: la caja quedó libre pero ESTE dispositivo no la retoma
    // solo (`RegisterLeaseService::isAdminRevoked()`). Sin esa frase el cajero
    // lee "está libre" y espera a que se arregle sola, que es justo lo que ya
    // no pasa.
    return releasedByAdmin(info)
      ? {
          title: "Un administrador liberó esta caja",
          body: "La caja quedó libre, y este dispositivo no la vuelve a tomar solo. Tomala de nuevo desde acá para seguir vendiendo.",
        }
      : {
          title: "Liberaron esta caja",
          body: "Esta caja se liberó desde el comercio. Está libre: volvé a tomarla para seguir vendiendo.",
        }
  }

  if (info?.reason === "released") {
    return {
      title: "Esta caja se cerró",
      body: "La caja se cerró desde este dispositivo. Está libre: volvé a tomarla para seguir vendiendo.",
    }
  }

  // `never_held` con el servidor respondiendo: la caja está LIBRE y este
  // dispositivo nunca la tuvo. Antes caía en el genérico de abajo, que manda a
  // conectarse — consejo inútil para un device que acaba de hablar con el
  // servidor. Desde que el latido dejó de tomar la caja sola (2026-09-01) este
  // es el estado normal de un POS recién abierto, no una anomalía.
  if (info?.reason === "never_held") {
    return {
      title: "Todavía no tomaste esta caja",
      body: "La caja está libre. Tomala para empezar a facturar desde este dispositivo.",
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

/**
 * ¿La última tenencia la cerró un ADMINISTRADOR desde el panel?
 *
 * Espejo exacto de `RegisterLeaseService::isAdminRevoked()`, que es lo que el
 * servidor evalúa para vetar la re-adquisición automática. Acá no decide nada:
 * solo elige el texto, para que lo que el cajero LEE coincida con lo que el
 * servidor HACE. El prefijo `admin:` lo escriben `register-lease.php` (panel,
 * "Liberar caja") y `devices.php` (revocar dispositivo); los caminos del propio
 * aparato escriben `device:…`.
 */
function releasedByAdmin(
  source: { reason?: TenancyDenyReason | null; denyReason?: TenancyDenyReason | null; releasedBy?: string | null } | null,
): boolean {
  const reason = source?.reason ?? source?.denyReason ?? null
  if (reason !== "revoked") return false
  return (source?.releasedBy ?? "").startsWith("admin:")
}

/**
 * Motivo CORTO del bloqueo, para el CONTROL que impide la acción — hoy el
 * tooltip del botón de cobrar (`CartBottom`, cart-panel.tsx).
 *
 * Existe separado de `registerConflictMessage` porque el lugar y el largo son
 * otros: título + cuerpo son para la pantalla bloqueante que explica qué
 * hacer; esto es una línea que contesta "¿por qué no puedo cobrar?" sin
 * sacarle el foco al carrito. La copia vive acá, con el resto de los textos de
 * tenencia, para que no se bifurque en el JSX.
 *
 * `verdict === null` es "todavía no se hidrató", no "sin tenencia": devuelve
 * el motivo genérico igual (el gate es fail-closed en los dos casos), pero sin
 * afirmar que alguien la tomó.
 */
export function registerBlockShortReason(verdict: TenancyVerdict | null): string | null {
  if (verdict?.canIssue) return null
  if (verdict?.holderDeviceName) return `Caja tomada por ${verdict.holderDeviceName}`
  // Caja LIBRE y este device no la tiene. Desde 2026-09-01 el POS ya no se la
  // queda solo en el latido, así que este texto tiene que decir que hay algo
  // que hacer y que lo hace el cajero — "hay que volver a tomarla" describía
  // un trámite de otro; "tocá para tomarla" nombra la acción y dónde está (el
  // toque abre `RegisterTakenPhase`, con el botón).
  // El motivo del bloqueo va en el CONTROL de la acción (tooltip del botón de
  // cobrar), no en una banda: nada se inserta ni mueve los botones del carrito.
  // Cuando la liberó un admin, decirlo cambia lo que el cajero hace — ya no
  // alcanza con esperar, el dispositivo no la retoma solo.
  if (verdict?.kind === "free") {
    return releasedByAdmin(verdict)
      ? "Un administrador liberó esta caja — tocá para tomarla de nuevo"
      : "Esta caja está libre — tocá para tomarla"
  }
  if (verdict?.kind === "stale") return "Hace más de 12 horas que no se confirma esta caja"
  if (verdict?.kind === "other-register") return "La tenencia confirmada es de otra caja"
  return "Caja sin tenencia confirmada"
}

/**
 * Traduce un veredicto de tenencia al par `{info, kind}` que consume la
 * pantalla bloqueante (`RegisterTakenPhase`), o `null` si la caja puede
 * emitir.
 *
 * Una sola función porque el bloqueo se evalúa en DOS momentos —al abrir el
 * diálogo de cobro y al confirmar la venta— y las dos veces tiene que decir
 * exactamente lo mismo. Fail-closed: `verdict === null` (todavía sin hidratar)
 * bloquea, con `kind: 'never'`.
 */
export function tenancyBlock(
  verdict: TenancyVerdict | null,
): { info: RegisterConflictInfo; kind: TenancyVerdictKind; canAcquire: boolean } | null {
  if (verdict?.canIssue) return null
  return {
    info: {
      holderDeviceId: verdict?.holderDeviceId ?? null,
      holderDeviceName: verdict?.holderDeviceName ?? null,
      expiresAt: null,
      reason: verdict?.denyReason ?? null,
      releasedBy: verdict?.releasedBy ?? null,
    },
    kind: verdict?.kind ?? "never",
    // Viaja con el bloqueo y no se recalcula en el JSX: es la misma decisión
    // que toma `evaluateGrant()` (ver `TenancyVerdict.canAcquire`) y tenerla
    // en un solo lugar es lo que evita que el botón y el mensaje se
    // contradigan. `verdict === null` (sin hidratar) ⇒ `true`: pedir la caja
    // es inofensivo y es justo lo que falta.
    canAcquire: verdict?.canAcquire ?? true,
  }
}
