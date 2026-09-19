import { describe, expect, it } from "vitest"

import {
  errorPaths,
  firstSubtabError,
  subtabsWithErrors,
} from "@/lib/forms/subtab-errors"

const TABS = [
  { id: "general", fields: ["name", "outletIds"] },
  { id: "precio", fields: ["price", "currencies"] },
  { id: "inventario", fields: ["replenishQty", "availability"] },
  { id: "avanzado" },
]

describe("errorPaths", () => {
  it("aplana errores anidados y de arrays", () => {
    const errors = {
      name: { type: "too_small", message: "El nombre es requerido" },
      availability: {
        days: { mon: { from: { type: "invalid", message: "x" } } },
      },
      outletIds: { root: { type: "too_small", message: "y" } },
    }
    expect(errorPaths(errors)).toEqual([
      "name",
      "availability.days.mon.from",
      "outletIds",
    ])
  })

  it("ignora ref y metadatos del error", () => {
    const errors = { price: { type: "x", message: "m", ref: { name: "price" } } }
    expect(errorPaths(errors)).toEqual(["price"])
  })

  it("sin errores devuelve vacío", () => {
    expect(errorPaths({})).toEqual([])
    expect(errorPaths(undefined)).toEqual([])
  })
})

describe("subtabsWithErrors", () => {
  it("marca la sub-pestaña que contiene el campo o un hijo", () => {
    const errors = {
      price: { type: "x", message: "m" },
      availability: { days: { tue: { to: { type: "x", message: "m" } } } },
    }
    expect([...subtabsWithErrors(TABS, errors)].sort()).toEqual([
      "inventario",
      "precio",
    ])
  })

  it("no confunde prefijos de nombre (priceType no es price)", () => {
    const errors = { priceType: { type: "x", message: "m" } }
    expect(subtabsWithErrors(TABS, errors).size).toBe(0)
  })
})

describe("firstSubtabError", () => {
  it("elige la primera sub-pestaña en el orden de la lista, no del error", () => {
    const errors = {
      replenishQty: { type: "x", message: "m" },
      currencies: { USD: { type: "x", message: "m" } },
    }
    expect(firstSubtabError(TABS, errors)).toEqual({
      tabId: "precio",
      field: "currencies.USD",
    })
  })

  it("null si el error no cae en ninguna sub-pestaña", () => {
    expect(firstSubtabError(TABS, { other: { type: "x", message: "m" } })).toBeNull()
  })
})
