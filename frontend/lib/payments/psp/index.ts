/**
 * Registry de pasarelas de pago (PSP) con cobro por QR desde la caja.
 *
 * Espejo del `PspCatalog` de PHP (`api/lib/PaymentMethods/PspCatalog.php`):
 * el backend decide qué medio de pago provisiona cada pasarela y si su canal
 * QR está prendido para el tenant; este registry decide cómo se cobra desde
 * el POS. Las dos mitades se enganchan por dos claves que TIENEN que
 * coincidir: `provider` y `systemKey`.
 *
 * ── Sumar una pasarela ──────────────────────────────────────────────────────
 *
 *   1. `frontend/lib/payments/psp/<provider>.ts` — el adapter (3 métodos).
 *   2. Una línea en `ADAPTERS` acá abajo.
 *   3. La entrada equivalente en `PspCatalog::QR_PROVIDERS` (backend).
 *
 * El dialog de cobro, el polling, los estados, la pantalla del cliente, el
 * filtrado del botón y la degradación sin red ya son compartidos.
 */

import type { PspQrAdapter } from "../psp-qr"
import { bancardQrAdapter } from "./bancard"

const ADAPTERS: readonly PspQrAdapter[] = [bancardQrAdapter]

/** systemKeys de medios de pago que abren un cobro con QR de pasarela. */
export const PSP_QR_SYSTEM_KEYS: readonly string[] = ADAPTERS.map((a) => a.systemKey)

/** true si ese medio de pago cobra por QR de pasarela (y por lo tanto exige red). */
export function isPspQrSystemKey(systemKey: string | null | undefined): boolean {
  return systemKey != null && PSP_QR_SYSTEM_KEYS.includes(systemKey)
}

/** Adapter que corresponde a un medio de pago, o null si no es de pasarela. */
export function pspQrAdapterForSystemKey(systemKey: string | null | undefined): PspQrAdapter | null {
  if (systemKey == null) return null
  return ADAPTERS.find((a) => a.systemKey === systemKey) ?? null
}

/**
 * ¿Está habilitado el canal QR de la pasarela dueña de ese medio de pago?
 *
 * Lee el mapa `pspQrEnabled` que resuelve el backend. `bancardQrEnabled` es el
 * fallback legacy: un POS con la config cacheada de antes del refactor no
 * tiene el mapa, y sin fallback perdería el botón del QR estando offline.
 */
export function isPspQrChannelEnabled(
  systemKey: string | null | undefined,
  config: { pspQrEnabled?: Record<string, boolean>; bancardQrEnabled?: boolean } | null | undefined,
): boolean {
  const adapter = pspQrAdapterForSystemKey(systemKey)
  if (adapter === null) return true // no es de pasarela: no lo filtra este gate
  const fromMap = config?.pspQrEnabled?.[adapter.provider]
  if (typeof fromMap === "boolean") return fromMap
  if (adapter.provider === "bancard") return config?.bancardQrEnabled === true
  return false
}

export type { PspQrAdapter }
