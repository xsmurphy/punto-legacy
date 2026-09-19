import { describe, expect, it } from "vitest"

import { formatIntCompact, formatMoneyCompact } from "@/lib/format"

const py = { currency: "Gs", thousand: "dot", decimal: "no" } as never
const us = { currency: "USD", thousand: "comma", decimal: "yes" } as never

describe("formato compacto", () => {
  it("millones y miles con el separador del tenant", () => {
    expect(formatMoneyCompact(1_500_000, py)).toBe("Gs 1,5 M")
    expect(formatMoneyCompact(18_991_000, py)).toBe("Gs 19 M")
    expect(formatMoneyCompact(820_000, py)).toBe("Gs 820 k")
    expect(formatMoneyCompact(1_500_000, us)).toBe("USD 1.5 M")
    expect(formatIntCompact(13_431, py)).toBe("13,4 k")
  })

  it("por debajo de mil, el número completo", () => {
    expect(formatIntCompact(590, py)).toBe("590")
    expect(formatMoneyCompact(950, py)).toBe("Gs 950")
  })
})
