/**
 * El lock screen es SIEMPRE lo primero (owner 2026-08-24).
 *
 * Lo que se verifica acá no se puede revisar leyendo la pantalla: que NINGÚN
 * estado rehidratado desde `sessionStorage` pueda dejar la caja abierta. La
 * versión anterior del store persistía `locked` a propósito (para que un F5 no
 * relockeara al cajero), así que hay devices con entradas v1 que dicen
 * `locked: false` guardadas ahora mismo — si el merge las dejara ganar, la
 * regresión sería silenciosa y solo visible en la caja de un comercio.
 *
 * `environment: "node"`: `sessionStorage` no existe, así que se inyecta un
 * doble antes de importar el store — `createJSONStorage` resuelve la storage
 * al crearse el módulo, y sin esto `persist` quedaría desactivado y el test no
 * ejercitaría nada.
 */

import { beforeEach, describe, expect, it, vi } from "vitest"

const STORE_KEY = "punto.pos.lock"

/** sessionStorage mínimo en memoria. */
function installSessionStorage(seed?: Record<string, string>) {
  const map = new Map<string, string>(Object.entries(seed ?? {}))
  const fake = {
    getItem: (k: string) => map.get(k) ?? null,
    setItem: (k: string, v: string) => void map.set(k, v),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
    key: (i: number) => Array.from(map.keys())[i] ?? null,
    get length() {
      return map.size
    },
  }
  ;(globalThis as { sessionStorage?: unknown }).sessionStorage = fake
  return map
}

/** Importa el store con el grafo de módulos limpio (storage ya inyectada). */
async function freshStore() {
  vi.resetModules()
  const mod = await import("@/lib/pos/lock-store")
  return mod.useLockStore
}

beforeEach(() => {
  installSessionStorage()
})

describe("lock-store", () => {
  it("arranca bloqueado sin nada persistido", async () => {
    const useLockStore = await freshStore()
    expect(useLockStore.getState().locked).toBe(true)
  })

  it("ignora un `locked: false` persistido por la versión vieja del store", async () => {
    installSessionStorage({
      [STORE_KEY]: JSON.stringify({
        state: { locked: false, activeUser: { id: "u1", name: "Ana" }, autoLockDone: true },
        version: 1,
      }),
    })
    const useLockStore = await freshStore()
    expect(useLockStore.getState().locked).toBe(true)
  })

  it("conserva la identidad del operador a través de la rehidratación", async () => {
    installSessionStorage({
      [STORE_KEY]: JSON.stringify({
        state: { activeUser: { id: "u1", name: "Ana" }, operatorToken: "tok-1" },
        version: 2,
      }),
    })
    const useLockStore = await freshStore()
    const s = useLockStore.getState()
    expect(s.locked).toBe(true)
    expect(s.activeUser).toEqual({ id: "u1", name: "Ana" })
    expect(s.operatorToken).toBe("tok-1")
  })

  it("nunca persiste `locked`: desbloquear y recargar vuelve a pedir el PIN", async () => {
    const map = installSessionStorage()
    const useLockStore = await freshStore()
    useLockStore.getState().unlock()
    expect(useLockStore.getState().locked).toBe(false)

    const persisted = JSON.parse(map.get(STORE_KEY) ?? "{}")
    expect(persisted.state).not.toHaveProperty("locked")

    // Misma storage, módulo nuevo = la recarga de la página.
    const useLockStoreAfterReload = await freshStore()
    expect(useLockStoreAfterReload.getState().locked).toBe(true)
  })

  it("bloquear tira la afirmación firmada del operador pero no su identidad", async () => {
    const useLockStore = await freshStore()
    useLockStore.getState().setActiveUser({ id: "u1", name: "Ana" })
    useLockStore.getState().setOperatorToken("tok-1")
    useLockStore.getState().unlock()

    useLockStore.getState().lock()
    const s = useLockStore.getState()
    expect(s.locked).toBe(true)
    expect(s.operatorToken).toBeNull()
    expect(s.activeUser).toEqual({ id: "u1", name: "Ana" })
  })
})
