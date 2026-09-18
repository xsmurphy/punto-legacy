/**
 * Copia local del personal que puede marcar en esta sucursal (context/83 §9.2).
 *
 * ── Por qué el reloj guarda una copia ──────────────────────────────────────
 *
 * Porque si no, marcar deja de funcionar sin internet — y la D7 dice que tiene
 * que funcionar. La gente entra y sale igual. La fuente de verdad sigue siendo
 * el servidor; esto es un caché, igual que el catálogo de artículos de la caja.
 *
 * Hasta el §9.2 esta lista bajaba dentro del bootstrap del POS y quedaba en el
 * snapshot de cada caja. Ahora baja a UN aparato —el reloj— por
 * `/v1/attendance?resource=roster`, así que necesita su propio lugar donde
 * quedar guardada.
 *
 * ── IndexedDB y no localStorage ────────────────────────────────────────────
 *
 * Mismo criterio que los rostros (`face-cache.ts`): el store de snapshots se
 * limpia entero con `clearPosSnapshots()` cuando el dispositivo se desvincula
 * del comercio. Una copia en otro lado sobreviviría a la desvinculación con los
 * nombres y los códigos del equipo adentro.
 *
 * Se guarda POR SUCURSAL: un reloj que cambia de sucursal no puede seguir
 * fichando a la gente de la anterior.
 */

import { getPosOfflineDB } from "@/lib/pos/offline-db"
import type { ClockEmployee } from "@/lib/types/clock"

function cacheKey(outletId: string): string {
  return `clock-roster:${outletId || "sin-sucursal"}`
}

export interface CachedRoster {
  employees: ClockEmployee[]
  /** ISO — cuándo se guardó (reloj del device). */
  savedAt: string
}

/**
 * Guarda la lista recién traída. Best-effort: sin IndexedDB (modo privado,
 * cuota llena) el reloj sigue andando con red, solo que no se prepara para el
 * corte.
 */
export async function saveRoster(outletId: string, employees: ClockEmployee[]): Promise<void> {
  if (typeof indexedDB === "undefined") return
  try {
    const db = await getPosOfflineDB()
    await db.put("snapshots", {
      key: cacheKey(outletId),
      savedAt: new Date().toISOString(),
      payload: employees,
    })
  } catch {
    // Ver docblock.
  }
}

/**
 * La última lista guardada de esta sucursal, o `null`.
 *
 * Valida la forma de lo que sale: un payload escrito por otra versión del
 * código sería peor que no tener nada — el reloj ofrecería nombres a los que no
 * puede atribuir una marcación.
 */
export async function loadRoster(outletId: string): Promise<CachedRoster | null> {
  if (typeof indexedDB === "undefined") return null
  try {
    const db = await getPosOfflineDB()
    const row = await db.get("snapshots", cacheKey(outletId))
    if (!row) return null
    const payload = row.payload
    if (!Array.isArray(payload)) return null
    const employees = payload.filter(
      (e): e is ClockEmployee =>
        !!e && typeof e.id === "string" && typeof e.name === "string",
    )
    return { employees, savedAt: row.savedAt }
  } catch {
    return null
  }
}
