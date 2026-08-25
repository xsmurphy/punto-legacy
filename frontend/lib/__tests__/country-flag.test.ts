import { describe, expect, it } from "vitest"
import { countryFlagEmoji, currencyFlagEmoji, UNKNOWN_FLAG } from "@/lib/country-flag"

describe("countryFlagEmoji", () => {
  it("mapea alpha-2 a regional indicators", () => {
    expect(countryFlagEmoji("PY")).toBe("🇵🇾")
    expect(countryFlagEmoji("us")).toBe("🇺🇸")
  })

  it("cae al globo con entrada vacía o inválida", () => {
    expect(countryFlagEmoji(null)).toBe(UNKNOWN_FLAG)
    expect(countryFlagEmoji("")).toBe(UNKNOWN_FLAG)
    expect(countryFlagEmoji("PRY")).toBe(UNKNOWN_FLAG)
    expect(countryFlagEmoji("1A")).toBe(UNKNOWN_FLAG)
  })
})

describe("currencyFlagEmoji", () => {
  it("deriva el país de las dos primeras letras del ISO 4217", () => {
    expect(currencyFlagEmoji("PYG")).toBe("🇵🇾")
    expect(currencyFlagEmoji("USD")).toBe("🇺🇸")
    expect(currencyFlagEmoji("BRL")).toBe("🇧🇷")
    expect(currencyFlagEmoji("ars")).toBe("🇦🇷")
  })

  it("EUR resuelve a la bandera de la UE por el mismo camino", () => {
    expect(currencyFlagEmoji("EUR")).toBe("🇪🇺")
  })

  it("los códigos supranacionales (X…) no inventan país", () => {
    expect(currencyFlagEmoji("XAF")).toBe(UNKNOWN_FLAG)
    expect(currencyFlagEmoji("XOF")).toBe(UNKNOWN_FLAG)
    expect(currencyFlagEmoji("XAU")).toBe(UNKNOWN_FLAG)
  })

  it("cae al globo antes que mostrar una bandera equivocada", () => {
    expect(currencyFlagEmoji(null)).toBe(UNKNOWN_FLAG)
    expect(currencyFlagEmoji("")).toBe(UNKNOWN_FLAG)
    expect(currencyFlagEmoji("PY")).toBe(UNKNOWN_FLAG)
  })
})
