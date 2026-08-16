"use client"

/**
 * Marca de agua del sync incremental del POS (context/43-sync-incremental.md).
 *
 * Persiste, POR SECCIÓN (items/customers/settings), la fecha del último sync
 * exitoso. SIEMPRE la que devuelve el SERVER (`serverTime` de `/v1/sync` —
 * ver `lib/catalog/delta-sync.ts`), NUNCA `Date.now()` del dispositivo: una
 * tablet con el reloj adelantado se perdería actualizaciones para siempre
 * (todo delta futuro le parecería "nada cambió desde mi fecha, que está en
 * el futuro"), y con el reloj atrasado pediría el catálogo completo en cada
 * sync (todo le parece "cambiado desde mi fecha vieja").
 *
 * localStorage, no IndexedDB: acá solo viven 3 timestamps chicos (no el
 * catálogo en sí, que sigue en memoria vía `useCatalogStore` + Service
 * Worker cache de `/api/pos/bootstrap`, ver `app/sw.ts`) — localStorage
 * alcanza y es síncrono, sin round-trip para leer 3 strings al reconectar.
 * Sobrevive el cierre del browser (a diferencia de sessionStorage/memoria).
 */

export interface SyncWatermarks {
  items: string | null
  customers: string | null
  settings: string | null
}

const EMPTY_WATERMARKS: SyncWatermarks = { items: null, customers: null, settings: null }

function storageKey(companyId: string): string {
  return `punto-pos-sync-watermarks:${companyId}`
}

export function loadWatermarks(companyId: string): SyncWatermarks {
  if (typeof window === "undefined") return { ...EMPTY_WATERMARKS }
  try {
    const raw = window.localStorage.getItem(storageKey(companyId))
    if (!raw) return { ...EMPTY_WATERMARKS }
    const parsed = JSON.parse(raw) as Partial<SyncWatermarks>
    return {
      items: typeof parsed.items === "string" ? parsed.items : null,
      customers: typeof parsed.customers === "string" ? parsed.customers : null,
      settings: typeof parsed.settings === "string" ? parsed.settings : null,
    }
  } catch {
    return { ...EMPTY_WATERMARKS }
  }
}

/** Actualiza solo los campos presentes en `partial` — no pisa el resto. */
export function saveWatermarks(companyId: string, partial: Partial<SyncWatermarks>): void {
  if (typeof window === "undefined") return
  try {
    const merged = { ...loadWatermarks(companyId), ...partial }
    window.localStorage.setItem(storageKey(companyId), JSON.stringify(merged))
  } catch {
    // localStorage lleno o bloqueado (modo privado) — no es fatal: el
    // próximo sync simplemente no encuentra watermark y cae a full.
  }
}
