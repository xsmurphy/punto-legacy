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
 *   - `pendingOps`    (v4) — cola GENÉRICA de mutaciones hechas sin red que no
 *                            son ventas: ajustes de la caja, hotkeys,
 *                            impresoras, apertura y cierre de caja. Es la
 *                            generalización de `pendingSales` (que sabe de
 *                            ventas y solo de ventas). Ver `pending-ops.ts`.
 *   - `shiftJournal`  (v5) — lo que ESTE dispositivo registró en el turno:
 *                            cada venta que emitió y cada movimiento de caja
 *                            que hizo, con o sin red. No es una cola (no se
 *                            envía nada) ni un cache del servidor: es la
 *                            memoria propia del device, y es lo único con lo
 *                            que puede mostrar un total sin preguntarle a
 *                            nadie. Ver `shift-journal.ts`.
 *
 * Por qué `shiftJournal` es un store y no se deriva de las colas: una venta
 * SALE de `pendingSales` en cuanto sincroniza, y una venta hecha con red nunca
 * pasa por ahí. Un total calculado desde la cola solo vería lo que todavía no
 * se envió — o sea que iría bajando a medida que la conexión vuelve, que es la
 * peor forma posible de mostrar plata.
 *
 * Purga (PII): el snapshot contiene la lista de clientes del comercio. Al
 * desvincular el device hay que borrarlo — ver `purgeOfflineSnapshots()` y
 * `purgeAllOfflineData()`.
 */

import { openDB, deleteDB, type DBSchema, type IDBPDatabase } from 'idb'
import type { CreateSalePayload } from '@/lib/commands/create-sale'

export const DB_NAME = 'punto-pos-offline'
export const DB_VERSION = 5

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
  /**
   * Desde cuándo este device tiene ESTA tenencia (hora local del tenant,
   * naive), o `null` si nunca la tuvo o el dato es de antes de que existiera
   * el campo.
   *
   * No es lo mismo que `confirmedAt`, que se renueva en cada latido: esto se
   * fija UNA vez, cuando aparece un `registerLeaseId` nuevo, y no se mueve
   * mientras la tenencia sea la misma. Existe para poder comparar contra la
   * apertura del turno: si el device tomó la caja DESPUÉS de que el turno se
   * abriera, hubo un rato del turno que no vio, y el total que muestra sin red
   * tiene que decirlo. Ver `local-shift-total.ts`.
   */
  heldSince?: string | null
  registerLeaseId: string | null
  denyReason: TenancyDenyReason | null
  holderDeviceId: string | null
  holderDeviceName: string | null
}

/**
 * Canal FIFO de una operación pendiente. Las operaciones de un MISMO canal se
 * aplican en el orden en que el cajero las hizo y una a la vez; las de canales
 * distintos son independientes entre sí.
 *
 * El canal no es un adorno: `drawer` transporta apertura y cierre de caja, y
 * aplicar un cierre antes que la apertura que lo precede no es "un poco
 * desordenado", es un arqueo mal armado. Los ajustes, en cambio, no dependen de
 * las impresoras, así que meterlos en la misma fila haría que un ajuste
 * rechazado frenara un cambio de impresora que no tiene nada que ver.
 */
export type PendingOpStream =
  | 'pos-config'
  | 'hotkeys'
  | 'drawer'
  // Conteo de stock (context/63 F1). Canal PROPIO y no `drawer`: los conteos
  // son eventos independientes entre sí y del turno (decisión explícita del
  // owner), así que no hay orden que preservar contra la apertura o el cierre
  // de caja. Compartir el canal solo lograría que un cierre rechazado frenara
  // un conteo que no tiene nada que ver con él.
  //
  // Sí es FIFO consigo mismo: dos conteos del mismo mostrador se aplican en el
  // orden en que se hicieron, porque el segundo ajusta sobre el saldo que dejó
  // el primero.
  | 'stock-count'
  | 'printer-bindings'

/** Qué operación es. Determina el transporte (ver `pending-ops-transport.ts`). */
export type PendingOpKind =
  | 'posConfig'
  | 'hotkeys'
  | 'drawerOpen'
  | 'drawerClose'
  | 'drawerExpense'
  | 'drawerIncome'
  | 'printerBindingCreate'
  | 'printerBindingUpdate'
  | 'printerBindingDelete'
  /**
   * Un conteo de stock COMPLETO: la lista con la que se contó y todas las
   * cantidades, en una sola operación.
   *
   * El grano no es casual. `setQty`/`bulkSetQty` del backend son
   * *last-write-wins* sin `opId`, así que encolarlas una por una rompería la
   * garantía de idempotencia que la cola promete en todo lo demás. Y no es un
   * problema de transporte: "poné 7 en este ítem" no tiene identidad propia.
   * El hecho que sí la tiene —"conté este mostrador"— es esta operación.
   */
  | 'stockCount'

export type PendingOpStatus = 'pending' | 'syncing' | 'failed'

/**
 * Fila del store `pendingOps` — una mutación que el cajero YA vio aplicada en
 * pantalla y que el servidor todavía no recibió.
 */
export interface PendingOpRow {
  /**
   * Identidad de la operación, generada por el cliente. Es la clave de
   * idempotencia: el mismo `opId` reenviado tras un timeout tiene que producir
   * el mismo efecto que la primera vez, no un segundo efecto.
   */
  opId: string
  stream: PendingOpStream
  kind: PendingOpKind
  /**
   * Orden de encolado dentro de la base. Se calcula como `max(seq) + 1` dentro
   * de la misma transacción del `put`, así que no hay empates ni depende del
   * reloj del device (que en estas cajas es justamente lo que no se puede dar
   * por bueno). Al vaciarse la cola vuelve a arrancar en 1: el orden solo
   * importa entre operaciones que coexisten.
   */
  seq: number
  /**
   * Caja para la que vale la operación. Una operación encolada para la caja A
   * NO se aplica si el device ahora es la caja B — ver el cerco de
   * `pending-ops-sync.ts`. Sin esto, un cambio de caja convertiría un ajuste
   * pendiente en un ajuste silencioso sobre la caja equivocada.
   */
  registerId: string
  /** Cuerpo de la mutación. El shape lo conoce el transporte, no la base. */
  payload: unknown
  /**
   * Texto para el cajero ("Cerrar caja — 1.250.000 Gs"). Se congela al
   * encolar: cuando la operación falla, tres días después, el estado del que
   * se derivaría el texto ya no existe.
   */
  label: string
  status: PendingOpStatus
  error?: OfflineError
  createdAt: string // ISO
  attempts: number
  lastAttemptAt?: string // ISO
}

/**
 * Qué clase de hecho registró el device. Los cuatro movimientos de caja son
 * los mismos que la cola de operaciones transporta, pero acá se anotan haya
 * habido red o no: el journal no distingue por dónde salió la operación, solo
 * por si ocurrió en esta caja y en este aparato.
 */
export type ShiftJournalKind =
  | 'sale'
  | 'drawerOpen'
  | 'drawerClose'
  | 'drawerExpense'
  | 'drawerIncome'

/** Un medio de pago aplicado en una venta, tal como se mandó/mandará al servidor. */
export interface ShiftJournalPayment {
  /** Nombre legible — el mismo que persiste `transactionPaymentType.name`. */
  name: string
  /** Slug/id del medio ('efectivo', 'tcredito', taxonomyId…). */
  type?: string
  total: number
}

/**
 * Fila del store `shiftJournal` — un hecho del turno registrado por ESTE
 * dispositivo.
 *
 * `date` es hora LOCAL del tenant, naive, del momento en que se operó: la
 * misma convención que `transactionDate` y que las fechas de caja. Es lo que
 * permite compararla con la apertura del turno sin convertir nada, y lo que
 * hace que un reloj de tablet corrido produzca un total mal recortado en vez
 * de un total mal sumado.
 */
export interface ShiftJournalRow {
  /**
   * Identidad del hecho, provista por quien lo registra: el `uid` de la venta
   * (el mismo que deduplica server-side) o el `opId`/uuid del movimiento. Es
   * la clave del store, así que anotar dos veces la misma venta —un reintento,
   * un re-render— no la suma dos veces.
   */
  entryId: string
  registerId: string
  kind: ShiftJournalKind
  /** Hora local del tenant, naive ('2026-08-23 14:32:07'). */
  date: string
  /** Monto de la operación: total cobrado (venta) o monto del movimiento. */
  amount: number
  /** Desglose por medio de pago. Solo en ventas. */
  payments?: ShiftJournalPayment[]
  /** Venta interna (consumo propio): no entra al arqueo, igual que server-side. */
  internal?: boolean
  /** ISO del reloj del device — solo para poder podar por antigüedad. */
  createdAt: string
}

// ── Schema ────────────────────────────────────────────────────────────────────

export interface PosOfflineDB extends DBSchema {
  pendingSales: {
    key: string
    value: OfflineSaleRow
  }
  pendingOps: {
    key: string
    value: PendingOpRow
  }
  snapshots: {
    key: string
    value: SnapshotRow
  }
  tenancy: {
    key: string
    value: TenancyGrantRow
  }
  shiftJournal: {
    key: string
    value: ShiftJournalRow
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
        if (!db.objectStoreNames.contains('pendingOps')) {
          db.createObjectStore('pendingOps', { keyPath: 'opId' })
        }
        if (!db.objectStoreNames.contains('shiftJournal')) {
          db.createObjectStore('shiftJournal', { keyPath: 'entryId' })
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
 * `pendingOps` sobrevive por el MISMO motivo, y no por simetría: ahí adentro
 * puede haber un CIERRE DE CAJA. El cajero contó la plata, cerró y se fue; que
 * la sesión se muera después no deshace nada de eso, y borrarlo dejaría el
 * turno abierto para siempre en la BD con su arqueo perdido. Lo que la cola
 * transporta de config (ajustes, hotkeys) es intrascendente al lado de eso,
 * pero tampoco hay razón para tirarlo. Si al re-parear el device quedó en OTRA
 * caja, el cerco por `registerId` de `pending-ops-sync.ts` frena la operación y
 * la muestra, en vez de aplicarla sobre la caja equivocada.
 *
 * `shiftJournal` tampoco se toca, y por la misma familia de razones: es el
 * registro de lo que este aparato emitió en el turno —con qué medios de pago y
 * por cuánto—, o sea la única base con la que puede mostrar un total sin red.
 * No contiene PII (montos y nombres de medios de pago, ningún cliente), así que
 * no hay nada que sacar de encima del device, y borrarlo dejaría al cajero
 * arqueando a ciegas después de un logout a mitad de turno.
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
