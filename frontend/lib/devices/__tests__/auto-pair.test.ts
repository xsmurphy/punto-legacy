/**
 * Pareo automático de `/pos` (context/72 §9.2, D-P1): qué pantalla sale según
 * la sesión y las cajas disponibles, y el orden de las tres credenciales
 * (panel → canje público → device).
 */
import { describe, expect, it, vi } from "vitest"
import { ApiError } from "@/lib/api-client"
import { pairRegister, planAutoPair, type AutoPairDeps, type AutoPairRegister } from "@/lib/devices/auto-pair"

const caja = (n: number): AutoPairRegister => ({
  registerId: `00000000-0000-4000-8000-00000000000${n}`,
  registerName: `Caja ${n}`,
  outletId: "00000000-0000-4000-8000-0000000000a1",
  outletName: "Central",
})

function makeDeps(over: Partial<AutoPairDeps> = {}): AutoPairDeps & { calls: string[] } {
  const calls: string[] = []
  const deps: AutoPairDeps = {
    hasPanelSession: () => true,
    listRegisters: async () => [caja(1)],
    createInvitation: async (registerId) => {
      calls.push(`create:${registerId}`)
      return { id: "inv-1" }
    },
    openInvitation: async (id) => {
      calls.push(`open:${id}`)
      return {
        kind: "token",
        device: { token: "pt_device", module: "pos", deviceId: "dev-1", companyId: "c", registerId: caja(1).registerId },
      }
    },
    persistDevice: (id) => {
      calls.push(`persist:${id}`)
    },
    acquireRegister: async (registerId) => {
      calls.push(`acquire:${registerId}`)
    },
    ...over,
  }
  return Object.assign(deps, { calls })
}

describe("planAutoPair — qué pantalla corresponde", () => {
  it("sin sesión de panel → link, sin pedir nada al servidor", async () => {
    const listRegisters = vi.fn()
    const plan = await planAutoPair({ hasPanelSession: () => false, listRegisters })
    expect(plan).toEqual({ kind: "link" })
    expect(listRegisters).not.toHaveBeenCalled()
  })

  it("una sola caja disponible → pareo automático sin clic", async () => {
    const plan = await planAutoPair(makeDeps())
    expect(plan).toEqual({ kind: "auto", register: caja(1) })
  })

  it("varias cajas → selector", async () => {
    const plan = await planAutoPair(makeDeps({ listRegisters: async () => [caja(1), caja(2)] }))
    expect(plan).toEqual({ kind: "choose", registers: [caja(1), caja(2)] })
  })

  it("ninguna caja libre → link", async () => {
    const plan = await planAutoPair(makeDeps({ listRegisters: async () => [] }))
    expect(plan).toEqual({ kind: "link" })
  })

  it("sin permiso (403) → link", async () => {
    const plan = await planAutoPair(
      makeDeps({
        listRegisters: async () => {
          throw new ApiError(403, null, "No tenés permiso")
        },
      }),
    )
    expect(plan).toEqual({ kind: "link" })
  })

  it("sesión vencida (401) o sin red → link", async () => {
    const vencida = await planAutoPair(
      makeDeps({
        listRegisters: async () => {
          throw new ApiError(401, null, "x")
        },
      }),
    )
    const sinRed = await planAutoPair(
      makeDeps({
        listRegisters: async () => {
          throw new TypeError("Failed to fetch")
        },
      }),
    )
    expect(vencida).toEqual({ kind: "link" })
    expect(sinRed).toEqual({ kind: "link" })
  })
})

describe("pairRegister — invitación del panel, canje público, tenencia del device", () => {
  it("camino feliz: crea, canjea, guarda el token y recién después toma la caja", async () => {
    const deps = makeDeps()
    const out = await pairRegister(caja(1).registerId, deps)
    expect(out).toEqual({ ok: true, registerId: caja(1).registerId })
    expect(deps.calls).toEqual([
      `create:${caja(1).registerId}`,
      "open:inv-1",
      "persist:inv-1",
      `acquire:${caja(1).registerId}`,
    ])
  })

  it("la caja se ocupó entre medio (409 al crear) → mensaje del servidor, sin canje", async () => {
    const deps = makeDeps({
      createInvitation: async () => {
        throw new ApiError(409, null, "Esa caja ya está en uso en otro dispositivo")
      },
    })
    const out = await pairRegister(caja(1).registerId, deps)
    expect(out).toEqual({ ok: false, message: "Esa caja ya está en uso en otro dispositivo" })
    expect(deps.calls).toEqual([])
  })

  it("el canje falla → no se guarda ningún token ni se toma la caja", async () => {
    const deps = makeDeps({
      openInvitation: async () => ({ kind: "error", reason: "Esa caja ya está en uso en otro dispositivo.", status: 409 }),
    })
    const out = await pairRegister(caja(1).registerId, deps)
    expect(out.ok).toBe(false)
    expect(deps.calls).toEqual([`create:${caja(1).registerId}`])
  })

  it("un canje que pide código (no auto-aprobado) no se queda esperando", async () => {
    const deps = makeDeps({ openInvitation: async () => ({ kind: "code", userCode: "ABC-1234", module: "pos" }) })
    const out = await pairRegister(caja(1).registerId, deps)
    expect(out.ok).toBe(false)
    expect(deps.calls).toEqual([`create:${caja(1).registerId}`])
  })

  it("si tomar la caja falla (la tiene otro) el pareo igual queda hecho", async () => {
    const deps = makeDeps({
      acquireRegister: async () => {
        throw new ApiError(409, null, "taken_by_other")
      },
    })
    const out = await pairRegister(caja(1).registerId, deps)
    expect(out.ok).toBe(true)
    expect(deps.calls).toContain("persist:inv-1")
  })

  it("un token para OTRA caja no se guarda", async () => {
    const deps = makeDeps({
      openInvitation: async () => ({
        kind: "token",
        device: { token: "pt", module: "pos", deviceId: "d", registerId: caja(2).registerId },
      }),
    })
    const out = await pairRegister(caja(1).registerId, deps)
    expect(out.ok).toBe(false)
    expect(deps.calls).not.toContain("persist:inv-1")
  })
})
