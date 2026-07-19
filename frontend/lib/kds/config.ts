/**
 * Config local del dispositivo KDS — persistida en localStorage, NO en BD
 * (cada pantalla física puede querer columnas/densidad/estación distintas,
 * config del navegador del dispositivo, no del tenant). O2,
 * context/24-orders-module-plan.md — "KDS inteligente y adaptable: columnas
 * configurables".
 */

export type KdsColumnMode = "status" | "stream"
export type KdsDensity = "comfortable" | "compact"

export interface KdsConfig {
  columnMode: KdsColumnMode
  density: KdsDensity
  /** stationIds visibles — [] = todas las estaciones (comodín, mismo criterio que order_station.categoryids). */
  stationIds: string[]
  warnMin: number
  lateMin: number
}

export const DEFAULT_KDS_CONFIG: KdsConfig = {
  columnMode: "status",
  density: "comfortable",
  stationIds: [],
  warnMin: 10,
  lateMin: 20,
}

const KEY = "punto.kds.config"

export function loadKdsConfig(): KdsConfig {
  if (typeof window === "undefined") return DEFAULT_KDS_CONFIG
  try {
    const raw = window.localStorage.getItem(KEY)
    if (!raw) return DEFAULT_KDS_CONFIG
    const parsed = JSON.parse(raw) as Partial<KdsConfig>
    return { ...DEFAULT_KDS_CONFIG, ...parsed }
  } catch {
    return DEFAULT_KDS_CONFIG
  }
}

export function saveKdsConfig(config: KdsConfig): void {
  if (typeof window === "undefined") return
  window.localStorage.setItem(KEY, JSON.stringify(config))
}
