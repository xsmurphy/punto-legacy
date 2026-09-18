/**
 * Primera mitad del canje de una invitación de dispositivo (`open`), y la
 * persistencia de la credencial cuando el canje la entrega.
 *
 * Vive acá y no dentro de `app/connect/[id]/connect-view.tsx` porque ahora la
 * usan DOS pantallas: el link de conexión de siempre y el pareo automático de
 * `/pos` (context/72 §9.2). Las dos tienen que canjear por el MISMO camino
 * público y de un solo uso —el que exige el `pairingSecret` de la mig 171 y
 * consume la invitación con un CAS—; una segunda implementación del canje
 * sería una segunda puerta de emisión de tokens de device.
 *
 * ── Sin credencial ──────────────────────────────────────────────────────────
 * `open` es público: lo ejecuta un navegador que todavía no tiene token de
 * device. No se adjunta NINGUNA credencial —ni la del device ni la del panel—
 * y `credentials: "omit"` evita que el browser mande cookies por su cuenta.
 */

import { setDeviceToken, toDeviceModule } from "@/lib/auth/device-token"
import { setDeviceClaims } from "@/lib/auth/device-claims"
import {
  clearPairingSecret,
  getPairingSecret,
  setPairingSecret,
} from "@/lib/auth/pairing-secret"

export const INVITATIONS_ENDPOINT = "/api/v1/device_invitations.php"

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
