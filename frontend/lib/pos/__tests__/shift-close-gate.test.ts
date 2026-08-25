/**
 * Gate de cierre de turno — parte pura.
 *
 * Lo que se verifica acá es lo que el cajero LEE cuando no puede cerrar y lo
 * que decide si el botón se deshabilita. El resto del gate (la consulta, y la
 * regla de verdad) vive en el servidor y tiene su propio camino.
 */
import { describe, expect, it } from "vitest"

import {
  EMPTY_SHIFT_CLOSE_BLOCKERS,
  blockerOrderLabel,
  blockerSpaceLabel,
  parseShiftCloseBlockers,
  shiftCloseBlockedSummary,
  type ShiftCloseBlockers,
} from "@/lib/pos/shift-close-gate"

function blockers(patch: Partial<ShiftCloseBlockers> = {}): ShiftCloseBlockers {
  return { ...EMPTY_SHIFT_CLOSE_BLOCKERS, ...patch }
}

describe("parseShiftCloseBlockers", () => {
  it("devuelve el vacío ante basura", () => {
    expect(parseShiftCloseBlockers(null)).toEqual(EMPTY_SHIFT_CLOSE_BLOCKERS)
    expect(parseShiftCloseBlockers("nope")).toEqual(EMPTY_SHIFT_CLOSE_BLOCKERS)
    expect(parseShiftCloseBlockers(undefined)).toEqual(EMPTY_SHIFT_CLOSE_BLOCKERS)
  })

  it("no explota si orders/spaces vienen null — el .map() del JSX es la pantalla del arqueo", () => {
    const r = parseShiftCloseBlockers({ enabled: true, total: 2, orders: null, spaces: null })
    expect(r.orders).toEqual([])
    expect(r.spaces).toEqual([])
    expect(r.total).toBe(2)
  })

  it("cae a la longitud de las listas cuando el servidor no mandó conteos", () => {
    const r = parseShiftCloseBlockers({
      enabled: true,
      orders: [{ id: "a", number: 1, status: "open", source: "counter", space: null }],
      spaces: [{ id: "s", name: "Mesa 1", status: "open" }],
    })
    expect(r.orderCount).toBe(1)
    expect(r.spaceCount).toBe(1)
    expect(r.total).toBe(2)
  })

  it("cree a los conteos del servidor por encima del detalle acotado", () => {
    // El servidor manda 25 filas de detalle y el conteo real. Si el front
    // contara las filas, el cajero vería "25 órdenes" con 40 abiertas.
    const r = parseShiftCloseBlockers({
      enabled: true,
      orderCount: 40,
      spaceCount: 0,
      total: 40,
      orders: new Array(25).fill(null).map((_, i) => ({
        id: `o${i}`, number: i, status: "open", source: "counter", space: null,
      })),
      spaces: [],
      truncated: true,
    })
    expect(r.orderCount).toBe(40)
    expect(r.orders).toHaveLength(25)
    expect(r.truncated).toBe(true)
  })

  it("enabled solo con true explícito — un payload viejo no prende la regla", () => {
    expect(parseShiftCloseBlockers({ total: 3 }).enabled).toBe(false)
    expect(parseShiftCloseBlockers({ enabled: true, total: 3 }).enabled).toBe(true)
  })
})

describe("shiftCloseBlockedSummary", () => {
  it("singular y plural, por separado en cada dimensión", () => {
    expect(shiftCloseBlockedSummary(blockers({ orderCount: 1, total: 1 }))).toBe(
      "No se puede cerrar el turno: la sucursal tiene 1 orden abierta.",
    )
    expect(shiftCloseBlockedSummary(blockers({ orderCount: 3, total: 3 }))).toBe(
      "No se puede cerrar el turno: la sucursal tiene 3 órdenes abiertas.",
    )
    expect(shiftCloseBlockedSummary(blockers({ spaceCount: 1, total: 1 }))).toBe(
      "No se puede cerrar el turno: la sucursal tiene 1 espacio abierto.",
    )
  })

  it("junta las dos dimensiones con 'y'", () => {
    expect(
      shiftCloseBlockedSummary(blockers({ orderCount: 2, spaceCount: 1, total: 3 })),
    ).toBe("No se puede cerrar el turno: la sucursal tiene 2 órdenes abiertas y 1 espacio abierto.")
  })

  it("sin nada abierto no inventa un motivo", () => {
    expect(shiftCloseBlockedSummary(EMPTY_SHIFT_CLOSE_BLOCKERS)).toBe(
      "No se puede cerrar el turno.",
    )
  })
})

describe("etiquetas de la lista", () => {
  it("la orden de un espacio dice de cuál — es como el cajero la ubica", () => {
    expect(
      blockerOrderLabel({ id: "o", number: 14, status: "open", source: "table", space: "Mesa 3" }),
    ).toBe("Orden #14 — Mesa 3")
  })

  it("una orden sin número no se muestra como 'Orden #null'", () => {
    expect(
      blockerOrderLabel({ id: "o", number: null, status: "open", source: "counter", space: null }),
    ).toBe("Orden sin número")
  })

  it("el espacio que ya pidió la cuenta se distingue del que sigue consumiendo", () => {
    expect(blockerSpaceLabel({ id: "s", name: "Mesa 4", status: "bill_requested" })).toBe(
      "Mesa 4 — cuenta pedida",
    )
    expect(blockerSpaceLabel({ id: "s", name: "Mesa 4", status: "open" })).toBe("Mesa 4")
  })
})
