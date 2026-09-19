import { describe, expect, it } from "vitest"

import { formatIntCompact, formatMoneyCompact } from "@/lib/format"

const dot = { currency: "$", thousand: "dot", decimal: "no" } as never
const us = { currency: "USD", thousand: "comma", decimal: "yes" } as never

describe("formato compacto", () => {
  it("millones y miles con el separador del tenant", () => {
    expect(formatMoneyCompact(1_500_000, dot)).toBe("$ 1,5 M")
    expect(formatMoneyCompact(18_991_000, dot)).toBe("$ 19 M")
    expect(formatMoneyCompact(820_000, dot)).toBe("$ 820 k")
    expect(formatMoneyCompact(1_500_000, us)).toBe("USD 1.5 M")
    expect(formatIntCompact(13_431, dot)).toBe("13,4 k")
  })

  it("por debajo de mil, el número completo", () => {
    expect(formatIntCompact(590, dot)).toBe("590")
    expect(formatMoneyCompact(950, dot)).toBe("$ 950")
  })
})
