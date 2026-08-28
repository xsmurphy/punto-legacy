import { describe, it, expect } from "vitest"

import { POS_TITLE_FALLBACK, selectPosWindowTitle } from "../window-title"

/**
 * El título de la ventana es lo único que distingue una caja de otra cuando el
 * comerciante tiene varias PWA abiertas. Antes decía "Punto" en todas (reporte
 * del owner 2026-08-28).
 */

const base = {
  config: { companyName: "Sushi Rox" },
  outlet: { name: "Centro" },
  registers: [
    { id: "r1", name: "Caja 1" },
    { id: "r2", name: "Caja 2" },
  ],
  activeRegisterId: "r1",
}

describe("selectPosWindowTitle", () => {
  it("arma `Comercio · Sucursal · Caja`", () => {
    expect(selectPosWindowTitle(base)).toBe("Sushi Rox · Centro · Caja 1")
  })

  it("usa la caja ACTIVA, no la primera del roster", () => {
    expect(selectPosWindowTitle({ ...base, activeRegisterId: "r2" })).toBe(
      "Sushi Rox · Centro · Caja 2",
    )
  })

  it("saltea lo que todavía no hidrató en vez de dejar separadores huérfanos", () => {
    expect(selectPosWindowTitle({ ...base, outlet: null })).toBe("Sushi Rox · Caja 1")
    expect(selectPosWindowTitle({ ...base, activeRegisterId: null })).toBe("Sushi Rox · Centro")
  })

  it("un nombre en blanco no cuenta como nombre", () => {
    expect(selectPosWindowTitle({ ...base, outlet: { name: "   " } })).toBe("Sushi Rox · Caja 1")
  })

  it("sin nada hidratado cae a la marca", () => {
    expect(
      selectPosWindowTitle({ config: null, outlet: null, registers: [], activeRegisterId: null }),
    ).toBe(POS_TITLE_FALLBACK)
  })
})
