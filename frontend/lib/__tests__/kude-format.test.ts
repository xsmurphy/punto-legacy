import { describe, expect, it } from "vitest"

import {
  consultationUrl,
  formatAmount,
  formatQuantity,
  groupCdc,
  type KudeFormat,
} from "@/lib/kude/types"

/**
 * Helpers puros del KuDE propio (`context/73`). Se testean estos y no el
 * template porque son los que pueden imprimir un NÚMERO equivocado en un
 * documento fiscal: el resto es layout.
 */

// Tenant sin decimales, miles con punto. Moneda deliberadamente NO paraguaya:
// el formateo sale de los ajustes del tenant, no de un país.
const noDecimals: KudeFormat = { thousand: ".", decimal: ",", decimals: 0, currency: "CLP" }
// Tenant con centavos y la convención inversa de separadores.
const withCents: KudeFormat = { thousand: ",", decimal: ".", decimals: 2, currency: "USD" }

describe("formatAmount", () => {
  it("agrupa miles con el separador del tenant y sin decimales", () => {
    expect(formatAmount(1234567, noDecimals)).toBe("1.234.567")
    expect(formatAmount(999, noDecimals)).toBe("999")
    expect(formatAmount(0, noDecimals)).toBe("0")
  })

  it("respeta la convención inversa de separadores", () => {
    expect(formatAmount(1234567.5, withCents)).toBe("1,234,567.50")
  })

  it("conserva el signo del negativo (una nota de crédito lo necesita)", () => {
    expect(formatAmount(-1500, noDecimals)).toBe("-1.500")
  })

  it("no imprime NaN en un documento fiscal", () => {
    expect(formatAmount(Number.NaN, noDecimals)).toBe("0")
  })
})

describe("formatQuantity", () => {
  it("no rellena con ceros y usa el decimal del tenant", () => {
    expect(formatQuantity(2, noDecimals)).toBe("2")
    expect(formatQuantity(1.5, noDecimals)).toBe("1,5")
    expect(formatQuantity(0.125, withCents)).toBe("0.125")
  })
})

describe("groupCdc", () => {
  it("parte el CDC en once grupos de cuatro (MT 13.4.4)", () => {
    const cdc = "0".repeat(43) + "1"
    const grouped = groupCdc(cdc)

    expect(grouped.split(" ")).toHaveLength(11)
    expect(grouped.replace(/ /g, "")).toBe(cdc)
  })
})

describe("consultationUrl", () => {
  it("deriva la URL de consulta del QR, sin sus parámetros", () => {
    expect(consultationUrl("https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=01")).toBe(
      "https://ekuatia.set.gov.py/consultas/qr",
    )
  })

  it("devuelve null si la emisión no trajo el QR o no es una URL", () => {
    expect(consultationUrl(null)).toBeNull()
    expect(consultationUrl("no-es-una-url")).toBeNull()
  })
})
