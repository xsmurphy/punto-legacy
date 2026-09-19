/**
 * Horas pico del dashboard — lógica pura, sin React.
 *
 * El backend (`DashboardService::topHours`) manda las 6 horas con más unidades
 * vendidas, en orden de RANKING (`"14:00 Ventas"`, paralelo a `total` = ventas
 * y `units` = unidades). Para leerlas como un día hay que ordenarlas por hora;
 * el pico es la barra más alta de lo que se dibuja (ventas).
 */

import type { TopHoursWidget } from "@/hooks/use-dashboard-widget"

export interface HourBar {
  /** 0-23. */
  hour: number
  /** Cantidad de ventas en esa hora — lo que mide la barra. */
  sales: number
  /** Unidades vendidas; `null` si el backend no lo manda. */
  units: number | null
}

/** Hora del label del backend (`"14:00 Ventas"` → 14); `null` si no se entiende. */
export function parseHour(label: string | null | undefined): number | null {
  const m = /^\s*(\d{1,2})/.exec(String(label ?? ""))
  if (!m) return null
  const h = Number(m[1])
  return h >= 0 && h <= 23 ? h : null
}

/** Barras en orden del día. Horas ilegibles o repetidas se descartan. */
export function hourBars(data: TopHoursWidget | undefined): HourBar[] {
  const hours = Array.isArray(data?.hour) ? data.hour : []
  const seen = new Set<number>()
  const out: HourBar[] = []
  hours.forEach((label, i) => {
    const hour = parseHour(label)
    if (hour === null || seen.has(hour)) return
    seen.add(hour)
    const sales = Number(data?.total?.[i] ?? 0)
    const rawUnits = data?.units?.[i]
    const units = rawUnits === undefined || rawUnits === null ? NaN : Number(rawUnits)
    out.push({
      hour,
      sales: Number.isFinite(sales) ? Math.max(0, sales) : 0,
      units: Number.isFinite(units) ? units : null,
    })
  })
  return out.sort((a, b) => a.hour - b.hour)
}

/**
 * La hora pico: la de más ventas. Empate → más unidades; si sigue, la más
 * temprana. Sin ventas en ninguna hora no hay pico.
 */
export function peakHour(bars: HourBar[]): HourBar | null {
  let best: HourBar | null = null
  for (const b of bars) {
    if (b.sales <= 0) continue
    if (
      !best ||
      b.sales > best.sales ||
      (b.sales === best.sales && (b.units ?? 0) > (best.units ?? 0))
    ) {
      best = b
    }
  }
  return best
}

/** `14` → `"14–15 h"`; la hora 23 cierra en 24. */
export function hourRange(hour: number): string {
  return `${hour}–${hour + 1} h`
}
