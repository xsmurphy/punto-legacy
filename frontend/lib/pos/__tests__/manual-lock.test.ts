/**
 * Bloqueo MANUAL con un solo usuario (owner 2026-09-16, context/72 §9.3):
 * el sin-PIN aplica solo al abrir la caja. "Bloquear" persiste una marca en el
 * dispositivo que sobrevive la recarga y que solo levanta el desbloqueo con PIN.
 *
 * La "recarga" se simula re-importando el store con `vi.resetModules()`: el
 * estado en memoria se pierde y lo único que queda es lo que está en storage,
 * exactamente como en el navegador.
 */
import { beforeEach, describe, expect, it, vi } from "vitest"
import type { PosUser } from "@/lib/types/pos-bootstrap"
import { soleOperator } from "@/lib/pos/sole-operator"
import { decideLockAction, LOCK_BLOCKED_NO_PIN, LOCK_BLOCKED_OFFLINE } from "@/lib/pos/lock-action"

function memoryStorage(): Storage {
  const m = new Map<string, string>()
  return {
    get length() {
      return m.size
    },
    clear: () => m.clear(),
    getItem: (k) => (m.has(k) ? (m.get(k) as string) : null),
    key: (i) => Array.from(m.keys())[i] ?? null,
    removeItem: (k) => void m.delete(k),
    setItem: (k, v) => void m.set(k, String(v)),
  }
}

const local = memoryStorage()

beforeEach(() => {
  local.clear()
  vi.stubGlobal("localStorage", local)
  vi.stubGlobal("sessionStorage", memoryStorage())
  vi.resetModules()
})

/** "Abrir la app": store nuevo, leyendo solo lo que quedó en storage. */
async function boot() {
  const mod = await import("@/lib/pos/lock-store")
  return mod.useLockStore
}

/** Lo que decide el lock screen: ¿se desbloquea sin PIN al arrancar? */
function unlocksWithoutPin(state: { manualLock: boolean; soleOperatorDenied: boolean }, users: PosUser[]) {
  return !state.manualLock && !state.soleOperatorDenied && soleOperator(users, false) !== null
}

const owner: PosUser = { id: "u-owner", name: "Dueña", pinhash: "h", pinIsDefault: false }

describe("bloqueo manual y recarga", () => {
  it("arranque normal con un solo usuario → sin PIN", async () => {
    const store = await boot()
    expect(unlocksWithoutPin(store.getState(), [owner])).toBe(true)
  })

  it("bloqueo manual → recarga → sigue bloqueada y no se desbloquea sin PIN", async () => {
    let store = await boot()
    store.getState().unlockAsSoleOperator({ id: owner.id, name: owner.name })
    store.getState().lockManually()
    expect(store.getState().locked).toBe(true)

    vi.resetModules()
    store = await boot()
    expect(store.getState().locked).toBe(true)
    expect(store.getState().manualLock).toBe(true)
    expect(unlocksWithoutPin(store.getState(), [owner])).toBe(false)
  })

  it("el bloqueo por inactividad (lock) NO deja la marca", async () => {
    let store = await boot()
    store.getState().lock()
    vi.resetModules()
    store = await boot()
    expect(store.getState().manualLock).toBe(false)
    expect(unlocksWithoutPin(store.getState(), [owner])).toBe(true)
  })

  it("PIN correcto → marca limpia → el próximo arranque vuelve al sin PIN", async () => {
    let store = await boot()
    store.getState().lockManually()
    vi.resetModules()
    store = await boot()
    // Match del PIN en el lock screen.
    store.getState().setActiveUser({ id: owner.id, name: owner.name })
    store.getState().unlock()
    expect(store.getState().manualLock).toBe(false)

    vi.resetModules()
    store = await boot()
    expect(store.getState().manualLock).toBe(false)
    expect(unlocksWithoutPin(store.getState(), [owner])).toBe(true)
  })

  it("el desbloqueo sin PIN no abre una caja bloqueada a mano ni levanta la marca", async () => {
    const store = await boot()
    store.getState().lockManually()
    store.getState().unlockAsSoleOperator({ id: owner.id, name: owner.name })
    expect(store.getState().locked).toBe(true)
    expect(store.getState().manualLock).toBe(true)
  })
})

describe("decideLockAction — qué hace Bloquear", () => {
  const defaultPin: PosUser = { id: "u-owner", name: "Dueña", pinhash: "h1111", pinIsDefault: true }
  const noPin: PosUser = { id: "u-owner", name: "Dueña", pinhash: null, pinIsDefault: false }

  it("con varios usuarios (desbloqueo con PIN) bloquea directo", () => {
    expect(decideLockAction({ soleOperator: false, operator: defaultPin, online: false })).toEqual({ kind: "lock" })
  })
  it("un solo usuario con PIN propio bloquea directo, también sin red", () => {
    expect(decideLockAction({ soleOperator: true, operator: owner, online: false })).toEqual({ kind: "lock" })
  })
  it("un solo usuario con el PIN del alta → primero elige su código", () => {
    expect(decideLockAction({ soleOperator: true, operator: defaultPin, online: true })).toEqual({ kind: "choose-pin" })
  })
  it("PIN del alta y sin red → deshabilitado con motivo", () => {
    expect(decideLockAction({ soleOperator: true, operator: defaultPin, online: false })).toEqual({
      kind: "blocked",
      reason: LOCK_BLOCKED_OFFLINE,
    })
  })
  it("sin ningún PIN cargado → deshabilitado (el bloqueo manual no tendría salida)", () => {
    expect(decideLockAction({ soleOperator: true, operator: noPin, online: true })).toEqual({
      kind: "blocked",
      reason: LOCK_BLOCKED_NO_PIN,
    })
  })
})
