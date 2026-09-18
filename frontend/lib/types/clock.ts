/**
 * Tipos del reloj de marcación (context/83 §9.2).
 *
 * Viven acá y no en `pos-bootstrap.ts` porque el reloj dejó de ser una pantalla
 * de la caja: es un dispositivo propio (`device.module = 'clock'`) con su propio
 * bootstrap slim, y su roster ya no viaja en el payload del POS.
 */

/**
 * Una persona que puede marcar en esta sucursal.
 *
 * Proyección mínima — ni sueldo, ni documento, ni teléfono. El reloj solo
 * necesita saber a quién corresponde un código y si a esa persona le toca
 * entrar o salir.
 */
export interface ClockEmployee {
  id: string
  name: string
  jobTitle: string | null
  /**
   * SHA-256 del PIN de la persona (`contact.pinhash`), el mismo que abre la
   * caja. `null` = no tiene código y se identifica por el rostro, que desde el
   * §9.3 es un caso NORMAL y no una carga incompleta.
   */
  pinHash: string | null
  /**
   * Última marcación conocida POR EL SERVIDOR. Es una SUGERENCIA para proponer
   * entrada o salida, nunca una regla: sin red el dato es viejo por definición
   * (la persona pudo marcar en otro aparato), y la pantalla deja cambiarlo de un
   * toque. `null` = nunca marcó, o el reloj todavía no sincronizó.
   */
  lastKind: "in" | "out" | null
  lastMarkedAt: string | null
}
