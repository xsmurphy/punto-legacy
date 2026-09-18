/**
 * El canje de una invitación de dispositivo de punta a punta —apertura
 * (`open`), espera de la aprobación (`status`) y persistencia de la credencial—
 * como TRANSPORTE puro, sin React.
 *
 * Vive acá y no dentro de `app/connect/[id]/connect-view.tsx` porque lo usan
 * TRES superficies: el link de conexión de siempre, el pareo automático de
 * `/pos` (context/72 §9.2) y —desde 2026-09-18— el formulario de vinculación de
 * la pantalla "no conectado", que canjea SIN NAVEGAR (ver
 * `hooks/use-invitation-redeem.ts`). Las tres tienen que canjear por el MISMO
 * camino público y de un solo uso —el que exige el `pairingSecret` de la mig
 * 171 y consume la invitación con un CAS—; una segunda implementación del canje
 * sería una segunda puerta de emisión de tokens de device.
 *
 * ── Sin credencial ──────────────────────────────────────────────────────────
 * `open` y `status` son públicos: los ejecuta un navegador que todavía no tiene
 * token de device. No se adjunta NINGUNA credencial —ni la del device ni la del
 * panel— y `credentials: "omit"` evita que el browser mande cookies por su
 * cuenta.
 */

import { setDeviceToken, toDeviceModule, type DeviceModule } from "@/lib/auth/device-token"
import { setDeviceClaims } from "@/lib/auth/device-claims"
import {
  clearPairingSecret,
  getPairingSecret,
  setPairingSecret,
} from "@/lib/auth/pairing-secret"

export const INVITATIONS_ENDPOINT = "/api/v1/device_invitations.php"

/** Estados que el backend le puede contar a quien todavía no canjeó. */
export type InvitationStatus =
  | "pending"
  | "opened"
  | "approved"
  | "denied"
  | "expired"
  | "consumed"

/** Credencial de device que entrega un canje exitoso. */
export interface RedeemedDevice {
  token: string
  module: string
  deviceId?: string
  companyId?: string
  registerId?: string
}

export type OpenInvitationResult =
  /** El canje entregó el token en la apertura (reconexión o pareo automático). */
  | { kind: "token"; device: RedeemedDevice }
  /** Invitación normal: hay que mostrar el código y esperar la aprobación. */
  | { kind: "code"; userCode: string; module: string }
  /**
   * No sirve. `reason` es `"in-use"` (409: la abrió otro navegador), el
   * mensaje del backend, `"not-found"` o `"config-error"` (red).
   */
  | { kind: "error"; reason: string; status: number }

interface OpenData {
  userCode?: string
  module?: string
  autoApprove?: boolean
  token?: string
  deviceId?: string
  companyId?: string
  registerId?: string
  pairingSecret?: string | null
}

type FetchLike = (input: string, init?: RequestInit) => Promise<Response>

function envelopeData<T>(body: unknown): T {
  const b = body as { ok?: boolean; data?: T }
  return (b?.data ?? (body as T)) as T
}

function errorMessage(body: unknown): string | null {
  const b = body as { error?: { message?: string } } | null
  return b?.error?.message ?? null
}

/**
 * `open` de la invitación. Presenta el `pairingSecret` guardado si este
 * navegador ya la abrió antes (recarga), y guarda el que llega en la primera
 * apertura — es la ÚNICA vez que sale en claro.
 */
export async function openInvitation(
  invitationId: string,
  fetchImpl: FetchLike = fetch,
): Promise<OpenInvitationResult> {
  const form = new URLSearchParams()
  const stored = getPairingSecret(invitationId)
  if (stored) form.set("pairingSecret", stored)

  let res: Response
  try {
    res = await fetchImpl(`${INVITATIONS_ENDPOINT}?resource=open&id=${encodeURIComponent(invitationId)}`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: form.toString(),
      cache: "no-store",
      credentials: "omit",
    })
  } catch {
    return { kind: "error", reason: "config-error", status: 0 }
  }

  const body = await res.json().catch(() => null)
  if (!res.ok) {
    // 409 = la invitación ya tiene dueño y no somos nosotros: copy dedicado
    // en la pantalla, porque lo que hace falta decir es "pedí un link NUEVO".
    return {
      kind: "error",
      reason: res.status === 409 ? "in-use" : errorMessage(body) ?? "not-found",
      status: res.status,
    }
  }

  const data = envelopeData<OpenData>(body)
  if (data.pairingSecret) setPairingSecret(invitationId, data.pairingSecret)

  if (data.autoApprove && data.token && data.deviceId) {
    return {
      kind: "token",
      device: {
        token: data.token,
        module: data.module ?? "pos",
        deviceId: data.deviceId,
        companyId: data.companyId,
        registerId: data.registerId,
      },
    }
  }
  if (!data.userCode) {
    return { kind: "error", reason: "not-found", status: res.status }
  }
  return { kind: "code", userCode: data.userCode, module: data.module ?? "pos" }
}

/**
 * Un paso de la espera de aprobación.
 *
 * `retry` y `pending` se ven parecidos y NO son lo mismo: `pending` es el
 * servidor diciendo "todavía nadie aprobó", `retry` es no haber podido
 * preguntar (red caída, respuesta rara). Los dos siguen esperando, pero solo el
 * segundo significa que no sabemos nada.
 */
export type PollInvitationResult =
  | { kind: "pending"; status: InvitationStatus }
  | { kind: "approved"; device: RedeemedDevice }
  /** Terminal y sin token: el admin la rechazó, venció, o la usó otro. */
  | { kind: "closed"; status: Extract<InvitationStatus, "denied" | "expired" | "consumed"> }
  /** Terminal y definitivo: el link no sirve. Mismos `reason` que `open`. */
  | { kind: "error"; reason: string; status: number }
  /** No se pudo preguntar. Se reintenta en el próximo ciclo. */
  | { kind: "retry" }

/**
 * Una vuelta de `status`. Presenta el secreto de pairing de este navegador: es
 * lo ÚNICO que distingue a este dispositivo de cualquier otro que haya recibido
 * el link reenviado.
 *
 * El token se entrega UNA sola vez —el backend mueve la invitación a `consumed`
 * con un CAS en el mismo poll que la encuentra aprobada—, así que quien llame a
 * esto tiene que garantizar que no haya dos vueltas encimadas ni una vuelta
 * después de canjear. Eso lo hace el hook.
 */
export async function pollInvitation(
  invitationId: string,
  fetchImpl: FetchLike = fetch,
): Promise<PollInvitationResult> {
  const secret = getPairingSecret(invitationId)

  let res: Response
  try {
    res = await fetchImpl(`${INVITATIONS_ENDPOINT}?resource=status&id=${encodeURIComponent(invitationId)}`, {
      method: "GET",
      // El secreto va en header, NUNCA en la query string: es una credencial y
      // la query queda en logs de proxy y en el Referer.
      headers: secret ? { "X-Pairing-Secret": secret } : undefined,
      cache: "no-store",
      credentials: "omit",
    })
  } catch {
    return { kind: "retry" }
  }

  // 404/410/409 son definitivos: la invitación no existe, venció, ya fue usada
  // por otro dispositivo, o su device fue REVOCADO (`issueTokenForExistingDevice`
  // exige status=1). Antes esto caía en un `return` mudo y el poll seguía
  // girando: el operador veía "esperando aprobación" para siempre.
  if (res.status === 404 || res.status === 410 || res.status === 409) {
    const body = await res.json().catch(() => null)
    return {
      kind: "error",
      reason: res.status === 409 ? "in-use" : errorMessage(body) ?? "not-found",
      status: res.status,
    }
  }
  if (!res.ok) return { kind: "retry" }

  const data = envelopeData<OpenData & { status?: InvitationStatus }>(
    await res.json().catch(() => null),
  )
  if (!data?.status) return { kind: "retry" }

  if (data.status === "approved" && data.token) {
    return {
      kind: "approved",
      device: {
        token: data.token,
        module: data.module ?? "pos",
        deviceId: data.deviceId,
        companyId: data.companyId,
        registerId: data.registerId,
      },
    }
  }

  if (data.status === "denied" || data.status === "expired" || data.status === "consumed") {
    return { kind: "closed", status: data.status }
  }

  return { kind: "pending", status: data.status }
}

/**
 * ¿La invitación es de OTRO tipo de pantalla?
 *
 * Existe porque el formulario de vinculación acepta un link pegado a mano, y
 * nada impide pegar el de la caja en el reloj de la entrada. Antes eso pareaba
 * igual y dejaba al aparato con un token de un módulo que esa pantalla no lee:
 * quedaba "no conectado" para siempre y el link —de un solo uso— ya estaba
 * quemado.
 *
 * Compara el string CRUDO y no el normalizado a propósito: `toDeviceModule()`
 * manda lo desconocido a `"pos"`, así que un módulo que este bundle todavía no
 * conoce pasaría por caja. Comparando crudo, lo desconocido no coincide con
 * ninguna pantalla y se rechaza, que es el lado seguro.
 *
 * `expected` ausente = no se exige nada (`/connect/{id}` rutea por módulo, no
 * tiene una pantalla de destino previa).
 */
export function isForeignInvitation(module: string, expected?: DeviceModule | null): boolean {
  if (!expected) return false
  return module !== expected
}

/**
 * module (string libre de la invitación) → namespace tipado de
 * device-token/claims.
 *
 * Re-export del canónico (`lib/auth/device-token.ts`): acá vivía una copia del
 * `if` con la lista de tipos escrita a mano, que es un lugar más donde
 * olvidarse al sumar uno. Se mantiene el nombre exportado porque hay
 * call-sites que lo importan de este módulo.
 */
export { toDeviceModule }

/**
 * Guarda el token y los claims del device recién canjeado, y descarta el
 * secreto de pairing (la invitación ya está consumida: guardarlo sería
 * conservar una credencial muerta).
 *
 * El Bearer va namespaced por module (`punto.device.token.pos` / `...screen`):
 * sin eso, parear los dos tipos en el mismo browser pisaba el primero
 * (incidente 2026-06-28).
 */
export function persistRedeemedDevice(invitationId: string, device: RedeemedDevice): void {
  const mod = toDeviceModule(device.module)
  setDeviceToken(device.token, mod)
  if (device.companyId && device.registerId && device.deviceId) {
    setDeviceClaims(
      { companyId: device.companyId, registerId: device.registerId, deviceId: device.deviceId },
      mod,
    )
  }
  clearPairingSecret(invitationId)
}
