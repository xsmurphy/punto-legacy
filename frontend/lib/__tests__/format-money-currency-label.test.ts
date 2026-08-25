import { describe, expect, it } from "vitest"
import { formatMoney, resolveCurrencyLabel } from "@/lib/format-money"

/**
 * Regresión del bug del botón sin texto en el NumericPad: el BFF del bootstrap
 * normaliza la moneda ausente a string VACÍO, y el `?? "Gs"` que había en cada
 * call-site no lo cubría porque `??` solo dispara con null/undefined.
 */
describe("resolveCurrencyLabel", () => {
  it("respeta la moneda del tenant", () => {
    expect(resolveCurrencyLabel({ currency: "$" })).toBe("$")
    expect(resolveCurrencyLabel({ currency: "R$" })).toBe("R$")
  })

  it("nunca devuelve vacío — string vacío, espacios, null y undefined caen al default", () => {
    expect(resolveCurrencyLabel({ currency: "" })).toBe("Gs")
    expect(resolveCurrencyLabel({ currency: "   " })).toBe("Gs")
    expect(resolveCurrencyLabel(null)).toBe("Gs")
  })
})

describe("formatMoney", () => {
  it("no deja un prefijo vacío cuando el tenant no configuró moneda", () => {
    expect(formatMoney(55000, { currency: "", thousand: "dot", decimal: "no" })).toBe("Gs 55.000")
  })

  it("usa la moneda configurada", () => {
    expect(formatMoney(1234.5, { currency: "$", thousand: "comma", decimal: "yes" })).toBe("$ 1,234.50")
  })
})
