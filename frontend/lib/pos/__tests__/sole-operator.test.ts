/**
 * Desbloqueo sin PIN con un solo usuario en la sucursal (context/72 §9.3, D-P2).
 *
 * - `soleOperator()`: la regla local, dinámica, con el roster del bootstrap.
 * - `unlockAsSoleOperator()`: el lock se omite con roster de uno, la afirmación
 *   la trae el servidor, y si el servidor dice que ya no son uno la caja se
 *   vuelve a bloquear y no se reabre sola.
 */
import { describe, expect, it } from "vitest"
import type { PosUser } from "@/lib/types/pos-bootstrap"
import { soleOperator } from "@/lib/pos/sole-operator"
import { unlockAsSoleOperator, type SoleUnlockDeps, type SoleUnlockState } from "@/lib/pos/sole-unlock"

const ana: PosUser = { id: "u-ana", name: "Ana", pinhash: null } as PosUser
const beto: PosUser = { id: "u-beto", name: "Beto", pinhash: "x" } as PosUser

describe("soleOperator — ¿se omite el PIN?", () => {
  it("roster de uno → ese usuario", () => {
    expect(soleOperator([ana], false)).toEqual({ id: "u-ana", name: "Ana" })
  })
  it("roster de dos → PIN (la regla es dinámica: no hay flag guardado)", () => {
    expect(soleOperator([ana, beto], false)).toBeNull()
  })
  it("roster vacío → PIN", () => {
    expect(soleOperator([], false)).toBeNull()
  })
  it("roster ausente en el bootstrap → nunca habilita el modo sin PIN", () => {
    expect(soleOperator([ana], true)).toBeNull()
    expect(soleOperator(null, false)).toBeNull()
  })
})

/** Store mínimo con la misma semántica que `lock-store.ts`. */
function makeStore(post: () => Promise<Response>) {
  const state: SoleUnlockState & { operatorToken: string | null; permissions: string[]; denied: boolean } = {
    locked: true,
    soleOperator: false,
    activeUser: null,
    operatorToken: null,
    permissions: [],
    denied: false,
  }
  const deps: SoleUnlockDeps = {
    post,
    getState: () => state,
    unlockAsSoleOperator: (user) => {
      Object.assign(state, { locked: false, soleOperator: true, activeUser: user, operatorToken: null, permissions: [] })
    },
    setOperatorToken: (t) => void (state.operatorToken = t),
    setOperatorPermissions: (p) => void (state.permissions = p),
    denySoleOperator: () => {
      Object.assign(state, { locked: true, soleOperator: false, denied: true, operatorToken: null, permissions: [] })
    },
  }
  return { state, deps }
}

const ok = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } })

describe("unlockAsSoleOperator", () => {
  it("desbloquea sin PIN y guarda la afirmación del servidor", async () => {
    const { state, deps } = makeStore(async () =>
      ok({ ok: true, user: { id: "u-ana", name: "Ana" }, operatorToken: "op.sig", permissions: ["pos.sale.create", 3] }),
    )
    await unlockAsSoleOperator({ id: "u-ana", name: "Ana" }, deps)
    expect(state.locked).toBe(false)
    expect(state.activeUser).toEqual({ id: "u-ana", name: "Ana" })
    expect(state.operatorToken).toBe("op.sig")
    expect(state.permissions).toEqual(["pos.sale.create"])
  })

  it("sin red: queda desbloqueada localmente, sin afirmación (igual que el PIN offline)", async () => {
    const { state, deps } = makeStore(async () => {
      throw new TypeError("Failed to fetch")
    })
    await unlockAsSoleOperator({ id: "u-ana", name: "Ana" }, deps)
    expect(state.locked).toBe(false)
    expect(state.operatorToken).toBeNull()
  })

  it("el servidor dice pin_required (roster cacheado viejo) → vuelve a bloquear y no se reabre", async () => {
    const { state, deps } = makeStore(async () =>
      ok({ ok: false, error: { message: "x", code: 403, reason: "pin_required" } }, 403),
    )
    await unlockAsSoleOperator({ id: "u-ana", name: "Ana" }, deps)
    expect(state.locked).toBe(true)
    expect(state.denied).toBe(true)
    expect(state.operatorToken).toBeNull()
  })

  it("el servidor afirma a OTRA persona → bloquea sin guardar esa afirmación", async () => {
    const { state, deps } = makeStore(async () => ok({ ok: true, user: { id: "u-beto" }, operatorToken: "op.beto" }))
    await unlockAsSoleOperator({ id: "u-ana", name: "Ana" }, deps)
    expect(state.locked).toBe(true)
    expect(state.operatorToken).toBeNull()
  })

  it("respuesta tardía después de un bloqueo no resucita la afirmación", async () => {
    let release: (r: Response) => void = () => undefined
    const pending = new Promise<Response>((resolve) => (release = resolve))
    const { state, deps } = makeStore(() => pending)
    const run = unlockAsSoleOperator({ id: "u-ana", name: "Ana" }, deps)
    // Mientras viaja, alguien desbloquea con PIN (deja de ser modo sin PIN).
    Object.assign(state, { soleOperator: false, activeUser: { id: "u-beto", name: "Beto" } })
    release(ok({ ok: true, user: { id: "u-ana" }, operatorToken: "op.ana" }))
    await run
    expect(state.operatorToken).toBeNull()
  })

  it("un error de otro tipo (502) no bloquea: es falta de red, no una respuesta sobre el roster", async () => {
    const { state, deps } = makeStore(async () => ok({ ok: false, error: { message: "x" } }, 502))
    await unlockAsSoleOperator({ id: "u-ana", name: "Ana" }, deps)
    expect(state.locked).toBe(false)
  })
})
