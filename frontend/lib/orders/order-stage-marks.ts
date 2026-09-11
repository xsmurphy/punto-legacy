/**
 * Demora entre etapas de UNA orden, leída de su línea de tiempo.
 *
 * Aplica las MISMAS reglas que el promedio del reporte
 * (`api/lib/Reports/OperationsService.php::stages`), para que lo que el detalle
 * dice de una orden sume a lo que el dashboard dice del período:
 *
 *   - solo eventos `scope === "order"`: los de ítem reusan los nombres
 *     `ready`/`delivered` con otro significado;
 *   - ENVÍO = primera vez en `sent` (sin evento de envío, la creación);
 *   - EN PROCESO = primera vez; LISTA = ÚLTIMA vez (el tiempo rehecho es
 *     trabajo, no espera); ENTREGADA = primera vez;
 *   - una diferencia negativa (reloj atrasado, etapa salteada y retomada) es
 *     `null`, no un número.
 *
 * `null` en una etapa = no se puede medir porque falta una de las dos marcas.
 * Nunca se rellena con cero: cero minutos sería una afirmación.
 */

import type { OrderEvent } from "@/hooks/use-orders"
import { parseNaive } from "@/lib/format-date"
import { resolveNumberLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"

export interface OrderStageDurations {
  /** Enviada → En proceso. */
  toProgress: number | null
  /** En proceso → Lista. */
  progressToReady: number | null
  /** Lista → Entregada. */
  readyToDelivered: number | null
  /** Enviada → Entregada. */
  total: number | null
  /** Entregada sin haber marcado "en proceso" o "lista". */
  skipped: boolean
}

function epoch(iso: string | null | undefined): number | null {
  if (!iso) return null
  const d = parseNaive(iso)
  return d ? d.getTime() : null
}

function minutesBetween(from: number | null, to: number | null): number | null {
  if (from === null || to === null || to < from) return null
  return (to - from) / 60_000
}

export function orderStageDurations(
  events: OrderEvent[],
  createdAt: string | null,
): OrderStageDurations {
  let sent: number | null = null
  let progress: number | null = null
  let ready: number | null = null
  let delivered: number | null = null

  for (const ev of events) {
    if (ev.scope !== "order") continue
    const t = epoch(ev.createdAt)
    if (t === null) continue
    switch (ev.toStatus) {
      case "sent":
        if (sent === null || t < sent) sent = t
        break
      case "in_progress":
        if (progress === null || t < progress) progress = t
        break
      case "ready":
        if (ready === null || t > ready) ready = t
        break
      case "delivered":
        if (delivered === null || t < delivered) delivered = t
        break
    }
  }
  const start = sent ?? epoch(createdAt)

  return {
    toProgress: minutesBetween(start, progress),
    progressToReady: minutesBetween(progress, ready),
    readyToDelivered: minutesBetween(ready, delivered),
    total: minutesBetween(start, delivered),
    skipped: delivered !== null && (progress === null || ready === null),
  }
}

/**
 * "12,5 min" / "1 h 20 min" con el separador decimal del tenant. Un decimal
 * por debajo de la hora (la diferencia entre 2 y 2,5 minutos importa en una
 * etapa corta); por encima, minutos enteros.
 */
export function formatMinutes(
  minutes: number,
  bootstrap: TenantLocaleConfig | null | undefined,
): string {
  const locale = resolveNumberLocale(bootstrap)
  if (minutes < 60) {
    const n = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(minutes)
    return `${n} min`
  }
  const h = Math.floor(minutes / 60)
  const m = Math.round(minutes - h * 60)
  if (m === 60) return `${h + 1} h`
  return m === 0 ? `${h} h` : `${h} h ${m} min`
}
