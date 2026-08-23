/**
 * Arranque en frío del POS sin red.
 *
 * Cubre lo que no se puede verificar leyendo el código ni mirando la pantalla:
 * la migración de la IndexedDB con ventas ya encoladas (devices que hoy están
 * en la calle con la v1), y el árbol de decisión red/cache/nada del bootstrap.
 *
 * `environment: "node"` + `fake-indexeddb`: sin browser, sin servidor.
 */

import { beforeEach, describe, expect, it, vi } from "vitest"
import "fake-indexeddb/auto"
import { openDB } from "idb"

// `posApi.get` es el borde de red del POS. Mockearlo reproduce el corte de
// conexión exacto (el `TypeError` que tira `fetch`) sin tocar red.
const posGet = vi.fn()
vi.mock("@/lib/api/pos-client", () => ({
  posApi: { get: (path: string) => posGet(path) },
}))

// Imports ESTÁTICOS y un solo grafo de módulos para todo el archivo. Nada de
// `vi.resetModules()` + `await import()`: eso crea una segunda copia de cada
// módulo, y entonces el `ApiError` del test deja de ser `instanceof` el del
// código bajo prueba, y el singleton de la base del test deja de ser el que
// usa `bootstrap-source`. Los tests fallaban por eso y no por el código.
import { ApiError } from "@/lib/api-client"
import {
  getPosOfflineDB,
  purgeOfflineSnapshots,
  purgeAllOfflineData,
  DB_NAME,
} from "@/lib/pos/offline-db"
import { saveBootstrapSnapshot, loadBootstrapSnapshot } from "@/lib/pos/bootstrap-cache"
import { fetchPosBootstrap } from "@/lib/pos/bootstrap-source"
import { enqueue, getCount } from "@/lib/pos/offline-queue"
import { useOfflineSyncStore } from "@/lib/pos/offline-sync-store"

/** Bootstrap mínimo que pasa la validación de `loadBootstrapSnapshot`. */
function fakeBootstrap(overrides: Record<string, unknown> = {}) {
  return {
    config: { companyId: 7, name: "Comercio" },
    activeRegisterId: "reg-1",
    items: [{ id: "i1", name: "Café" }],
    customers: [{ id: "c1", name: "Cliente Uno" }],
    registers: [],
    outlets: [],
    users: [],
    ...overrides,
  } as never
}

/**
 * Estado limpio entre tests: se borra la base de verdad con la misma función
 * que corre al desvincular el device — así el reset también ejercita la purga
 * en cada iteración, en vez de simular una base vacía por otro camino.
 */
beforeEach(async () => {
  await purgeAllOfflineData()
  posGet.mockReset()
  useOfflineSyncStore.setState({ catalogFromCache: false, catalogCachedAt: null })
})

describe("migración de la base v1 → v3", () => {
  it("conserva las ventas encoladas de un device que ya venía con la v1", async () => {
    // Un device en la calle: base v1, solo `pendingSales`, con una venta
    // emitida e impresa esperando conexión.
    const v1 = await openDB(DB_NAME, 1, {
      upgrade(db) {
        db.createObjectStore("pendingSales", { keyPath: "clientTempId" })
      },
    })
    await v1.put("pendingSales", {
      clientTempId: "uid-1",
      invoiceNo: 42,
      sale: {},
      status: "pending",
      attempts: 0,
      createdAt: new Date().toISOString(),
    })
    v1.close()

    // Abrirla con el schema nuevo NO puede perder esa venta: es un documento
    // fiscal que existe en papel y en ningún otro lado.
    const db = await getPosOfflineDB()
    expect(db.version).toBe(3)
    expect([...db.objectStoreNames].sort()).toEqual([
      "pendingSales",
      "snapshots",
      "tenancy",
    ])

    const row = await db.get("pendingSales", "uid-1")
    expect(row?.invoiceNo).toBe(42)
  })

  it("v2 → v3 agrega `tenancy` sin tocar lo que ya había", async () => {
    // El salto que introduce el grant de tenencia (incidente 2026-08-23). Un
    // device que hoy está en la calle con la v2 puede tener ventas encoladas Y
    // un snapshot del catálogo: el upgrade solo agrega un store, no migra ni
    // borra datos, y esto lo fija para que nadie lo convierta en destructivo.
    const v2 = await openDB(DB_NAME, 2, {
      upgrade(db) {
        db.createObjectStore("pendingSales", { keyPath: "clientTempId" })
        db.createObjectStore("snapshots", { keyPath: "key" })
      },
    })
    await v2.put("pendingSales", {
      clientTempId: "uid-2",
      invoiceNo: 77,
      sale: {},
      status: "pending",
      attempts: 0,
      createdAt: new Date().toISOString(),
    })
    await v2.put("snapshots", {
      key: "pos-bootstrap",
      savedAt: new Date().toISOString(),
      payload: { config: {}, activeRegisterId: "reg-1" },
    })
    v2.close()

    const db = await getPosOfflineDB()
    expect(db.version).toBe(3)
    expect(db.objectStoreNames.contains("tenancy")).toBe(true)
    // Store nuevo: arranca vacío, o sea sin tenencia confirmada — y sin
    // tenencia confirmada el POS no emite. El device tiene que hacer un claim
    // con red antes de volver a facturar, que es el comportamiento correcto
    // tras un update.
    expect(await db.count("tenancy")).toBe(0)

    const row = await db.get("pendingSales", "uid-2")
    expect(row?.invoiceNo).toBe(77)
    expect(await db.get("snapshots", "pos-bootstrap")).toBeTruthy()
  })
})

describe("snapshot del bootstrap", () => {
  it("round-trip: lo que se guarda es lo que se lee, con fecha", async () => {
    await saveBootstrapSnapshot(fakeBootstrap())
    const cached = await loadBootstrapSnapshot()
    expect(cached).not.toBeNull()
    expect(cached!.bootstrap.activeRegisterId).toBe("reg-1")
    expect(Number.isNaN(Date.parse(cached!.savedAt))).toBe(false)
  })

  it("device que nunca sincronizó → null (no hay nada que servir)", async () => {
    expect(await loadBootstrapSnapshot()).toBeNull()
  })

  it("payload corrupto → null, no un arranque a medias", async () => {
    const db = await getPosOfflineDB()
    await db.put("snapshots", {
      key: "pos-bootstrap",
      savedAt: new Date().toISOString(),
      payload: { config: null },
    })
    expect(await loadBootstrapSnapshot()).toBeNull()
  })
})

describe("árbol de decisión del bootstrap: red / cache / nada", () => {
  it("RED: sirve lo de red y deja el snapshot listo para el próximo arranque", async () => {
    posGet.mockResolvedValue(fakeBootstrap({ activeRegisterId: "reg-fresco" }))

    const data = await fetchPosBootstrap()
    expect(data.activeRegisterId).toBe("reg-fresco")
    expect(useOfflineSyncStore.getState().catalogFromCache).toBe(false)

    // El guardado es best-effort y sin await dentro de `fetchPosBootstrap`.
    await vi.waitFor(async () => {
      const cached = await loadBootstrapSnapshot()
      expect(cached?.bootstrap.activeRegisterId).toBe("reg-fresco")
    })
  })

  it("SIN RED con snapshot: la caja abre con datos cacheados", async () => {
    await saveBootstrapSnapshot(fakeBootstrap())
    // El corte real: `fetch` rechaza con TypeError, no con un status HTTP.
    posGet.mockRejectedValue(new TypeError("Failed to fetch"))

    const data = await fetchPosBootstrap()

    expect(data.activeRegisterId).toBe("reg-1")
    expect(useOfflineSyncStore.getState().catalogFromCache).toBe(true)
    expect(useOfflineSyncStore.getState().catalogCachedAt).not.toBeNull()
  })

  it("SIN RED y sin snapshot: falla — no hay nada que dejar operar", async () => {
    posGet.mockRejectedValue(new TypeError("Failed to fetch"))
    await expect(fetchPosBootstrap()).rejects.toThrow()
    expect(useOfflineSyncStore.getState().catalogFromCache).toBe(false)
  })

  it("401: NO degrada al snapshot aunque exista — la sesión murió", async () => {
    await saveBootstrapSnapshot(fakeBootstrap())
    posGet.mockRejectedValue(new ApiError(401, { code: "session_revoked" }, "revocado"))

    await expect(fetchPosBootstrap()).rejects.toBeInstanceOf(ApiError)
    expect(useOfflineSyncStore.getState().catalogFromCache).toBe(false)
  })

  it("5xx: sí degrada — el server no pudo opinar sobre esta sesión", async () => {
    await saveBootstrapSnapshot(fakeBootstrap())
    posGet.mockRejectedValue(new ApiError(502, null, "upstream caído"))

    const data = await fetchPosBootstrap()
    expect(data.activeRegisterId).toBe("reg-1")
    expect(useOfflineSyncStore.getState().catalogFromCache).toBe(true)
  })
})

describe("purga al desvincular el device", () => {
  it("logout: borra la PII del snapshot pero conserva las ventas encoladas", async () => {
    await saveBootstrapSnapshot(fakeBootstrap())
    await enqueue({ clientTempId: "uid-9", invoiceNo: 9, sale: {} as never })

    await purgeOfflineSnapshots()

    expect(await loadBootstrapSnapshot()).toBeNull()
    expect(await getCount()).toBe(1)
  })

  it("eliminar dispositivo: no queda NADA en IndexedDB", async () => {
    await saveBootstrapSnapshot(fakeBootstrap())
    await enqueue({ clientTempId: "uid-9", invoiceNo: 9, sale: {} as never })

    await purgeAllOfflineData()

    // La base entera se borró: reabrirla da una v2 vacía, sin snapshot y sin cola.
    expect(await loadBootstrapSnapshot()).toBeNull()
    expect(await getCount()).toBe(0)
  })
})
