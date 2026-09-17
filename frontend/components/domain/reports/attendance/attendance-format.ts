/**
 * Cómo se lee un reporte de asistencia (context/83 F1).
 *
 * Los minutos NO se muestran como minutos: "492" no le dice nada a nadie sobre
 * una jornada. Todo lo que este archivo hace es traducir el dato crudo del
 * backend a lo que una persona espera ver en una planilla de horas.
 */

import type { AttendanceReviewReason } from "@/hooks/use-attendance"

/** 492 → "8h 12m". 0 → "0h". Negativos no existen (el backend los recorta). */
export function formatMinutes(total: number): string {
  const minutes = Math.max(0, Math.round(total))
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${m}m`
  if (m === 0) return `${h}h`
  return `${h}h ${m}m`
}

/**
 * Por qué quedó para revisar, en palabras del comercio.
 *
 * Sin códigos, sin jerga y sin explicar el mecanismo: el encargado necesita
 * saber QUÉ mirar, no cómo funciona el flag. Es la regla de copy del proyecto
 * — los casos puntuales se resuelven en silencio.
 */
export function reviewReasonLabel(reason: AttendanceReviewReason | null): string {
  switch (reason) {
    case "no_camera":
      return "El dispositivo no tiene cámara"
    case "camera_denied":
      return "La cámara estaba bloqueada"
    case "photo_failed":
      return "No se pudo sacar la foto"
    case "photo_lost":
      return "La foto no llegó"
    case "pin_stale":
      return "El código no coincide con el del legajo"
    case "employee_inactive":
      return "La persona ya no figura activa"
    default:
      return "Para revisar"
  }
}

/**
 * La tardanza, o el silencio honesto.
 *
 * `null` y `0` son cosas distintas y se muestran distinto: `null` es "no hay
 * horario declarado contra el cual medir" y se escribe con un guión, `0` es
 * "llegó a horario" y se escribe. Colapsarlos haría que un equipo entero sin
 * horario cargado apareciera como perfectamente puntual.
 */
export function formatLateness(lateMinutes: number | null): string {
  if (lateMinutes === null) return "—"
  if (lateMinutes === 0) return "En horario"
  return formatMinutes(lateMinutes)
}
