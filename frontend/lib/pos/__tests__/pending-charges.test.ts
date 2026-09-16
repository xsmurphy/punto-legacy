/**
 * Cobros online-only con resultado ambiguo (`lib/pos/pending-charges.ts`).
 *
 * Lo que se prueba es la decisión que evita el cobro duplicado: tras un
 * timeout / caída de red / 5xx, el próximo intento sobre el MISMO objeto
 *
 *   - si la venta ya está registrada → la muestra y NO vuelve a postear;
 *   - si no lo está → postea con el MISMO uid;
 *   - si no se puede saber (sin red) → no postea.
 *
 * Y que el transporte (`lookupSaleByUid`) solo lee "no existe" de un 404 del
 * backend: cualquier otro fallo es "no sé", nunca "no existe".
 *
 * `environment: "node"` + `fake-indexeddb`: sin browser, sin servidor.
 */

import { beforeEach, describe, expect, it, vi } from "vitest"
import "fake-indexeddb/auto"

import { getPosOfflineDB } from "@/lib/pos/offline-db"
import {
  chargeKey,
  chargeTargetFor,
  getPendingCharge,
  planChargeAttempt,
  recordAmbiguousCharge,
  reconcilePendingCharges,
  RESOLVED_TTL_MS,
  UNRESOLVED_TTL_MS,
  type ChargeFollowups,
  type ChargeTarget,
  type RegisteredSale,
  type SaleLookup,
} from "@/lib/pos/pending-charges"
import type { CreateSalePayload } from "@/lib/commands/create-sale"
import { ApiError } from "@/lib/api-client"

vi.mock("@/lib/api/pos-client", () => ({
  posApi: { get: vi.fn() },
}))
import { posApi } from "@/lib/api/pos-client"
import { lookupSaleByUid } from "@/lib/pos/sale-lookup"

const SESSION: ChargeTarget = { kind: "space-session", sessionId: "sess-1" }
const FOLLOWUPS: ChargeFollowups = {
  settlementIntent: null,
  sessionParentId: "sess-1",
  sessionOrderIds: ["ord-1", "ord-2"],
  orderParentId: null,
}

function payload(uid: string, subtotal = 50_000): CreateSalePayload {
  return {
    uid,
    type: 0,
    sale: [],
    payment: [{ name: "Efectivo", type: "efectivo", total: subtotal }],
    subtotal,
    tax: 0,
    discount: 0,
    ivaRemoved: false,
    client: null,
    user: null,
    note: null,
    interno: false,
    tags: [],
    date: "2026-09-16 12:00:00",
    timestamp: 1_789_000_000,
    invoiceno: 101,
    invoiceserie: "",
  } as unknown as CreateSalePayload
}

function sale(uid: string): RegisteredSale {
  return {
    transactionId: "tx-original",
    uid,
    invoiceNo: 101,
    invoicePrefix: "001-001",
    invoiceSerie: null,
    total: 50_000,
    einvoicePortalUrl: null,
  }
}

/**
 * Simula el tramo del diálogo que importa: planifica y SOLO postea cuando el
 * plan lo permite, con el uid que el plan dice.
 */
async function attemptCharge(target: ChargeTarget, lookup: SaleLookup, freshUid: string) {
  const post = vi.fn(async (uid: string) => ({ transactionId: "tx-nueva", uid }))
  const plan = await planChargeAttempt(target, lookup, freshUid)
  if (plan.action === "emit") await post(plan.uid)
  return { plan, post }
}

beforeEach(async () => {
  const db = await getPosOfflineDB()
  await db.clear("pendingCharges")
  vi.mocked(posApi.get).mockReset()
})

describe("objeto cobrado", () => {
  it("la venta simple no tiene objeto; espacio y orden sí", () => {
    expect(chargeTargetFor({ settlementIntent: null, sessionParentId: null, orderParentId: null })).toBeNull()
    expect(chargeTargetFor({ settlementIntent: null, sessionParentId: "s", orderParentId: null })).toEqual({
      kind: "space-session",
      sessionId: "s",
    })
    expect(chargeTargetFor({ settlementIntent: null, sessionParentId: null, orderParentId: "o" })).toEqual({
      kind: "order",
      orderId: "o",
    })
  })

  it("el cobro parcial y el total del mismo espacio comparten pendiente", () => {
    const parcial = chargeTargetFor({
      settlementIntent: { sessionId: "s", kind: "amount", amount: 10 },
      sessionParentId: null,
      orderParentId: null,
    })!
    expect(chargeKey(parcial)).toBe(chargeKey({ kind: "space-session", sessionId: "s" }))
  })
})

describe("reintento tras resultado ambiguo", () => {
  it("sin pendiente → postea con el uid de la apertura", async () => {
    const lookup = vi.fn<SaleLookup>()
    const { plan, post } = await attemptCharge(SESSION, lookup, "uid-apertura")
    expect(plan).toEqual({ action: "emit", uid: "uid-apertura" })
    expect(post).toHaveBeenCalledWith("uid-apertura")
    expect(lookup).not.toHaveBeenCalled()
  })

  it("venta YA registrada → la muestra y NO postea", async () => {
    await recordAmbiguousCharge({ target: SESSION, uid: "uid-1", payload: payload("uid-1"), followups: FOLLOWUPS })
    const lookup = vi.fn<SaleLookup>(async (uid) => sale(uid))

    const { plan, post } = await attemptCharge(SESSION, lookup, "uid-reapertura")

    expect(post).not.toHaveBeenCalled()
    expect(plan.action).toBe("show-registered")
    if (plan.action !== "show-registered") return
    expect(plan.sale.transactionId).toBe("tx-original")
    // Los pasos posteriores viajan congelados con el cobro, no con el carrito.
    expect(plan.row.followups).toEqual(FOLLOWUPS)
    expect(lookup).toHaveBeenCalledWith("uid-1")
  })

  it("venta NO registrada → postea con el MISMO uid, no con el de la reapertura", async () => {
    await recordAmbiguousCharge({ target: SESSION, uid: "uid-1", payload: payload("uid-1"), followups: FOLLOWUPS })
    const lookup = vi.fn<SaleLookup>(async () => null)

    const { plan, post } = await attemptCharge(SESSION, lookup, "uid-reapertura")

    expect(plan).toEqual({ action: "emit", uid: "uid-1" })
    expect(post).toHaveBeenCalledWith("uid-1")
  })

  it("sin red → bloqueado: no postea", async () => {
    await recordAmbiguousCharge({ target: SESSION, uid: "uid-1", payload: payload("uid-1"), followups: FOLLOWUPS })
    const lookup = vi.fn<SaleLookup>(async () => {
      throw new TypeError("Failed to fetch")
    })

    const { plan, post } = await attemptCharge(SESSION, lookup, "uid-reapertura")

    expect(plan).toEqual({ action: "block" })
    expect(post).not.toHaveBeenCalled()
    // El pendiente sigue ahí para la próxima vez.
    expect((await getPendingCharge(SESSION))?.uid).toBe("uid-1")
  })

  it("una venta ya encontrada registrada se resuelve SIN red", async () => {
    await recordAmbiguousCharge({ target: SESSION, uid: "uid-1", payload: payload("uid-1"), followups: FOLLOWUPS })
    await planChargeAttempt(SESSION, async (uid) => sale(uid), "x")

    const offline = vi.fn<SaleLookup>(async () => {
      throw new TypeError("Failed to fetch")
    })
    const { plan, post } = await attemptCharge(SESSION, offline, "uid-reapertura")

    expect(plan.action).toBe("show-registered")
    expect(offline).not.toHaveBeenCalled()
    expect(post).not.toHaveBeenCalled()
  })

  it("otro intento ambiguo conserva el uid y la fecha del primero", async () => {
    const t0 = Date.parse("2026-09-16T12:00:00Z")
    await recordAmbiguousCharge({ target: SESSION, uid: "uid-1", payload: payload("uid-1"), followups: FOLLOWUPS, now: t0 })
    await recordAmbiguousCharge({
      target: SESSION,
      uid: "uid-OTRO",
      payload: payload("uid-OTRO"),
      followups: FOLLOWUPS,
      now: t0 + 60_000,
    })
    await recordAmbiguousCharge({
      target: SESSION,
      uid: "uid-1",
      payload: payload("uid-1", 70_000),
      followups: FOLLOWUPS,
      now: t0 + 120_000,
    })

    const row = await getPendingCharge(SESSION)
    expect(row?.uid).toBe("uid-1")
    expect(row?.createdAt).toBe(new Date(t0).toISOString())
    expect(row?.lastAttemptAt).toBe(new Date(t0 + 120_000).toISOString())
    expect(row?.payload.subtotal).toBe(70_000)
  })
})

describe("reconciliación desde el sync", () => {
  const T0 = Date.parse("2026-09-16T12:00:00Z")

  async function seed(target: ChargeTarget, uid: string) {
    await recordAmbiguousCharge({ target, uid, payload: payload(uid), followups: FOLLOWUPS, now: T0 })
  }

  it("registrada → se anota la venta (quien reabra la ve sin red)", async () => {
    await seed(SESSION, "uid-1")
    await reconcilePendingCharges(async (uid) => sale(uid), T0 + 1_000)
    expect((await getPendingCharge(SESSION))?.resolvedSale?.transactionId).toBe("tx-original")
  })

  it("no registrada y reciente → se conserva; vencida → se descarta", async () => {
    await seed(SESSION, "uid-1")
    await reconcilePendingCharges(async () => null, T0 + UNRESOLVED_TTL_MS - 1)
    expect(await getPendingCharge(SESSION)).toBeDefined()
    await reconcilePendingCharges(async () => null, T0 + UNRESOLVED_TTL_MS)
    expect(await getPendingCharge(SESSION)).toBeUndefined()
  })

  it("no resucita un pendiente que el diálogo limpió mientras consultaba", async () => {
    await seed(SESSION, "uid-1")
    const db = await getPosOfflineDB()
    await reconcilePendingCharges(async (uid) => {
      // El diálogo resolvió y limpió el cobro durante la consulta del sync.
      await db.delete("pendingCharges", chargeKey(SESSION))
      return sale(uid)
    }, T0 + 1_000)
    expect(await getPendingCharge(SESSION)).toBeUndefined()
  })

  it("sin respuesta → nunca se descarta", async () => {
    await seed(SESSION, "uid-1")
    await reconcilePendingCharges(async () => {
      throw new Error("500")
    }, T0 + UNRESOLVED_TTL_MS * 10)
    expect(await getPendingCharge(SESSION)).toBeDefined()
  })

  it("registrada y sin reabrir por un día → se poda", async () => {
    await seed(SESSION, "uid-1")
    await reconcilePendingCharges(async (uid) => sale(uid), T0)
    await reconcilePendingCharges(async () => null, T0 + RESOLVED_TTL_MS - 1)
    expect(await getPendingCharge(SESSION)).toBeDefined()
    await reconcilePendingCharges(async () => null, T0 + RESOLVED_TTL_MS)
    expect(await getPendingCharge(SESSION)).toBeUndefined()
  })
})

describe("transporte: GET /v1/sales?uid=", () => {
  it("devuelve la venta registrada", async () => {
    vi.mocked(posApi.get).mockResolvedValueOnce({ sale: { ...sale("uid-1") } })
    await expect(lookupSaleByUid("uid-1")).resolves.toMatchObject({ transactionId: "tx-original", invoiceNo: 101 })
    expect(posApi.get).toHaveBeenCalledWith("/v1/sales?uid=uid-1")
  })

  it("404 del backend → no existe", async () => {
    vi.mocked(posApi.get).mockRejectedValueOnce(
      new ApiError(404, { ok: false, error: { code: 404, message: "no" } }, "no"),
    )
    await expect(lookupSaleByUid("uid-1")).resolves.toBeNull()
  })

  it("404 que no es del backend → NO se lee como 'no existe'", async () => {
    vi.mocked(posApi.get).mockRejectedValueOnce(new ApiError(404, "<html>Not Found</html>", "404"))
    await expect(lookupSaleByUid("uid-1")).rejects.toBeInstanceOf(ApiError)
  })

  it("5xx y red → tiran (no se sabe)", async () => {
    vi.mocked(posApi.get).mockRejectedValueOnce(new ApiError(500, { ok: false }, "500"))
    await expect(lookupSaleByUid("uid-1")).rejects.toBeInstanceOf(ApiError)
    vi.mocked(posApi.get).mockRejectedValueOnce(new TypeError("Failed to fetch"))
    await expect(lookupSaleByUid("uid-1")).rejects.toBeInstanceOf(TypeError)
  })
})
