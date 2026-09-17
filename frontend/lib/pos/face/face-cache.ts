/**
 * Copia local de los rostros de la sucursal (RRHH F2, context/83 D5).
 *
 * ── Por qué el dispositivo guarda una copia ────────────────────────────────
 *
 * Porque si no, el reconocimiento no funciona sin internet — y la D7 dice que
 * marcar tiene que funcionar sin internet. La fuente de verdad sigue siendo el
 * SERVIDOR (alta desde cualquier quiosco, reposición de una tablet rota sin
 * re-enrolar a nadie, borrado al egreso que se propaga); esto es un caché, igual
 * que el catálogo de artículos.
 *
 * ── Y por qué en IndexedDB y no en la Cache API ────────────────────────────
 *
 * Mismo criterio que el snapshot del bootstrap: la Cache API no participa del
 * `moduleLogout()` del device, así que un dispositivo desvinculado del comercio
 * se quedaría con los vectores faciales de su equipo. Acá se borran con el resto
 * (`clearPosSnapshots()` limpia el store entero).
 *
 * Se guarda POR SUCURSAL: una tablet que cambia de sucursal no puede reconocer
 * con la lista de la anterior.
 */

import { getPosOfflineDB } from "@/lib/pos/offline-db"
import type { FaceCandidate } from "@/lib/pos/face/face-match"

/** Clave dentro del store `snapshots`. */
function cacheKey(outletId: string): string {
  return `pos-faces:${outletId || "sin-sucursal"}`
}

export interface CachedFaces {
  faces: FaceCandidate[]
  /** ISO — cuándo se guardó (reloj del device). */
  savedAt: string
}

/**
 * Guarda la lista recién traída. Best-effort: sin IndexedDB (modo privado,
 * cuota llena) la sesión online sigue andando igual, solo que este dispositivo
 * no se prepara para el corte.
 */
export async function saveFaces(outletId: string, faces: FaceCandidate[]): Promise<void> {
  if (typeof indexedDB === "undefined") return
  try {
    const db = await getPosOfflineDB()
    await db.put("snapshots", {
      key: cacheKey(outletId),
      savedAt: new Date().toISOString(),
      payload: faces,
    })
  } catch {
    // Ver docblock.
  }
}

/**
 * La última lista guardada de esta sucursal, o `null`.
 *
 * Valida la forma de lo que sale: un payload de otra versión del código sería
 * peor que no tener nada, porque el motor compararía contra basura y el
 * resultado sería un reconocimiento equivocado, no un error.
 */
export async function loadFaces(outletId: string): Promise<CachedFaces | null> {
  if (typeof indexedDB === "undefined") return null
  try {
    const db = await getPosOfflineDB()
    const row = await db.get("snapshots", cacheKey(outletId))
    if (!row) return null
    const payload = row.payload
    if (!Array.isArray(payload)) return null
    const faces = payload.filter(
      (f): f is FaceCandidate =>
        !!f &&
        typeof f.employeeId === "string" &&
        typeof f.modelVersion === "string" &&
        Array.isArray(f.embedding),
    )
    return { faces, savedAt: row.savedAt }
  } catch {
    return null
  }
}
