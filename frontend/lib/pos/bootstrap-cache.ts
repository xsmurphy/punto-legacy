/**
 * Snapshot persistente del bootstrap del POS — lo que permite que la caja
 * ARRANQUE sin red.
 *
 * Por qué IndexedDB y no el Service Worker
 * ────────────────────────────────────────
 * `app/sw.ts` declaraba una ruta `NetworkFirst` para `/api/pos/bootstrap` con
 * la intención de cubrir esto. Nunca funcionó: serwist matchea las rutas con
 * `regExp.exec(url.href)` (ver `RegExpRoute`), y el patrón estaba anclado con
 * `^\/api\/...` contra un href que empieza en `https://` — no matcheaba nunca,
 * así que el bootstrap jamás se cacheó y el arranque offline moría igual.
 *
 * Corregir el patrón habría alcanzado para cachear, pero el Service Worker es
 * la storage EQUIVOCADA para este dato: la respuesta del bootstrap trae la
 * lista de clientes del comercio (PII) y la Cache API no participa del
 * `moduleLogout()` del device. Un snapshot en IndexedDB, en cambio, es de este
 * código: se guarda tipado, se lee tipado, y se purga con el resto de los
 * datos del comercio cuando el device se desvincula (`offline-db.ts`).
 *
 * Un solo dueño del bootstrap offline, entonces, y es este archivo.
 *
 * El snapshot puede estar VIEJO y eso es correcto: se muestra, no se esconde
 * (decisión de producto). `savedAt` viaja con él para que la UI pueda decir
 * desde cuándo son los datos, y el delta-sync de `lib/catalog/delta-sync.ts`
 * lo pone al día por watermark apenas vuelve la red.
 */

import { getPosOfflineDB } from '@/lib/pos/offline-db'
import type { PosBootstrap } from '@/lib/types/pos-bootstrap'

/** Clave del snapshot dentro del store `snapshots`. */
const BOOTSTRAP_KEY = 'pos-bootstrap'

export interface CachedBootstrap {
  bootstrap: PosBootstrap
  /** ISO — cuándo se guardó este snapshot (reloj del device). */
  savedAt: string
}

/**
 * Persiste el bootstrap recién traído de la red. Best-effort: si IndexedDB no
 * está disponible (modo privado, cuota llena), NO propaga el error — la sesión
 * online tiene que seguir funcionando aunque el device no pueda prepararse
 * para el corte.
 */
export async function saveBootstrapSnapshot(bootstrap: PosBootstrap): Promise<void> {
  if (typeof indexedDB === 'undefined') return
  try {
    const db = await getPosOfflineDB()
    await db.put('snapshots', {
      key: BOOTSTRAP_KEY,
      savedAt: new Date().toISOString(),
      payload: bootstrap,
    })
  } catch {
    // Ver docblock: guardar el snapshot es una mejora del próximo arranque,
    // nunca un requisito del actual.
  }
}

/**
 * Devuelve el último bootstrap persistido, o `null` si este device nunca
 * completó un bootstrap online (primer arranque sin haber sincronizado jamás
 * — el único caso en que la caja legítimamente no puede operar).
 *
 * Valida lo mínimo indispensable (`config` y `activeRegisterId` presentes):
 * un payload corrupto o de un schema viejo incompatible es peor que no tener
 * nada, porque la caja arrancaría a medias.
 */
export async function loadBootstrapSnapshot(): Promise<CachedBootstrap | null> {
  if (typeof indexedDB === 'undefined') return null
  try {
    const db = await getPosOfflineDB()
    const row = await db.get('snapshots', BOOTSTRAP_KEY)
    if (!row) return null
    const bootstrap = row.payload as PosBootstrap | null
    if (!bootstrap || typeof bootstrap !== 'object') return null
    if (!bootstrap.config || typeof bootstrap.activeRegisterId !== 'string') return null
    return { bootstrap, savedAt: row.savedAt }
  } catch {
    return null
  }
}
