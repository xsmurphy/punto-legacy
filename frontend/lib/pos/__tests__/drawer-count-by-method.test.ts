/**
 * El arqueo del cierre por MEDIO DE PAGO (mig 167).
 *
 * Qué se prueba y por qué esto y no otra cosa:
 *
 *   1. **El efectivo siempre está, y primero.** Es la única fila que no
 *      depende de que haya habido ventas: el fondo inicial está en el cajón
 *      desde que el turno abrió. Si esta fila se cae, el cajero cierra sin
 *      contar la plata, que es el peor resultado posible de esta pantalla.
 *   2. **La lista de medios sobrevive al control a ciegas.** El arqueo a
 *      ciegas oculta los ACUMULADOS, no la existencia de las ventas con
 *      tarjeta. Un cajero que no sabe qué medios tuvo el turno no puede
 *      contarlos y entonces no hay arqueo. Por eso
 *      `computeLocalShiftMethods()` no recibe `blindControl`: es blind-safe
 *      por construcción (no computa montos), y este test es lo que impide que
 *      alguien "mejore" la función agregándole sumas.
 *   3. **La clave de agrupación es la misma de los dos lados.** El servidor
 *      empareja lo contado con lo esperado por `paymentGroupKey`. Si las dos
 *      normalizaciones se separan, el arqueo deja de encontrarse y todo sale
 *      como sobrante — un fallo silencioso y caro.
 *   4. **`parseServerCloseTotals` no inventa filas ni ceros.** `null` es "no
 *      se sabe"; un cero acá se leería como "se contó y no había nada".
 *
 * `environment: "node"`: el módulo es puro a propósito.
 */

import { describe, expect, it } from "vitest"

import {
  computeLocalShiftMethods,
  paymentGroupKey,
} from "@/lib/pos/local-shift-total"
import type { ShiftJournalRow } from "@/lib/pos/offline-db"
import { parseServerCloseTotals } from "@/lib/pos/shift-close-reconciliation"

const REGISTER = "reg-1"
const SHIFT_OPEN = "2026-08-24 08:00:00"

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

describe("computeLocalShiftMethods", () => {
  it("devuelve el efectivo aunque el turno no haya tenido ninguna venta", () => {
    const rows = computeLocalShiftMethods({ entries: [], shiftOpenDate: SHIFT_OPEN })
    expect(rows).toEqual([{ key: "efectivo", name: "Efectivo", code: "cash", isCash: true }])
  })

  it("usa el nombre del efectivo del catálogo del comercio", () => {
    const rows = computeLocalShiftMethods({
      entries: [],
      shiftOpenDate: SHIFT_OPEN,
      cashName: "Contado",
    })
    expect(rows).toEqual([{ key: "contado", name: "Contado", code: "cash", isCash: true }])
  })

  it("pone el efectivo primero y lista los demás medios usados, sin montos", () => {
    const rows = computeLocalShiftMethods({
      entries: [
        sale("s1", "2026-08-24 09:00:00", [
          { name: "T. Crédito", type: "tcredito", total: 50000 },
        ]),
        sale("s2", "2026-08-24 10:00:00", [
          { name: "QR", type: "qr", total: 20000 },
          { name: "Efectivo", type: "cash", total: 10000 },
        ]),
      ],
      shiftOpenDate: SHIFT_OPEN,
    })

    expect(rows[0]).toEqual({ key: "efectivo", name: "Efectivo", code: "cash", isCash: true })
    expect(rows.map((r) => r.name)).toEqual(["Efectivo", "T. Crédito", "QR"])
    // El slug viaja para que el servidor pueda emparejar aunque el nombre
    // resuelto difiera del que anotó esta caja.
    expect(rows.map((r) => r.code)).toEqual(["cash", "tcredito", "qr"])
    // Ninguna fila lleva monto: es lo que la vuelve segura a ciegas.
    for (const r of rows) {
      expect(Object.keys(r).sort()).toEqual(["code", "isCash", "key", "name"])
    }
  })

  it("no duplica el cajón cuando las ventas traen el efectivo con otro nombre", () => {
    const rows = computeLocalShiftMethods({
      entries: [
        sale("s1", "2026-08-24 09:00:00", [{ name: "cash", type: "cash", total: 1000 }]),
      ],
      shiftOpenDate: SHIFT_OPEN,
      cashName: "Efectivo",
    })
    expect(rows.filter((r) => r.isCash)).toHaveLength(1)
    expect(rows).toHaveLength(1)
  })

  it("ignora las ventas internas y las anteriores a la apertura del turno", () => {
    const rows = computeLocalShiftMethods({
      entries: [
        // Turno anterior: fuera de la ventana.
        sale("s0", "2026-08-23 22:00:00", [
          { name: "Cheque", type: "check", total: 5000 },
        ]),
        // Consumo propio: no entra al arqueo, igual que server-side.
        sale("s1", "2026-08-24 09:00:00", [
          { name: "Interno", type: "internal", total: 3000 },
        ], true),
        sale("s2", "2026-08-24 09:30:00", [
          { name: "T. Débito", type: "tdebito", total: 7000 },
        ]),
      ],
      shiftOpenDate: SHIFT_OPEN,
    })
    expect(rows.map((r) => r.name)).toEqual(["Efectivo", "T. Débito"])
  })

  it("agrupa el mismo medio escrito con distinta caja de letras", () => {
    const rows = computeLocalShiftMethods({
      entries: [
        sale("s1", "2026-08-24 09:00:00", [{ name: "T. Crédito", total: 1 }]),
        sale("s2", "2026-08-24 09:05:00", [{ name: "t. crédito", total: 2 }]),
      ],
      shiftOpenDate: SHIFT_OPEN,
    })
    expect(rows).toHaveLength(2) // efectivo + una sola fila de crédito
  })
})

describe("paymentGroupKey", () => {
  it("normaliza igual que DrawerService::paymentGroupKey (minúsculas, sin bordes)", () => {
    expect(paymentGroupKey("  T. Crédito ")).toBe("t. crédito")
    expect(paymentGroupKey("EFECTIVO")).toBe("efectivo")
  })
})

describe("parseServerCloseTotals — arqueo por medio", () => {
  it("lee las filas del servidor conservando los null como 'no se sabe'", () => {
    const parsed = parseServerCloseTotals({
      closing: {
        date: "2026-08-24 08:00:00",
        total: 100,
        subtotal: 60,
        salesTotal: 100,
        returns: 0,
        byMethod: [
          { key: "efectivo", name: "Efectivo", isCash: true, expected: 60, counted: 55, difference: -5 },
          { key: "qr", name: "QR", isCash: false, expected: 40, counted: null, difference: null },
        ],
      },
    })
    expect(parsed?.byMethod).toHaveLength(2)
    expect(parsed?.byMethod[0]).toEqual({
      key: "efectivo",
      name: "Efectivo",
      isCash: true,
      expected: 60,
      counted: 55,
      difference: -5,
    })
    // Sin contar NO es cero: cero diría "se contó y no había nada".
    expect(parsed?.byMethod[1].counted).toBeNull()
    expect(parsed?.byMethod[1].difference).toBeNull()
  })

  it("un backend sin la mig 167 devuelve lista vacía, no filas inventadas", () => {
    const parsed = parseServerCloseTotals({
      closing: { date: "", total: 10, subtotal: 10, salesTotal: 10, returns: 0 },
    })
    expect(parsed?.byMethod).toEqual([])
  })

  it("descarta filas sin clave en vez de arrastrarlas al informe", () => {
    const parsed = parseServerCloseTotals({
      closing: {
        date: "",
        total: 10,
        subtotal: 10,
        salesTotal: 10,
        returns: 0,
        byMethod: [{ name: "Sin clave", expected: 1 }, null, "basura"],
      },
    })
    expect(parsed?.byMethod).toEqual([])
  })
})
