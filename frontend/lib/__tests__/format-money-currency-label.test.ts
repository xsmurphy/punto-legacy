import { describe, expect, it } from "vitest"
import { formatMoney, resolveCurrencyLabel } from "@/lib/format-money"
import { UNKNOWN_CURRENCY_SIGN } from "@/lib/tenant-locale"

/**
 * Cadena de fallbacks de la etiqueta de moneda.
 *
 * Cubre dos bugs distintos que se pisaban entre sí:
 *
 *  1. El botón sin texto del NumericPad — el BFF del bootstrap normaliza la
 *     moneda ausente a string VACÍO, y el `?? "Gs"` que había en cada
 *     call-site no lo cubría porque `??` solo dispara con null/undefined.
 *  2. El default paraguayo — cuando el tenant no configuró moneda, el
 *     fallback era "Gs" para todo el mundo. Ahora el escalón intermedio es la
 *     moneda del PAÍS del tenant, y solo si tampoco hay país se cae al signo
 *     genérico de ISO 4217.
 */
describe("resolveCurrencyLabel", () => {
  it("respeta la moneda configurada del tenant", () => {
    expect(resolveCurrencyLabel({ currency: "$" })).toBe("$")
    expect(resolveCurrencyLabel({ currency: "R$" })).toBe("R$")
  })

  it("trata string vacío y espacios como AUSENTE, no como una moneda válida", () => {
    // Este es el caso que `??` no cubría y dejaba el botón sin label.
    expect(resolveCurrencyLabel({ currency: "", country: "PY" })).toBe("Gs")
    expect(resolveCurrencyLabel({ currency: "   ", country: "PY" })).toBe("Gs")
  })

  it("cae a la moneda del PAÍS del tenant, no a Paraguay", () => {
    expect(resolveCurrencyLabel({ currency: "", country: "BR" })).toBe("R$")
    expect(resolveCurrencyLabel({ currency: "", country: "PE" })).toBe("S/")
    expect(resolveCurrencyLabel({ currency: "", country: "ES" })).toBe("€")
    // Un tenant paraguayo sin moneda configurada sigue viendo guaraníes:
    // ahora porque su PAÍS lo dice, no porque sea el default de todos.
    expect(resolveCurrencyLabel({ currency: "", country: "PY" })).toBe("Gs")
  })

  it("sin moneda NI país devuelve el signo genérico, nunca vacío ni Gs", () => {
    expect(resolveCurrencyLabel({ currency: "", country: "" })).toBe(UNKNOWN_CURRENCY_SIGN)
    expect(resolveCurrencyLabel(null)).toBe(UNKNOWN_CURRENCY_SIGN)
    expect(resolveCurrencyLabel(undefined)).toBe(UNKNOWN_CURRENCY_SIGN)
    // No vacío: eso reintroduciría el bug del botón sin texto.
    expect(resolveCurrencyLabel(null)).not.toBe("")
  })
})

describe("formatMoney", () => {
  it("no deja un prefijo vacío cuando el tenant no configuró moneda", () => {
    expect(formatMoney(55000, { currency: "", country: "PY", thousand: "dot", decimal: "no" })).toBe(
      "Gs 55.000",
    )
  })

  it("usa la moneda configurada", () => {
    expect(formatMoney(1234.5, { currency: "$", thousand: "comma", decimal: "yes" })).toBe("$ 1,234.50")
  })

  it("respeta los separadores y decimales del país cuando no vienen explícitos", () => {
    // BR: miles con punto, 2 decimales.
    expect(formatMoney(1234.5, { currency: "", country: "BR" })).toBe("R$ 1.234,50")
    // MX: miles con coma, 2 decimales.
    expect(formatMoney(1234.5, { currency: "", country: "MX" })).toBe("$ 1,234.50")
    // PY: miles con punto, sin decimales.
    expect(formatMoney(1234.5, { currency: "", country: "PY" })).toBe("Gs 1.235")
  })
})
