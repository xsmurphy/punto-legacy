/**
 * Cola de operaciones del POS: encolado, vista optimista, sincronización,
 * reintentos y la regla de conflicto entre la caja y el panel.
 *
 * Qué se prueba acá y por qué no alcanza con leer el código: el orden dentro
 * de un canal, el freno ante una operación terminal, el cerco por caja y la
 * forma exacta del payload de ajustes (un PATCH, no la config entera) son
 * decisiones que se ven correctas en el diff y se rompen en la práctica con un
 * `reduce` mal ordenado o un merge de más. Y la que más importa: un cierre de
 * caja rechazado no puede desaparecer ni dejar pasar lo que viene detrás.
 *
 * `environment: "node"` + `fake-indexeddb`: sin browser, sin servidor.
 */

import { beforeEach, describe, expect, it, vi } from "vitest"
import "fake-indexeddb/auto"

import { getPosOfflineDB } from "@/lib/pos/offline-db"
import {
  discardOp,
  enqueueOp,
  getFailedOpsCount,
  getOpsCount,
  markOpFailed,
  peekAllOps,
  retryOp,
  reviveInterruptedOps,
  markOpSyncing,
  OPS_MAX_ATTEMPTS,
} from "@/lib/pos/pending-ops"
import { PendingOpError, syncPendingOps } from "@/lib/pos/pending-ops-sync"
import {
  ACCOUNT_BLOCKED_CODE,
  ACCOUNT_BLOCKED_NOTE,
  isAccountBlocked,
} from "@/lib/pos/account-block"
import { ApiError } from "@/lib/api-client"
import type { PendingOpRow } from "@/lib/pos/pending-ops"
import {
  applyBindingOps,
  applyDrawerOps,
  applyPendingConfigPatches,
  CLOSED_DRAWER,
  pendingHotkeys,
} from "@/lib/pos/local-register-state"
import { POS_REGISTER_CONFIG_DEFAULTS } from "@/hooks/use-pos-config"
import type { PosRegisterConfig } from "@/hooks/use-pos-config"

const CAJA = "reg-1"
const OTRA_CAJA = "reg-2"

/** Sender que siempre acepta y registra qué recibió. */
function okSender() {
  const seen: PendingOpRow[] = []
  const send = vi.fn(async (row: PendingOpRow) => {
    seen.push(row)
  })
  return { send, seen }
}

async function clearOps() {
  const db = await getPosOfflineDB()
  await db.clear("pendingOps")
  await db.clear("snapshots")
}

function enqueueConfig(patch: Partial<PosRegisterConfig>, registerId = CAJA) {
  return enqueueOp({
    kind: "posConfig",
    stream: "pos-config",
    registerId,
    payload: patch,
    label: "Ajustes de la caja",
    mergePayload: (prev, next) => ({
      ...(prev as Partial<PosRegisterConfig>),
      ...(next as Partial<PosRegisterConfig>),
    }),
  })
}

function enqueueDrawer(
  kind: "drawerOpen" | "drawerClose",
  amount: number,
  date: string,
  registerId = CAJA,
) {
  return enqueueOp({
    kind,
    stream: "drawer",
    registerId,
    payload: { amount, date },
    label: `${kind} ${amount}`,
  })
}

beforeEach(async () => {
  await clearOps()
})

// ── Encolar ───────────────────────────────────────────────────────────────────

describe("encolar", () => {
  it("numera en orden de encolado y arranca en 'pending'", async () => {
    await enqueueDrawer("drawerOpen", 100, "2026-08-23 08:00:00")
    await enqueueDrawer("drawerClose", 900, "2026-08-23 20:00:00")

    const ops = await peekAllOps()
    expect(ops.map((o) => o.seq)).toEqual([1, 2])
    expect(ops.map((o) => o.kind)).toEqual(["drawerOpen", "drawerClose"])
    expect(ops.every((o) => o.status === "pending")).toBe(true)
    expect(await getOpsCount()).toBe(2)
  })

  it("da a cada operación una identidad propia (clave de idempotencia)", async () => {
    await enqueueDrawer("drawerOpen", 100, "2026-08-23 08:00:00")
    await enqueueDrawer("drawerOpen", 100, "2026-08-23 08:00:00")
    const ops = await peekAllOps()
    expect(new Set(ops.map((o) => o.opId)).size).toBe(2)
  })

  it("fusiona ajustes sucesivos en UNA operación (cinco interruptores, un cambio)", async () => {
    await enqueueConfig({ mergeRepeated: false })
    await enqueueConfig({ showSoftKeyboard: true })
    await enqueueConfig({ mergeRepeated: true })

    const ops = await peekAllOps()
    expect(ops).toHaveLength(1)
    expect(ops[0].payload).toEqual({ mergeRepeated: true, showSoftKeyboard: true })
  })

  it("NO fusiona operaciones de caja: dos extracciones son dos hechos", async () => {
    await enqueueDrawer("drawerOpen", 100, "2026-08-23 08:00:00")
    await enqueueDrawer("drawerOpen", 200, "2026-08-23 09:00:00")
    expect(await getOpsCount()).toBe(2)
  })

  it("no fusiona contra una operación ya fallida — un error no se tapa con un cambio nuevo", async () => {
    const first = await enqueueConfig({ mergeRepeated: false })
    await markOpFailed(first.opId, { code: "HTTP_422", message: "no" })

    await enqueueConfig({ showSoftKeyboard: true })
    const ops = await peekAllOps()
    expect(ops).toHaveLength(2)
    expect(ops[0].status).toBe("failed")
  })
})

// ── Vista optimista ───────────────────────────────────────────────────────────

describe("vista optimista", () => {
  it("los ajustes en cola se ven encima de lo que diga el servidor", async () => {
    await enqueueConfig({ mergeRepeated: false })

    const desdeServidor: PosRegisterConfig = {
      ...POS_REGISTER_CONFIG_DEFAULTS,
      mergeRepeated: true,
    }
    const visto = await applyPendingConfigPatches(CAJA, desdeServidor)
    expect(visto.mergeRepeated).toBe(false)
  })

  it("no mezcla los ajustes de otra caja", async () => {
    await enqueueConfig({ mergeRepeated: false }, OTRA_CAJA)
    const visto = await applyPendingConfigPatches(CAJA, POS_REGISTER_CONFIG_DEFAULTS)
    expect(visto.mergeRepeated).toBe(POS_REGISTER_CONFIG_DEFAULTS.mergeRepeated)
  })

  it("abrir, cerrar y volver a abrir sin red deja la caja ABIERTA", async () => {
    await enqueueDrawer("drawerOpen", 100, "2026-08-23 08:00:00")
    await enqueueDrawer("drawerClose", 900, "2026-08-23 14:00:00")
    await enqueueDrawer("drawerOpen", 50, "2026-08-23 15:00:00")

    const ops = await peekAllOps()
    const estado = applyDrawerOps(CLOSED_DRAWER, ops)
    expect(estado.isOpen).toBe(true)
    expect(estado.openDate).toBe("2026-08-23 15:00:00")
    expect(estado.openAmount).toBe(50)
  })

  it("un cierre en cola cierra una caja que el servidor todavía cree abierta", async () => {
    await enqueueDrawer("drawerClose", 900, "2026-08-23 20:00:00")
    const estado = applyDrawerOps(
      { isOpen: true, openDate: "2026-08-23 08:00:00", openAmount: 100 },
      await peekAllOps(),
    )
    expect(estado.isOpen).toBe(false)
  })

  it("la grilla de hotkeys en cola gana, y encolar de nuevo reemplaza la anterior", async () => {
    const grilla = (itemId: string) => ({
      hotkeys: [{ itemId, position: 0, color: "", isCategory: false }],
    })
    for (const item of ["a", "b"]) {
      await enqueueOp({
        kind: "hotkeys",
        stream: "hotkeys",
        registerId: CAJA,
        payload: grilla(item),
        label: "Accesos directos",
        mergePayload: (_prev, next) => next,
      })
    }
    expect(await getOpsCount()).toBe(1)
    expect(await pendingHotkeys(CAJA)).toEqual(grilla("b").hotkeys)
  })

  it("una impresora creada sin red se puede editar y borrar antes de sincronizar", async () => {
    const id = "11111111-1111-1111-1111-111111111111"
    const base = [] as Parameters<typeof applyBindingOps>[0]
    await enqueueOp({
      kind: "printerBindingCreate",
      stream: "printer-bindings",
      registerId: CAJA,
      payload: { registerId: CAJA, binding: { id, name: "Cocina" } },
      label: "alta",
    })
    await enqueueOp({
      kind: "printerBindingUpdate",
      stream: "printer-bindings",
      registerId: CAJA,
      payload: { id, patch: { name: "Cocina 2" } },
      label: "cambio",
    })
    const conCambio = applyBindingOps(base, await peekAllOps())
    expect(conCambio).toHaveLength(1)
    expect(conCambio[0].name).toBe("Cocina 2")

    await enqueueOp({
      kind: "printerBindingDelete",
      stream: "printer-bindings",
      registerId: CAJA,
      payload: { id },
      label: "baja",
    })
    expect(applyBindingOps(base, await peekAllOps())).toHaveLength(0)
  })
})

// ── Sincronizar ───────────────────────────────────────────────────────────────

describe("sincronizar", () => {
  it("drena la cola en orden y la deja vacía", async () => {
    await enqueueDrawer("drawerOpen", 100, "2026-08-23 08:00:00")
    await enqueueDrawer("drawerClose", 900, "2026-08-23 20:00:00")
    const { send, seen } = okSender()

    const res = await syncPendingOps({ send, activeRegisterId: CAJA })

    expect(res.synced).toBe(2)
    expect(seen.map((o) => o.kind)).toEqual(["drawerOpen", "drawerClose"])
    expect(await getOpsCount()).toBe(0)
  })

  it("no manda nada dos veces: una segunda pasada no reenvía lo ya sincronizado", async () => {
    await enqueueConfig({ mergeRepeated: false })
    const { send } = okSender()

    await syncPendingOps({ send, activeRegisterId: CAJA })
    await syncPendingOps({ send, activeRegisterId: CAJA })

    expect(send).toHaveBeenCalledTimes(1)
  })

  it("no toca nada sin caja activa", async () => {
    await enqueueConfig({ mergeRepeated: false })
    const { send } = okSender()
    const res = await syncPendingOps({ send, activeRegisterId: "" })
    expect(send).not.toHaveBeenCalled()
    expect(res.synced).toBe(0)
    expect(await getOpsCount()).toBe(1)
  })

  it("los canales son independientes: las impresoras trabadas no frenan los ajustes", async () => {
    await enqueueOp({
      kind: "printerBindingDelete",
      stream: "printer-bindings",
      registerId: CAJA,
      payload: { id: "x" },
      label: "baja",
    })
    await enqueueConfig({ mergeRepeated: false })

    const send = vi.fn(async (row: PendingOpRow) => {
      if (row.stream === "printer-bindings") {
        throw new PendingOpError("HTTP_422", "no existe", false)
      }
    })
    const res = await syncPendingOps({ send, activeRegisterId: CAJA })

    expect(res.synced).toBe(1)
    expect(res.failed).toBe(1)
    const ops = await peekAllOps()
    expect(ops).toHaveLength(1)
    expect(ops[0].stream).toBe("printer-bindings")
    expect(ops[0].status).toBe("failed")
  })
})

// ── El arqueo no se pierde ────────────────────────────────────────────────────

describe("una operación de caja rechazada", () => {
  it("queda en la cola, visible, y FRENA lo que viene detrás en su canal", async () => {
    await enqueueDrawer("drawerClose", 900, "2026-08-23 20:00:00")
    await enqueueDrawer("drawerOpen", 100, "2026-08-24 08:00:00")

    // El cierre lo rechaza el servidor; la apertura del día siguiente NO puede
    // aplicarse encima — dejaría el turno anterior abierto para siempre.
    const send = vi.fn(async (row: PendingOpRow) => {
      if (row.kind === "drawerClose") {
        throw new PendingOpError("HTTP_403", "Caja no seleccionada", false)
      }
    })

    const res = await syncPendingOps({ send, activeRegisterId: CAJA })

    expect(send).toHaveBeenCalledTimes(1)
    expect(res.failed).toBe(1)
    expect(res.halted).toContainEqual({ stream: "drawer", reason: "error" })

    const ops = await peekAllOps()
    expect(ops.map((o) => o.status)).toEqual(["failed", "pending"])
    expect(await getFailedOpsCount()).toBe(1)
  })

  it("sigue frenando el canal en las pasadas siguientes hasta que alguien la resuelva", async () => {
    await enqueueDrawer("drawerClose", 900, "2026-08-23 20:00:00")
    await enqueueDrawer("drawerOpen", 100, "2026-08-24 08:00:00")
    const failing = vi.fn(async (row: PendingOpRow) => {
      if (row.kind === "drawerClose") throw new PendingOpError("HTTP_403", "no", false)
    })
    await syncPendingOps({ send: failing, activeRegisterId: CAJA })

    const { send } = okSender()
    const res = await syncPendingOps({ send, activeRegisterId: CAJA })

    expect(send).not.toHaveBeenCalled()
    expect(res.halted).toContainEqual({ stream: "drawer", reason: "failed-head" })
    expect(await getOpsCount()).toBe(2)
  })

  it("al reintentarla a mano se destraba el canal entero", async () => {
    await enqueueDrawer("drawerClose", 900, "2026-08-23 20:00:00")
    await enqueueDrawer("drawerOpen", 100, "2026-08-24 08:00:00")
    const failing = vi.fn(async (row: PendingOpRow) => {
      if (row.kind === "drawerClose") throw new PendingOpError("HTTP_403", "no", false)
    })
    await syncPendingOps({ send: failing, activeRegisterId: CAJA })

    const trabada = (await peekAllOps())[0]
    await retryOp(trabada.opId)

    const { send, seen } = okSender()
    await syncPendingOps({ send, activeRegisterId: CAJA })

    expect(seen.map((o) => o.kind)).toEqual(["drawerClose", "drawerOpen"])
    expect(await getOpsCount()).toBe(0)
  })

  it("descartarla es una decisión explícita y deja pasar el resto", async () => {
    await enqueueDrawer("drawerClose", 900, "2026-08-23 20:00:00")
    await enqueueDrawer("drawerOpen", 100, "2026-08-24 08:00:00")
    const failing = vi.fn(async (row: PendingOpRow) => {
      if (row.kind === "drawerClose") throw new PendingOpError("HTTP_403", "no", false)
    })
    await syncPendingOps({ send: failing, activeRegisterId: CAJA })

    await discardOp((await peekAllOps())[0].opId)

    const { send, seen } = okSender()
    await syncPendingOps({ send, activeRegisterId: CAJA })
    expect(seen.map((o) => o.kind)).toEqual(["drawerOpen"])
  })
})

// ── Reintentos ────────────────────────────────────────────────────────────────

describe("reintentos", () => {
  it("un corte de red es transitorio: vuelve a 'pending' y NO se marca fallida", async () => {
    await enqueueConfig({ mergeRepeated: false })
    const send = vi.fn(async () => {
      throw new PendingOpError("NETWORK_ERROR", "sin red", true)
    })

    const res = await syncPendingOps({ send, activeRegisterId: CAJA })

    expect(res.retried).toBe(1)
    expect(res.failed).toBe(0)
    const [op] = await peekAllOps()
    expect(op.status).toBe("pending")
    expect(op.attempts).toBe(1)
    expect(await getFailedOpsCount()).toBe(0)
  })

  it("el backoff impide reintentar de inmediato, y con el tiempo la deja pasar", async () => {
    await enqueueConfig({ mergeRepeated: false })
    const flaky = vi.fn(async () => {
      throw new PendingOpError("NETWORK_ERROR", "sin red", true)
    })
    await syncPendingOps({ send: flaky, activeRegisterId: CAJA })

    const { send } = okSender()
    const frenada = await syncPendingOps({ send, activeRegisterId: CAJA })
    expect(send).not.toHaveBeenCalled()
    expect(frenada.halted).toContainEqual({ stream: "pos-config", reason: "backoff" })

    // Media hora después el backoff ya venció.
    const luego = await syncPendingOps({
      send,
      activeRegisterId: CAJA,
      now: () => Date.now() + 31 * 60_000,
    })
    expect(luego.synced).toBe(1)
  })

  it("un rechazo del servidor NO se reintenta solo: sería martillarlo con el mismo payload", async () => {
    await enqueueConfig({ mergeRepeated: false })
    const send = vi.fn(async () => {
      throw new PendingOpError("HTTP_422", "valor inválido", false)
    })
    await syncPendingOps({ send, activeRegisterId: CAJA })

    const [op] = await peekAllOps()
    expect(op.status).toBe("failed")
    expect(op.error?.code).toBe("HTTP_422")
  })

  it("un error que el transporte no clasificó se trata como terminal", async () => {
    await enqueueConfig({ mergeRepeated: false })
    const send = vi.fn(async () => {
      throw new Error("algo raro")
    })
    await syncPendingOps({ send, activeRegisterId: CAJA })
    expect((await peekAllOps())[0].status).toBe("failed")
  })

  it("lo que quedó en vuelo tras un crash se rescata al arrancar", async () => {
    const op = await enqueueConfig({ mergeRepeated: false })
    await markOpSyncing(op.opId)

    // Antes del rescate frena su canal: no sabemos si la primera llegó.
    const { send } = okSender()
    await syncPendingOps({ send, activeRegisterId: CAJA })
    expect(send).not.toHaveBeenCalled()

    expect(await reviveInterruptedOps()).toBe(1)
    const luego = await syncPendingOps({
      send,
      activeRegisterId: CAJA,
      now: () => Date.now() + 31 * 60_000,
    })
    expect(luego.synced).toBe(1)
  })

  it("el reintento manual pone el contador en cero (no castiga al que esperó)", async () => {
    await enqueueConfig({ mergeRepeated: false })
    const send = vi.fn(async () => {
      throw new PendingOpError("HTTP_500", "boom", false)
    })
    await syncPendingOps({ send, activeRegisterId: CAJA })

    const [fallida] = await peekAllOps()
    expect(fallida.attempts).toBe(1)

    await retryOp(fallida.opId)
    const [lista] = await peekAllOps()
    expect(lista.status).toBe("pending")
    expect(lista.attempts).toBe(0)
    expect(lista.lastAttemptAt).toBeUndefined()
    expect(lista.error).toBeUndefined()
  })
})

// ── Conflicto caja vs panel ───────────────────────────────────────────────────

describe("conflicto entre la caja y el panel", () => {
  /**
   * La regla elegida: la caja gana en los campos QUE TOCÓ; el panel conserva
   * todo lo demás. Se implementa guardando un PATCH (no la config entera) y
   * dejando que el servidor lo mergee sobre lo que tenga.
   *
   * Estas dos pruebas son la regla escrita como comportamiento, no como
   * comentario: si alguien "simplifica" el encolado mandando la config
   * completa, la primera falla.
   */
  it("campos distintos: sobreviven los dos cambios", async () => {
    // El cajero, sin red, apaga el agrupado de repetidos.
    await enqueueConfig({ mergeRepeated: false })

    // Mientras tanto, desde el panel, alguien prende el teclado virtual.
    const desdeElPanel: PosRegisterConfig = {
      ...POS_REGISTER_CONFIG_DEFAULTS,
      showSoftKeyboard: true,
    }

    // Lo que se manda es SOLO lo que la caja tocó.
    const [op] = await peekAllOps()
    expect(op.payload).toEqual({ mergeRepeated: false })

    // Y el merge del servidor conserva el cambio del panel.
    const resultado = { ...desdeElPanel, ...(op.payload as Partial<PosRegisterConfig>) }
    expect(resultado.mergeRepeated).toBe(false)
    expect(resultado.showSoftKeyboard).toBe(true)
  })

  it("el MISMO campo en los dos lados: gana la caja", async () => {
    await enqueueConfig({ modoSoloOrdenes: true })
    const desdeElPanel: PosRegisterConfig = {
      ...POS_REGISTER_CONFIG_DEFAULTS,
      modoSoloOrdenes: false,
    }
    const [op] = await peekAllOps()
    const resultado = { ...desdeElPanel, ...(op.payload as Partial<PosRegisterConfig>) }
    expect(resultado.modoSoloOrdenes).toBe(true)
  })
})

// ── Cerco por caja ────────────────────────────────────────────────────────────

describe("cerco por caja", () => {
  it("una operación de otra caja no se aplica sobre la caja actual", async () => {
    await enqueueConfig({ mergeRepeated: false }, OTRA_CAJA)
    const { send } = okSender()

    const res = await syncPendingOps({ send, activeRegisterId: CAJA })

    expect(send).not.toHaveBeenCalled()
    expect(res.failed).toBe(1)
    const [op] = await peekAllOps()
    expect(op.status).toBe("failed")
    expect(op.error?.code).toBe("REGISTER_CHANGED")
  })

  it("vuelve a poder enviarse cuando el device regresa a esa caja", async () => {
    await enqueueConfig({ mergeRepeated: false }, OTRA_CAJA)
    const { send } = okSender()
    await syncPendingOps({ send, activeRegisterId: CAJA })

    await retryOp((await peekAllOps())[0].opId)
    const res = await syncPendingOps({ send, activeRegisterId: OTRA_CAJA })

    expect(res.synced).toBe(1)
    expect(await getOpsCount()).toBe(0)
  })
})

// ── D8: la cuenta impaga no mata nada ─────────────────────────────────────────
//
// Invariante del owner (context/34-admin-saas-plan.md §F7, D8): con la cuenta
// del comercio bloqueada por falta de pago, el backend responde 403 a TODO.
// Ese 403 NO puede terminar en `failed` — en esta cola puede haber un CIERRE DE
// CAJA, y en la cola gemela una venta ya emitida, impresa y cobrada. La
// disposición correcta es ESPERA: sin contar intentos, sin escribir error, sin
// agotarse nunca.

describe("cuenta impaga (D8)", () => {
  it("el 403 de cuenta bloqueada es ESPERA: no cuenta intento, no escribe error", async () => {
    await enqueueConfig({ mergeRepeated: false })
    const send = vi.fn(async () => {
      throw new PendingOpError(ACCOUNT_BLOCKED_CODE, ACCOUNT_BLOCKED_NOTE, false, true)
    })

    const res = await syncPendingOps({ send, activeRegisterId: CAJA })

    expect(res.failed).toBe(0)
    expect(res.retried).toBe(0)
    expect(res.waiting).toEqual([
      { opId: (await peekAllOps())[0].opId, reason: ACCOUNT_BLOCKED_NOTE },
    ])
    expect(res.halted).toContainEqual({ stream: "pos-config", reason: "waiting" })

    const [op] = await peekAllOps()
    expect(op.status).toBe("pending")
    expect(op.attempts).toBe(0)
    expect(op.error).toBeUndefined()
    expect(await getFailedOpsCount()).toBe(0)
  })

  it("no se agota: sobrevive muchas más pasadas que OPS_MAX_ATTEMPTS", async () => {
    await enqueueConfig({ mergeRepeated: false })
    const bloqueado = vi.fn(async () => {
      throw new PendingOpError(ACCOUNT_BLOCKED_CODE, ACCOUNT_BLOCKED_NOTE, false, true)
    })

    for (let i = 0; i < OPS_MAX_ATTEMPTS * 2; i++) {
      await syncPendingOps({ send: bloqueado, activeRegisterId: CAJA })
    }

    expect(await getFailedOpsCount()).toBe(0)
    expect((await peekAllOps())[0].status).toBe("pending")

    // Regularizado el pago, sale sola en la pasada siguiente. Y sin backoff
    // acumulado: la espera nunca tocó `attempts` ni `lastAttemptAt`.
    const { send } = okSender()
    const res = await syncPendingOps({ send, activeRegisterId: CAJA })
    expect(res.synced).toBe(1)
    expect(await getOpsCount()).toBe(0)
  })

  it("el motivo sale de `details.reason` del envelope, NUNCA del texto del mensaje", () => {
    const blocked = new ApiError(
      403,
      {
        ok: false,
        error: {
          message: "Cuenta bloqueada por falta de pago",
          code: 403,
          details: { reason: "account_blocked" },
        },
      },
      "Cuenta bloqueada por falta de pago",
    )
    expect(isAccountBlocked(blocked)).toBe(true)

    // Un 403 cualquiera sigue siendo terminal: sin motivo en el sobre el
    // comportamiento no cambia (fail-closed hacia lo de antes).
    const permisos = new ApiError(
      403,
      { ok: false, error: { message: "Sin permiso", code: 403 } },
      "Sin permiso",
    )
    expect(isAccountBlocked(permisos)).toBe(false)

    // El mismo texto SIN el motivo estructurado no alcanza. Es el punto de todo
    // el mecanismo: si esto matcheara, mejorar un copy mataría ventas reales.
    const impostor = new ApiError(
      403,
      { ok: false, error: { message: "Cuenta bloqueada por falta de pago", code: 403 } },
      "x",
    )
    expect(isAccountBlocked(impostor)).toBe(false)

    // Un tenant CANCELADO no espera para siempre: eso no se destraba solo.
    const cancelado = new ApiError(
      403,
      {
        ok: false,
        error: {
          message: "La cuenta no está activa",
          code: 403,
          details: { reason: "account_inactive" },
        },
      },
      "x",
    )
    expect(isAccountBlocked(cancelado)).toBe(false)
  })
})
