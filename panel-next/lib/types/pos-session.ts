/**
 * Tipos de sesión de caja del POS.
 *
 * Una sesión de caja (PosSession) representa la combinación de:
 *   - El usuario autenticado (del JWT _jwt realm pos-app)
 *   - La caja (register) activa seleccionada al iniciar
 *   - El outlet al que pertenece la caja
 *
 * El guard de auth (`PosAuthGuard`) hidrata este contexto desde el
 * bootstrap del BFF y lo provee via React context a toda la UI del POS.
 *
 * Ver context/16-app-next-rewrite.md §7 Sprint 0.
 */

export interface PosSession {
  /** UUID del usuario autenticado. */
  userId: string | number
  /** Rol del usuario (mirrors Bootstrap.user.role). */
  role: number
  /** UUID del outlet activo (del JWT claim `oid`). */
  outletId: string
  outletName: string
  /** Caja seleccionada al iniciar sesión de caja.
   *  Null = no hay caja seleccionada aún (estado inicial). */
  registerId: string | null
  registerName: string | null
  /** Nombre de la empresa (para header del POS). */
  companyName: string
}
