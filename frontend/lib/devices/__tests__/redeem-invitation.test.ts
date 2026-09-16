/**
 * Canje público de una invitación (`open`), compartido por `/connect/[id]` y
 * el pareo automático de `/pos`. Lo que importa: viaja SIN credencial y
 * distingue token / código / error.
 */
import { beforeEach, describe, expect, it, vi } from "vitest"

const secrets = new Map<string, string>()
vi.mock("@/lib/auth/pairing-secret", () => ({
  getPairingSecret: (id: string) => secrets.get(id) ?? null,
  setPairingSecret: (id: string, s: string) => void secrets.set(id, s),
  clearPairingSecret: (id: string) => void secrets.delete(id),
}))

import { openInvitation } from "@/lib/devices/redeem-invitation"

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } })
}

beforeEach(() => secrets.clear())

describe("openInvitation", () => {
  it("no adjunta ninguna credencial (ni Authorization ni cookies)", async () => {
    const fetchImpl = vi.fn(async (_url: string, _init?: RequestInit) =>
      jsonResponse(200, { ok: true, data: { autoApprove: true, token: "pt", deviceId: "d", module: "pos" } }),
    )
    await openInvitation("inv-1", fetchImpl)
    const init = fetchImpl.mock.calls[0][1] as RequestInit
    expect(init.credentials).toBe("omit")
    const headers = new Headers(init.headers)
    expect(headers.has("authorization")).toBe(false)
    expect(fetchImpl.mock.calls[0][0]).toContain("resource=open&id=inv-1")
  })

  it("auto-aprobada → token", async () => {
    const res = await openInvitation("inv-1", async () =>
      jsonResponse(200, {
        ok: true,
        data: { autoApprove: true, token: "pt", deviceId: "d", module: "pos", companyId: "c", registerId: "r" },
      }),
    )
    expect(res).toEqual({
      kind: "token",
      device: { token: "pt", module: "pos", deviceId: "d", companyId: "c", registerId: "r" },
    })
  })

  it("invitación normal → código, y guarda el secreto de la primera apertura", async () => {
    const res = await openInvitation("inv-2", async () =>
      jsonResponse(200, { ok: true, data: { userCode: "ABC-1234", module: "pos", pairingSecret: "s3cr3t" } }),
    )
    expect(res).toEqual({ kind: "code", userCode: "ABC-1234", module: "pos" })
    expect(secrets.get("inv-2")).toBe("s3cr3t")
  })

  it("409 → in-use; 410 → mensaje del backend", async () => {
    const inUse = await openInvitation("inv-3", async () => jsonResponse(409, { ok: false, error: { message: "x" } }))
    const gone = await openInvitation("inv-4", async () =>
      jsonResponse(410, { ok: false, error: { message: "La conexión automática ya se usó o venció." } }),
    )
    expect(inUse).toEqual({ kind: "error", reason: "in-use", status: 409 })
    expect(gone).toEqual({ kind: "error", reason: "La conexión automática ya se usó o venció.", status: 410 })
  })

  it("sin red → config-error", async () => {
    const res = await openInvitation("inv-5", async () => {
      throw new TypeError("Failed to fetch")
    })
    expect(res).toEqual({ kind: "error", reason: "config-error", status: 0 })
  })
})
