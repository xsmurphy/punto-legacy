/**
 * Preaviso de timbrado por números restantes (D5, context/37).
 *
 * El corte duro al pasarse de `rangeTo` ya existe server-side
 * (`RangeExhaustedException`) — es el mínimo legal. Esto es lo que faltaba:
 * que la caja NO se entere al intentar cobrar. Umbrales fijos en números
 * absolutos, no en % del rango: el cajero razona en "cuántas facturas me
 * quedan", y un % de un rango chico avisaría demasiado tarde.
 *
 * Compartido entre el panel (badge en Control de Cajas, `registers-tab.tsx`)
 * y el POS (estado del pill único, `offline-status-pill.tsx`) para que los
 * dos cambien de color en el MISMO número — dos umbrales distintos serían
 * dos verdades.
 */

/** Debajo de esto la caja debe pedir timbrado nuevo YA (rojo). */
export const TIMBRADO_CRIT_AT = 50

/** Debajo de esto conviene iniciar el trámite (ámbar). */
export const TIMBRADO_WARN_AT = 200

export type TimbradoLevel = "ok" | "warn" | "crit"

export function timbradoLevel(remaining: number | null): TimbradoLevel {
  if (remaining === null) return "ok"
  if (remaining <= TIMBRADO_CRIT_AT) return "crit"
  if (remaining <= TIMBRADO_WARN_AT) return "warn"
  return "ok"
}
