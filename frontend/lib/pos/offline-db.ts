/**
 * Dueño ÚNICO de la IndexedDB del POS (`punto-pos-offline`).
 *
 * Antes cada consumidor abría la base por su cuenta: `offline-queue.ts` tenía
 * su propio `openDB('punto-pos-offline', 1, ...)` con su propio `DBSchema`. Con
 * un solo consumidor eso funcionaba; con dos, NO — dos módulos abriendo la
 * misma base con versiones distintas se bloquean mutuamente (`blocked` /
 * `VersionError`) y el que pierde se queda sin base, en silencio, en runtime.
 *
 * Por eso el schema y el singleton viven acá y nadie más llama a `openDB`:
 * agregar un store es subir `DB_VERSION` y extender el `upgrade` de este
 * archivo, no abrir una base paralela.
 *
 * Stores:
 *   - `pendingSales`  (v1) — cola de ventas emitidas sin conexión. Ver
 *                            `offline-queue.ts`.
 *   - `snapshots`     (v2) — snapshot del bootstrap/catálogo para que la caja
 *                            ARRANQUE sin red. Ver `bootstrap-cache.ts`.
 *   - `tenancy`       (v3) — última tenencia de caja CONFIRMADA por el
 *                            servidor, con su hora. Es lo que le permite al
 *                            device saber sin red si tiene derecho a emitir.
 *                            Ver `register-tenancy.ts`.
 *
 * Purga (PII): el snapshot contiene la lista de clientes del comercio. Al
 * desvincular el device hay que borrarlo — ver `purgeOfflineSnapshots()` y
 * `purgeAllOfflineData()`.
 */

import { openDB, deleteDB, type DBSchema, type IDBPDatabase } from 'idb'
import type { CreateSalePayload } from '@/lib/commands/create-sale'

export const DB_NAME = 'punto-pos-offline'
export const DB_VERSION = 3

// ── Filas ─────────────────────────────────────────────────────────────────────

export interface OfflineError {
  code: string
  message: string
}

export type OfflineSaleStatus = 'pending' | 'syncing' | 'failed'

export interface OfflineSaleRow {
  clientTempId: string
  invoiceNo: number
  sale: CreateSalePayload
  status: OfflineSaleStatus
  error?: OfflineError
  createdAt: string      // ISO
  lastAttemptAt?: string // ISO
  attempts: number
}

/**
 * Fila del store `snapshots`. `payload` es deliberadamente `unknown`: este
 * módulo es la plomería de la base y no debe conocer el shape de lo que
 * guardan sus clientes. El tipado concreto lo pone `bootstrap-cache.ts`.
 */
export interface SnapshotRow {
  key: string
  /** ISO del momento en que el snapshot se guardó (reloj del device). */
  savedAt: string
  payload: unknown
}

/**
 * Por qué el motivo de una tenencia DENEGADA importa: `taken_by_other` es el
 * único que el device no puede resolver solo. Espejo exacto del `reason` que
 * devuelve `RegisterLeaseService::holderConflict()` (api/lib/services).
 */
export type TenancyDenyReason =
  | 'taken_by_other'
  | 'revoked'
  | 'released'
  | 'never_held'

/**
 * Fila del store `tenancy` — la ÚLTIMA respuesta del servidor sobre "¿tengo
 * yo esta caja?", con la hora en que la dio. El tipado concreto y las reglas
 * de vigencia viven en `register-tenancy.ts`; acá está solo el shape que la
 * base guarda.
 */
export interface TenancyGrantRow {
  key: string
  /** Caja a la que se refiere el grant. Un grant de OTRA caja no vale. */
  registerId: string
  status: 'held' | 'denied'
  /** ISO — cuándo el servidor confirmó/denegó esto (reloj del device). */
  confirmedAt: string
  registerLeaseId: string | null
  denyReason: TenancyDenyReason | null
  holderDeviceId: string | null
  holderDeviceName: string | null
}

// ── Schema ────────────────────────────────────────────────────────────────────

export interface PosOfflineDB extends DBSchema {
  pendingSales: {
    key: string
    value: OfflineSaleRow
  }
  snapshots: {
    key: string
    value: SnapshotRow
  }
  tenancy: {
    key: string
    value: TenancyGrantRow
  }
}

// ── Singleton ─────────────────────────────────────────────────────────────────

let dbPromise: Promise<IDBPDatabase<PosOfflineDB>> | null = null

export function getPosOfflineDB(): Promise<IDBPDatabase<PosOfflineDB>> {
  if (!dbPromise) {
    dbPromise = openDB<PosOfflineDB>(DB_NAME, DB_VERSION, {
      upgrade(db) {
        // Sin `switch (oldVersion)`: crear-si-no-existe es idempotente y
        // cubre por igual el alta desde cero y el salto v1 → v2 (donde
        // `pendingSales` ya está y solo falta `snapshots`). El día que un
        // upgrade tenga que MIGRAR datos (no solo crear stores), ahí sí hace
        // falta ramificar por `oldVersion`.
        if (!db.objectStoreNames.contains('pendingSales')) {
          db.createObjectStore('pendingSales', { keyPath: 'clientTempId' })
        }
        if (!db.objectStoreNames.contains('snapshots')) {
          db.createObjectStore('snapshots', { keyPath: 'key' })
        }
        if (!db.objectStoreNames.contains('tenancy')) {
          db.createObjectStore('tenancy', { keyPath: 'key' })
        }
      },
    })
  }
  return dbPromise
}

// ── Purga ─────────────────────────────────────────────────────────────────────

/**
 * Borra SOLO los snapshots (bootstrap + catálogo + clientes), preservando la
 * cola de ventas.
 *
 * Es la purga que corre en `moduleLogout()` — o sea ante CUALQUIER muerte de
 * sesión, incluida una revocación remota del admin. Saca la PII (la lista de
 * clientes del comercio) del device y deja la caja sin datos para operar, que
 * es exactamente lo que se busca; pero NO toca `pendingSales`, porque ahí
 * puede haber ventas YA EMITIDAS E IMPRESAS que el backend todavía no recibió.
 * Borrarlas sería destruir documentos fiscales que existen en papel y no
 * existen en ningún otro lado (el invariante que `module-logout.ts` ya
 * declaraba). Sobreviven al re-pareo y el loop de sync las drena.
 *
 * Para el borrado total y explícito ver `purgeAllOfflineData()`.
 */
export async function purgeOfflineSnapshots(): Promise<void> {
  // El guard correcto es "¿hay IndexedDB?", no "¿hay window?": es la capacidad
  // que estas funciones realmente necesitan, y así valen igual en un worker o
  // en un runner sin DOM.
  if (typeof indexedDB === 'undefined') return
  try {
    const db = await getPosOfflineDB()
    await db.clear('snapshots')
    // El grant de tenencia se va con el snapshot, no con la cola: es una
    // afirmación sobre la SESIÓN de este device ("el servidor me confirmó que
    // esta caja es mía"), y una sesión muerta o revocada no puede seguir
    // autorizando la emisión de comprobantes sin red. Las ventas ya emitidas
    // (`pendingSales`) sobreviven igual — el grant no hace falta para
    // sincronizarlas, el próximo claim tras el re-pareo lo reconstruye.
    await db.clear('tenancy')
  } catch {
    // Base inaccesible (modo privado, cuota, corrupción): no hay nada que
    // purgar que podamos alcanzar, y esto corre dentro de un logout que NO
    // puede fallar por esto.
  }
}

/**
 * Borrado TOTAL: snapshots + cola de ventas + caches HTTP del POS.
 *
 * Solo para el desvinculado EXPLÍCITO del device ("Eliminar dispositivo del
 * comercio"), donde el operador confirmó que se lleva puesto lo pendiente —
 * el call-site tiene que avisarle si hay ventas sin sincronizar ANTES de
 * llamar acá (ver `pos-main-menu.tsx`).
 *
 * Cierra el singleton primero: `deleteDB` con una conexión abierta queda
 * colgada en `blocked` hasta que la última conexión se cierre, y en un tab
 * vivo eso no pasa nunca.
 */
export async function purgeAllOfflineData(): Promise<void> {
  try {
    if (dbPromise) {
      const db = await dbPromise
      db.close()
      dbPromise = null
    }
    if (typeof indexedDB !== 'undefined') {
      await deleteDB(DB_NAME)
    }
  } catch {
    dbPromise = null
  }

  // Cache API: el Service Worker guarda respuestas de `/api/pos/*` (ver
  // `app/sw.ts`) que también son datos del comercio. IndexedDB purgada y la
  // Cache API intacta dejaría la mitad de la PII en el device.
  try {
    if (typeof caches !== 'undefined') {
      const names = await caches.keys()
      await Promise.all(
        names.filter((n) => n.startsWith('pos-')).map((n) => caches.delete(n)),
      )
    }
  } catch {
    // Idem: el logout no puede fallar por esto.
  }
}
