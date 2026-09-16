/**
 * Pareo automático de `/pos` desde una sesión de panel (context/72 §9.2, D-P1).
 *
 * Un navegador SIN token de device que entra a `/pos` y SÍ tiene sesión de
 * panel con `settings.device.pair` se parea solo:
 *   - una caja disponible  → sin clic;
 *   - varias               → se elige cuál;
 *   - ninguna / sin permiso / sin sesión → la pantalla de link de siempre.
 *
 * ── Es el ÚNICO lugar donde las dos credenciales se cruzan, y no se tocan ───
 * Mandato token-only del POS (`feedback_pos_token_only_no_realms`): un cliente
 * HTTP = un realm. Acá hay tres requests y cada una lleva SU credencial o
 * ninguna:
 *
 *   1. listar cajas / crear la invitación → cliente del PANEL (`api`), con el
 *      Bearer del panel, contra un endpoint del realm `panel`. Devuelve solo
 *      el id de la invitación, nunca un token de device.
 *   2. canjear la invitación → `open` PÚBLICO, sin credencial
 *      (`redeem-invitation.ts`). De ahí, y solo de ahí, nace el token de device.
 *   3. tomar la caja → cliente del POS, con el Bearer del device recién
 *      canjeado.
 *
 * La sesión de panel nunca viaja a `/api/pos/*` ni se convierte en credencial
 * del POS (§9.4, arquitecturas rechazadas).
 *
 * Las dependencias se inyectan: la lógica se prueba en node sin browser
 * (`__tests__/auto-pair.test.ts`) y las implementaciones reales las arma el
 * componente (`components/layout/pos-first-use.tsx`).
 */

import { ApiError } from "@/lib/api-client"
import type { OpenInvitationResult, RedeemedDevice } from "@/lib/devices/redeem-invitation"

export interface AutoPairRegister {
  registerId: string
  registerName: string
  outletId: string
  outletName: string
}

export type AutoPairPlan =
  | { kind: "link" }
  | { kind: "auto"; register: AutoPairRegister }
  | { kind: "choose"; registers: AutoPairRegister[] }

export type PairOutcome = { ok: true; registerId: string } | { ok: false; message: string }

export interface AutoPairDeps {
  /** ¿Este navegador tiene sesión de panel? (token presente, sin validar). */
  hasPanelSession: () => boolean
  /** Cliente del PANEL: cajas disponibles para este usuario. */
  listRegisters: () => Promise<AutoPairRegister[]>
  /** Cliente del PANEL: invitación auto-aprobada. Solo el id. */
  createInvitation: (registerId: string) => Promise<{ id: string }>
  /** Canje público de un solo uso. */
  openInvitation: (invitationId: string) => Promise<OpenInvitationResult>
  /** Guarda el token de device canjeado. */
  persistDevice: (invitationId: string, device: RedeemedDevice) => void
  /** Cliente del POS: tomar la caja SI está libre (nunca se la quita a otro). */
  acquireRegister: (registerId: string) => Promise<unknown>
}

const GENERIC_ERROR = "No se pudo conectar la caja. Intentá de nuevo."

/**
 * Qué mostrar. Cualquier cosa que no sea una lista con cajas —sin sesión,
 * sesión vencida (401), sin permiso (403), sin red— cae en `link`: es la
 * pantalla que ya existía y no promete nada que no se pueda cumplir.
 */
export async function planAutoPair(deps: Pick<AutoPairDeps, "hasPanelSession" | "listRegisters">): Promise<AutoPairPlan> {
  if (!deps.hasPanelSession()) return { kind: "link" }
  let registers: AutoPairRegister[]
  try {
    registers = await deps.listRegisters()
  } catch {
    return { kind: "link" }
  }
  const valid = Array.isArray(registers) ? registers.filter((r) => r && r.registerId) : []
  if (valid.length === 0) return { kind: "link" }
  if (valid.length === 1) return { kind: "auto", register: valid[0] }
  return { kind: "choose", registers: valid }
}

/**
 * Parea este navegador a la caja elegida: invitación (panel) → canje (público)
 * → tomar la caja (device).
 *
 * Tomar la caja es best-effort: si otro dispositivo la tomó en el medio, el
 * servidor responde conflicto y NO se la quita (`RegisterLeaseService::claim`).
 * El pareo igual quedó hecho, y la caja muestra lo de siempre ("Tomar caja").
 */
export async function pairRegister(registerId: string, deps: AutoPairDeps): Promise<PairOutcome> {
  let invitationId: string
  try {
    const created = await deps.createInvitation(registerId)
    invitationId = created?.id ?? ""
  } catch (err) {
    // Los 403/409 del endpoint traen un mensaje para el comerciante ("Esa caja
    // ya está en uso en otro dispositivo"). Lo demás, genérico.
    if (err instanceof ApiError && (err.status === 403 || err.status === 409) && err.message) {
      return { ok: false, message: err.message }
    }
    return { ok: false, message: GENERIC_ERROR }
  }
  if (!invitationId) return { ok: false, message: GENERIC_ERROR }

  const opened = await deps.openInvitation(invitationId)
  if (opened.kind === "error") {
    // El backend explica en una línea por qué no (la caja se ocupó, el
    // permiso cambió). Los motivos codificados del link no le dicen nada acá.
    const coded = ["in-use", "not-found", "config-error"].includes(opened.reason)
    return { ok: false, message: coded ? GENERIC_ERROR : opened.reason }
  }
  if (opened.kind !== "token") {
    // `code` no debería pasar con una invitación auto-aprobada; si pasa, no
    // se queda esperando una aprobación que nadie va a dar.
    return { ok: false, message: GENERIC_ERROR }
  }
  if (opened.device.registerId && opened.device.registerId !== registerId) {
    return { ok: false, message: GENERIC_ERROR }
  }

  deps.persistDevice(invitationId, opened.device)

  try {
    await deps.acquireRegister(registerId)
  } catch {
    // Ver docblock: el pareo ya está hecho.
  }
  return { ok: true, registerId }
}
