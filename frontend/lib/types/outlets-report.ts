import type { TimeBucket } from "@/lib/charts/granularity"

/**
 * Shapes de `/v1/reports/outlets` — espejo de `OutletsComparisonService`
 * (api/lib/Reports/OutletsComparisonService.php).
 *
 * Ventas, ganancia, margen, ticket y cantidad son los de `PeriodStats`, la
 * fórmula del KPI del dashboard: la fila total ES ese KPI y la suma de las
 * sucursales cierra con él.
 */

/** Base del delta: lo que hubo en el período anterior. */
export interface OutletPeriodPrevious {
  total: number
  revenue: number
  count: number
}

export interface OutletPeriodStats {
  /** Ventas: total - descuento - ventas internas. */
  total: number
  expenses: number
  /** Ganancia: ventas - egresos (la del dashboard). */
  revenue: number
  /** Margen en % (redondeado, piso 0; 100 sin egresos). */
  margin: number
  count: number
  /** Ticket promedio (bruto / cantidad). */
  customerAverage: number
  /** Clientes con al menos una compra en el período. En el total es distinct. */
  activeCustomers: number
  /** `null` = sin movimiento en el período anterior: no hay delta. */
  previous: OutletPeriodPrevious | null
}

export interface OutletSummaryRow extends OutletPeriodStats {
  outletId: string
  name: string
  /** `false` = sucursal inactiva que tuvo movimiento en el período. */
  active: boolean
}

export interface OutletsSummaryResponse {
  rows: OutletSummaryRow[]
  total: OutletPeriodStats
}

export interface OutletsSeriesResponse {
  granularity: "day" | "week" | "month"
  buckets: TimeBucket[]
  series: Array<{
    outletId: string
    name: string
    points: Array<{ bucket: string; total: number }>
  }>
}

export interface OutletOperationsRow {
  outletId: string
  name: string
  /** Cantidad de ventas por hora del día, de la más cargada a la menos. */
  hours: Array<{ hour: number; count: number }>
  /** Cobrado por medio de pago, de mayor a menor. */
  payments: Array<{ name: string; amount: number }>
  topItems: Array<{ itemId: string; name: string; units: number; total: number }>
}

export interface OutletsOperationsResponse {
  rows: OutletOperationsRow[]
}
