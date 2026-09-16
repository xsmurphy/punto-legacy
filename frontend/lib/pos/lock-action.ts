/**
 * Qué hace "Bloquear" (owner, 2026-09-16 — context/72 §9.3).
 *
 * Con varios usuarios, o con la caja desbloqueada con PIN, bloquea y listo.
 * La excepción es el modo de un solo usuario (la caja abrió sin PIN): ahí el
 * bloqueo manual SÍ va a pedir PIN, así que antes hay que asegurarse de que el
 * operador conozca el suyo:
 *
 *   - PIN del signup (`pinIsDefault`) y con red → primero elige su código.
 *   - PIN del signup y SIN red → no se puede elegir (hace falta el servidor):
 *     botón deshabilitado con el motivo. Es el ÚNICO caso por red; con PIN
 *     propio bloquea normal sin conexión.
 *   - Sin ningún PIN cargado → tampoco: el lock screen no tendría contra qué
 *     validar y el bloqueo manual persiste tras recargar, así que la caja
 *     quedaría inutilizable hasta cargarle un código desde el panel.
 */

import type { PosUser } from "@/lib/types/pos-bootstrap"

export type LockAction =
  | { kind: "lock" }
  | { kind: "choose-pin" }
  | { kind: "blocked"; reason: string }

export const LOCK_BLOCKED_OFFLINE = "Conectate a internet para elegir tu código antes de bloquear"
export const LOCK_BLOCKED_NO_PIN = "Cargá tu código POS en Equipo, en el panel, para poder bloquear"

export function decideLockAction(input: {
  soleOperator: boolean
  operator: PosUser | null | undefined
  online: boolean
}): LockAction {
  if (!input.soleOperator || !input.operator) return { kind: "lock" }
  if (input.operator.pinIsDefault === true) {
    return input.online ? { kind: "choose-pin" } : { kind: "blocked", reason: LOCK_BLOCKED_OFFLINE }
  }
  if (!input.operator.pinhash) return { kind: "blocked", reason: LOCK_BLOCKED_NO_PIN }
  return { kind: "lock" }
}
