/**
 * Estado de un ítem respecto de sus umbrales de stock.
 *
 * Vive acá y no en cada pantalla porque el listado, el detalle y cualquier
 * reporte futuro tienen que pintar el MISMO semáforo: si el criterio se
 * duplica, dos pantallas terminan diciendo cosas distintas del mismo ítem.
 */

export type StockStatus =
  /** Sin stock. Es su propio estado, no "bajo mínimo": la diferencia entre
   *  quedarse corto y no poder vender es la que le importa al que repone. */
  | "quiebre"
  /** En o por debajo del mínimo. */
  | "bajo"
  /** Por encima del máximo — capital inmovilizado. */
  | "sobre"
  /** Dentro de rango, o sin umbrales definidos. */
  | "ok"

export interface StockStatusInput {
  qty: number | null | undefined
  min: number | null | undefined
  max: number | null | undefined
  /** Ítems que no llevan stock (servicios, combos) no tienen estado. */
  tracked?: boolean | number | null
}

/**
 * `null` = el ítem no se controla por stock y no debe pintarse de ningún color.
 *
 * Los umbrales en `null` se ignoran de forma independiente: se puede tener
 * mínimo sin máximo. `0` SÍ es un umbral válido — es "avisame al llegar a
 * cero" — así que la comparación es contra `null`/`undefined`, nunca contra
 * falsy, que trataría el 0 como "sin definir".
 */
export function stockStatus({ qty, min, max, tracked = true }: StockStatusInput): StockStatus | null {
  const llevaStock = tracked === true || tracked === 1
  if (!llevaStock) return null

  const saldo = typeof qty === "number" ? qty : 0

  if (saldo <= 0) return "quiebre"
  if (min !== null && min !== undefined && saldo <= min) return "bajo"
  if (max !== null && max !== undefined && saldo > max) return "sobre"
  return "ok"
}

/** Clases de color por estado. `ok` no pinta: si todo se colorea, nada resalta. */
export const STOCK_STATUS_CLASS: Record<StockStatus, string> = {
  quiebre: "text-destructive font-medium",
  bajo: "text-amber-600 dark:text-amber-500 font-medium",
  sobre: "text-blue-600 dark:text-blue-400",
  ok: "",
}

export const STOCK_STATUS_LABEL: Record<StockStatus, string> = {
  quiebre: "Sin stock",
  bajo: "Bajo mínimo",
  sobre: "Sobre máximo",
  ok: "En rango",
}
