/**
 * El total del turno que la caja muestra sin conexión.
 *
 * Por qué se prueba esto y no otra cosa: el número en sí es una suma, pero lo
 * que lo vuelve defendible —qué entra en la ventana del turno, qué se cuenta
 * una sola vez aunque esté en dos lados, qué hueco se declara y cuándo NO hay
 * que mostrar nada— son decisiones que se leen bien en el diff y se rompen en
 * silencio con un filtro de más. Es plata en una pantalla de arqueo: si el
 * número sale corto, un faltante parece cuadrar.
 *
 * `environment: "node"` + `fake-indexeddb`: sin browser, sin servidor.
 */

import { beforeEach, describe, expect, it } from "vitest"
import "fake-indexeddb/auto"

import {
  computeLocalShiftTotals,
  gapMessages,
  isCashPayment,
} from "@/lib/pos/local-shift-total"
import type { ShiftJournalRow } from "@/lib/pos/offline-db"
import { getPosOfflineDB } from "@/lib/pos/offline-db"
import {
  journalSince,
  readShiftJournal,
  recordDrawerOp,
  recordSale,
} from "@/lib/pos/shift-journal"
import {
  closeTotalsMatch,
  parseServerCloseTotals,
} from "@/lib/pos/shift-close-reconciliation"

const REGISTER = "reg-1"
const SHIFT_OPEN = "2026-08-23 08:00:00"

function sale(
  entryId: string,
  date: string,
  payments: { name: string; type?: string; total: number }[],
  internal = false,
): ShiftJournalRow {
  return {
    entryId,
    registerId: REGISTER,
    kind: "sale",
    date,
    amount: payments.reduce((s, p) => s + p.total, 0),
    payments,
    internal,
    createdAt: new Date().toISOString(),
  }
}

function drawerOp(
  entryId: string,
  kind: Exclude<ShiftJournalRow["kind"], "sale">,
  date: string,
  amount: number,
): ShiftJournalRow {
  return {
    entryId,
    registerId: REGISTER,
    kind,
    date,
    amount,
    createdAt: new Date().toISOString(),
  }
}

const cash = (total: number) => ({ name: "Efectivo", type: "efectivo", total })
const card = (total: number) => ({ name: "T. Débito", type: "tdebito", total })

const base = {
  shiftOpenDate: SHIFT_OPEN,
  heldSince: SHIFT_OPEN,
  journalSince: SHIFT_OPEN,
  blindControl: false,
}

// ── El caso normal ────────────────────────────────────────────────────────────

describe("turno con ventas encoladas y ya sincronizadas", () => {
  // El punto de todo el diseño: el journal anota la venta cuando OCURRE, no
  // cuando viaja. Una venta que ya sincronizó y otra que sigue en la cola pesan
  // exactamente lo mismo en el total — si no, el número bajaría a medida que
  // vuelve la conexión, que es lo peor que puede hacer un total de arqueo.
  const entries = [
    drawerOp("open", "drawerOpen", SHIFT_OPEN, 100_000),
    sale("ya-sincronizada", "2026-08-23 09:00:00", [cash(50_000)]),
    sale("en-cola", "2026-08-23 10:00:00", [cash(30_000), card(20_000)]),
  ]

  it("suma las dos ventas una sola vez cada una", () => {
    const totals = computeLocalShiftTotals({ ...base, entries })!
    expect(totals.salesCount).toBe(2)
    expect(totals.salesTotal).toBe(100_000)
    // 100.000 inicial + 100.000 vendido
    expect(totals.total).toBe(200_000)
  })

  it("separa el efectivo del resto para el conteo del cajón", () => {
    const totals = computeLocalShiftTotals({ ...base, entries })!
    expect(totals.cashSales).toBe(80_000)
    // El cajón tiene el inicial + lo cobrado en efectivo, no la tarjeta.
    expect(totals.cashTotal).toBe(180_000)
    expect(totals.byMethod).toEqual([
      { name: "Efectivo", amount: 80_000 },
      { name: "T. Débito", amount: 20_000 },
    ])
  })

  it("aplica ingresos y extracciones con el signo de composeSummary", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      entries: [
        ...entries,
        drawerOp("out", "drawerExpense", "2026-08-23 11:00:00", 25_000),
        drawerOp("in", "drawerIncome", "2026-08-23 12:00:00", 5_000),
      ],
    })!
    expect(totals.cashOut).toBe(25_000)
    expect(totals.cashIn).toBe(5_000)
    expect(totals.total).toBe(180_000)
    expect(totals.cashTotal).toBe(160_000)
  })

  it("no cuenta las ventas internas, igual que el servidor", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      entries: [...entries, sale("consumo", "2026-08-23 11:30:00", [cash(9_000)], true)],
    })!
    expect(totals.salesCount).toBe(2)
    expect(totals.salesTotal).toBe(100_000)
  })

  it("deja afuera lo que pasó antes de que el turno abriera", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      entries: [sale("de-ayer", "2026-08-22 20:00:00", [cash(999_000)]), ...entries],
    })!
    expect(totals.salesTotal).toBe(100_000)
    expect(totals.windowStart).toBe(SHIFT_OPEN)
  })

  it("solo declara el hueco permanente cuando cubre el turno entero", () => {
    const totals = computeLocalShiftTotals({ ...base, entries })!
    expect(totals.gaps).toEqual(["panel-movements"])
    expect(gapMessages(totals.gaps)).toHaveLength(1)
  })
})

// ── Tenencia tomada a mitad del turno ─────────────────────────────────────────

describe("tenencia tomada con el turno ya abierto", () => {
  // El único de los tres huecos del brief que el device puede DETECTAR: si
  // tomó la caja a las 12 y el turno abrió a las 8, hubo cuatro horas en las
  // que otro dispositivo pudo haber vendido. El total se muestra igual, con la
  // advertencia escrita al lado.
  const entries = [sale("mia", "2026-08-23 13:00:00", [cash(40_000)])]

  it("declara el hueco de tenencia", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      entries,
      heldSince: "2026-08-23 12:00:00",
    })!
    expect(totals.gaps).toContain("tenancy-mid-shift")
  })

  it("muestra el total igual — el hueco se dice, no se calla el número", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      entries,
      heldSince: "2026-08-23 12:00:00",
    })!
    expect(totals.salesTotal).toBe(40_000)
  })

  it("avisa que no conoce el monto inicial si la apertura no fue de este aparato", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      entries,
      heldSince: "2026-08-23 12:00:00",
    })!
    expect(totals.gaps).toContain("no-open-entry")
    expect(totals.openAmount).toBe(0)
  })

  it("declara el hueco del registro cuando el journal arrancó tarde", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      entries,
      journalSince: "2026-08-23 12:30:00",
    })!
    expect(totals.gaps).toContain("journal-mid-shift")
  })

  it("sin apertura conocida no recorta ventana y lo dice", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      entries,
      shiftOpenDate: null,
      heldSince: null,
      journalSince: null,
    })!
    expect(totals.gaps).toContain("shift-open-unknown")
    expect(totals.salesTotal).toBe(40_000)
  })

  it("cada hueco tiene su línea, en orden de importancia", () => {
    const msgs = gapMessages(["panel-movements", "tenancy-mid-shift"])
    expect(msgs).toHaveLength(2)
    expect(msgs[0]).toContain("tomó la caja con el turno ya abierto")
    expect(msgs[1]).toContain("panel")
  })
})

// ── Control de caja a ciegas ──────────────────────────────────────────────────

describe("blindControl", () => {
  // La regla vive en el cálculo, no en el JSX: con el control a ciegas
  // prendido no hay total, y que la red se caiga no es una excusa para
  // mostrarlo. Cualquier pantalla que llame a esta función lo respeta sin
  // tener que acordarse.
  it("no devuelve ningún total", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      blindControl: true,
      entries: [
        drawerOp("open", "drawerOpen", SHIFT_OPEN, 100_000),
        sale("v1", "2026-08-23 09:00:00", [cash(50_000)]),
      ],
    })
    expect(totals).toBeNull()
  })
})

// ── Turno sin ventas ──────────────────────────────────────────────────────────

describe("turno sin ventas", () => {
  it("muestra el inicial y nada más", () => {
    const totals = computeLocalShiftTotals({
      ...base,
      entries: [drawerOp("open", "drawerOpen", SHIFT_OPEN, 100_000)],
    })!
    expect(totals.salesCount).toBe(0)
    expect(totals.byMethod).toEqual([])
    expect(totals.total).toBe(100_000)
    expect(totals.cashTotal).toBe(100_000)
    expect(totals.empty).toBe(true)
  })

  it("un turno completamente vacío da cero, no null", () => {
    const totals = computeLocalShiftTotals({ ...base, entries: [] })!
    expect(totals.total).toBe(0)
    expect(totals.empty).toBe(true)
    expect(totals.gaps).toContain("no-open-entry")
  })
})

// ── Medios de pago ────────────────────────────────────────────────────────────

describe("detección de efectivo", () => {
  it("usa el slug, con el nombre como respaldo", () => {
    expect(isCashPayment({ name: "Efectivo", type: "efectivo", total: 1 })).toBe(true)
    expect(isCashPayment({ name: "Contado", type: "cash", total: 1 })).toBe(true)
    expect(isCashPayment({ name: "Efectivo", total: 1 })).toBe(true)
    expect(isCashPayment({ name: "T. Débito", type: "tdebito", total: 1 })).toBe(false)
    // Un medio custom llamado "Efectivo del delivery" NO es efectivo del cajón:
    // tiene su propio slug y el nombre no se usa cuando el slug está.
    expect(isCashPayment({ name: "Efectivo delivery", type: "tax-123", total: 1 })).toBe(
      false,
    )
  })
})

// ── El journal contra IndexedDB ───────────────────────────────────────────────

describe("registro del turno", () => {
  beforeEach(async () => {
    const db = await getPosOfflineDB()
    await db.clear("shiftJournal")
    await db.clear("snapshots")
  })

  it("anota una venta una sola vez aunque se registre dos veces", async () => {
    const payments = [cash(10_000)]
    await recordSale({ registerId: REGISTER, uid: "uid-1", date: SHIFT_OPEN, payments })
    await recordSale({ registerId: REGISTER, uid: "uid-1", date: SHIFT_OPEN, payments })
    const rows = await readShiftJournal(REGISTER)
    expect(rows).toHaveLength(1)
  })

  it("no mezcla cajas", async () => {
    await recordSale({
      registerId: REGISTER,
      uid: "uid-1",
      date: SHIFT_OPEN,
      payments: [cash(10_000)],
    })
    await recordSale({
      registerId: "otra-caja",
      uid: "uid-2",
      date: SHIFT_OPEN,
      payments: [cash(99_000)],
    })
    const rows = await readShiftJournal(REGISTER)
    expect(rows).toHaveLength(1)
    expect(rows[0].entryId).toBe("uid-1")
  })

  it("la apertura poda el turno anterior y reencuadra el registro", async () => {
    await recordSale({
      registerId: REGISTER,
      uid: "vieja",
      date: "2026-08-22 10:00:00",
      payments: [cash(77_000)],
    })
    await recordDrawerOp({
      registerId: REGISTER,
      entryId: "op-open",
      kind: "drawerOpen",
      date: SHIFT_OPEN,
      amount: 100_000,
    })
    const rows = await readShiftJournal(REGISTER)
    expect(rows.map((r) => r.entryId)).toEqual(["op-open"])
    expect(await journalSince(REGISTER)).toBe(SHIFT_OPEN)
  })

  it("el total de punta a punta sale de lo anotado", async () => {
    await recordDrawerOp({
      registerId: REGISTER,
      entryId: "op-open",
      kind: "drawerOpen",
      date: SHIFT_OPEN,
      amount: 100_000,
    })
    await recordSale({
      registerId: REGISTER,
      uid: "uid-1",
      date: "2026-08-23 09:30:00",
      payments: [cash(20_000), card(5_000)],
    })
    const totals = computeLocalShiftTotals({
      ...base,
      entries: await readShiftJournal(REGISTER),
      journalSince: await journalSince(REGISTER),
    })!
    expect(totals.total).toBe(125_000)
    expect(totals.cashTotal).toBe(120_000)
    expect(totals.gaps).toEqual(["panel-movements"])
  })
})

// ── Reconciliación del cierre ─────────────────────────────────────────────────

describe("comparación con el arqueo del servidor", () => {
  const local = { total: 200_000, cash: 180_000, salesCount: 2, gaps: [] as string[] }

  it("coincide dentro del ruido del punto flotante", () => {
    expect(
      closeTotalsMatch(local, {
        date: SHIFT_OPEN,
        total: 200_000.0001,
        subtotal: 0,
        salesTotal: 0,
        returns: 0,
        byMethod: [],
      }),
    ).toBe(true)
  })

  it("no coincide cuando el servidor tiene una operación que la caja no vio", () => {
    expect(
      closeTotalsMatch(local, {
        date: SHIFT_OPEN,
        total: 150_000,
        subtotal: 0,
        salesTotal: 0,
        returns: 0,
        byMethod: [],
      }),
    ).toBe(false)
  })

  it("sin uno de los dos números no hay diferencia que denunciar", () => {
    expect(closeTotalsMatch(local, null)).toBe(true)
    expect(closeTotalsMatch(null, null)).toBe(true)
  })

  it("un backend sin el campo no se lee como un turno de cero", () => {
    expect(parseServerCloseTotals({ message: "true" })).toBeNull()
    expect(parseServerCloseTotals(null)).toBeNull()
    // `byMethod: []` y no ausente: el arqueo por medio (mig 167) llega vacío
    // cuando el backend todavía no lo manda, que es distinto de inventar filas.
    expect(parseServerCloseTotals({ closing: { total: "200000", date: SHIFT_OPEN } })).toEqual(
      { date: SHIFT_OPEN, total: 200_000, subtotal: 0, salesTotal: 0, returns: 0, byMethod: [] },
    )
  })
})
