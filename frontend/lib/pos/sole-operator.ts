/**
 * ¿La caja opera sin PIN? (context/72 §9.3, D-P2)
 *
 * Regla del owner (2026-09-16): si la sucursal tiene UN solo usuario habilitado,
 * no tiene sentido pedir PIN — la caja no muestra el bloqueo y opera a nombre
 * de ese usuario.
 *
 * ── Esto es la copia LOCAL de una regla que decide el servidor ──────────────
 * El que emite la afirmación de operador (la que atribuye ventas, permisos y
 * auditoría) es `/v1/unlock-sole`, que cuenta el roster contra la BD. Esta
 * función solo decide si el lock screen se muestra, con el roster que ya bajó
 * al bootstrap — el mismo que valida el PIN sin red. Offline es equivalente al
 * PIN offline de hoy: desbloqueo local, sin afirmación firmada hasta que haya
 * red. Un empleado recién creado no puede operar hasta sincronizar, así que
 * un roster cacheado de uno no abre ningún hueco que el PIN offline no tenga.
 *
 * Es dinámica: no hay flag guardado. Cuando el roster pasa a dos, devuelve
 * null y el bloqueo vuelve solo.
 *
 * `rosterMissing` (el bootstrap no trajo la clave `users`) NUNCA habilita el
 * modo sin PIN: un roster ausente no dice nada sobre el comercio.
 */

import type { PosUser } from "@/lib/types/pos-bootstrap"

export function soleOperator(
  users: readonly PosUser[] | null | undefined,
  rosterMissing: boolean,
): { id: string; name: string } | null {
  if (rosterMissing || !Array.isArray(users) || users.length !== 1) return null
  const only = users[0]
  const id = only?.id != null ? String(only.id) : ""
  if (id === "") return null
  return { id, name: String(only.name ?? "") }
}
