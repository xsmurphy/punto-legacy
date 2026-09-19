import { pctDelta } from "@/lib/reports/previous-range"
import type {
  OutletOperationsRow,
  OutletPeriodStats,
  OutletsSeriesResponse,
  OutletSummaryRow,
} from "@/lib/types/outlets-report"

/**
 * Lógica pura del reporte de Sucursales (`/reports/outlets`). Los números
 * llegan del servidor ya calculados con la fórmula del dashboard; acá solo se
 * deciden deltas, porcentajes de participación y qué se muestra.
 */

/**
 * Variación contra el período anterior. `undefined` = no hay base (la
 * sucursal no tuvo movimiento en el período anterior): la celda no muestra
 * delta. `null` = hubo movimiento pero la métrica era 0 (porcentaje infinito).
 */
export function outletDelta(
  stats: Pick<OutletPeriodStats, "total" | "revenue" | "previous">,
  metric: "total" | "revenue",
): number | null | undefined {
  if (!stats.previous) return undefined
  return pctDelta(stats[metric], stats.previous[metric])
}

export interface ShareItem {
  outletId: string
  name: string
  value: number
  /** Porcentaje sobre la suma de los valores POSITIVOS. */
  pct: number
}

/**
 * Participación de cada sucursal en ventas o ganancia.
 *
 * Solo entran las sucursales con valor positivo: una ganancia negativa no es
 * una "porción" del total. Con menos de dos porciones devuelve `[]` — un
 * gráfico de participación de una sola sucursal es el 100 % y no dice nada
 * (regla de dashboards: nada de gráficos de una sola porción).
 */
export function shareOf(rows: OutletSummaryRow[], metric: "total" | "revenue"): ShareItem[] {
  const positive = rows.filter((r) => r[metric] > 0)
  if (positive.length < 2) return []
  const sum = positive.reduce((acc, r) => acc + r[metric], 0)
  return positive
    .map((r) => ({
      outletId: r.outletId,
      name: r.name,
      value: r[metric],
      pct: sum > 0 ? (r[metric] / sum) * 100 : 0,
    }))
    .sort((a, b) => b.value - a.value)
}

/** Tope de líneas del gráfico de evolución: la paleta de gráficos tiene cinco tonos. */
export const MAX_SERIES = 5

export interface EvolutionChart {
  /** Claves `s0…sN` (un uuid no sirve como variable CSS `--color-…`). */
  series: Array<{ key: string; outletId: string; name: string }>
  /** Una fila por bucket del calendario, con el valor de cada serie. */
  data: Array<Record<string, string | number | boolean>>
  /** Hay más sucursales con ventas que líneas en el gráfico. */
  truncated: boolean
}

/**
 * Arma el gráfico de evolución: una línea por sucursal con ventas en el
 * período, las `MAX_SERIES` que más vendieron (el orden lo da `order`, que es
 * el de la tabla). Sucursales sin ventas no suman una línea plana en cero.
 */
export function evolutionChart(
  res: OutletsSeriesResponse,
  order: string[],
): EvolutionChart {
  const rank = new Map(order.map((id, i) => [id, i]))
  const withSales = res.series
    .filter((s) => s.points.some((p) => p.total !== 0))
    .sort((a, b) => (rank.get(a.outletId) ?? Infinity) - (rank.get(b.outletId) ?? Infinity))
  const shown = withSales.slice(0, MAX_SERIES)
  const series = shown.map((s, i) => ({ key: `s${i}`, outletId: s.outletId, name: s.name }))

  const data = res.buckets.map((b) => {
    const row: Record<string, string | number | boolean> = {
      bucket: b.bucket,
      end: b.end,
      partial: b.partial,
    }
    shown.forEach((s, i) => {
      row[`s${i}`] = s.points.find((p) => p.bucket === b.bucket)?.total ?? 0
    })
    return row
  })

  return { series, data, truncated: withSales.length > shown.length }
}

/** Las `n` horas con más ventas, en el orden en que llegan (ya vienen de mayor a menor). */
export function peakHours(row: OutletOperationsRow, n = 3): OutletOperationsRow["hours"] {
  return row.hours.filter((h) => h.count > 0).slice(0, n)
}

/** Medios de pago con su porcentaje sobre lo cobrado por la sucursal. */
export function paymentMix(row: OutletOperationsRow): Array<{ name: string; amount: number; pct: number }> {
  const sum = row.payments.reduce((acc, p) => acc + p.amount, 0)
  return row.payments.map((p) => ({ ...p, pct: sum > 0 ? (p.amount / sum) * 100 : 0 }))
}

/** "09:00" — hora del día como la escribe el comercio. */
export function hourLabel(hour: number): string {
  return `${String(hour).padStart(2, "0")}:00`
}

/**
 * Hasta cuántas sucursales la pestaña de operación muestra una card por
 * sucursal lado a lado. Con más, se elige la sucursal de un selector.
 */
export const OPERATIONS_CARDS_MAX = 4
