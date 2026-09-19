import { describe, expect, it } from "vitest"

import {
  formattedNumberToWords,
  integerToSpanishWords,
  spellFormattedNumbers,
} from "@/lib/ai/spoken-numbers"

describe("integerToSpanishWords", () => {
  it("unidades, decenas y centenas con sus irregulares", () => {
    expect(integerToSpanishWords(0)).toBe("cero")
    expect(integerToSpanishWords(16)).toBe("dieciséis")
    expect(integerToSpanishWords(21)).toBe("veintiuno")
    expect(integerToSpanishWords(31)).toBe("treinta y uno")
    expect(integerToSpanishWords(100)).toBe("cien")
    expect(integerToSpanishWords(101)).toBe("ciento uno")
    expect(integerToSpanishWords(595)).toBe("quinientos noventa y cinco")
  })

  it("miles y millones con apócope", () => {
    expect(integerToSpanishWords(1000)).toBe("mil")
    expect(integerToSpanishWords(21_000)).toBe("veintiún mil")
    expect(integerToSpanishWords(31_000)).toBe("treinta y un mil")
    expect(integerToSpanishWords(101_000)).toBe("ciento un mil")
    expect(integerToSpanishWords(1_500_000)).toBe("un millón quinientos mil")
    expect(integerToSpanishWords(2_290_000)).toBe("dos millones doscientos noventa mil")
    expect(integerToSpanishWords(21_000_000)).toBe("veintiún millones")
    expect(integerToSpanishWords(1_000_000_000)).toBe("mil millones")
    expect(integerToSpanishWords(36_153_000)).toBe("treinta y seis millones ciento cincuenta y tres mil")
  })

  it("fuera de rango → null (queda el número como estaba)", () => {
    expect(integerToSpanishWords(1e12)).toBeNull()
    expect(integerToSpanishWords(-1)).toBeNull()
  })
})

describe("formattedNumberToWords", () => {
  it("separador de miles con punto o coma, decimales con el otro", () => {
    expect(formattedNumberToWords("1.500.000")).toBe("un millón quinientos mil")
    expect(formattedNumberToWords("1,500,000")).toBe("un millón quinientos mil")
    expect(formattedNumberToWords("1.234,56")).toBe("mil doscientos treinta y cuatro coma cincuenta y seis")
    expect(formattedNumberToWords("1,234.56")).toBe("mil doscientos treinta y cuatro coma cincuenta y seis")
    expect(formattedNumberToWords("12,5")).toBe("doce coma cinco")
    expect(formattedNumberToWords("0,05")).toBe("cero coma cero cinco")
    expect(formattedNumberToWords("-475.000")).toBe("menos cuatrocientos setenta y cinco mil")
  })
})

describe("spellFormattedNumbers", () => {
  it("el caso del owner: el monto se lee como monto", () => {
    expect(spellFormattedNumbers("Vendiste ₡ 1.500.000 este mes")).toBe(
      "Vendiste ₡ un millón quinientos mil este mes",
    )
  })

  it("variaciones negativas y porcentajes con decimal", () => {
    expect(spellFormattedNumbers("-₡ 475.000 (-20,7%)")).toBe(
      "-₡ cuatrocientos setenta y cinco mil (menos veinte coma siete%)",
    )
  })

  it("no toca fechas, rangos ni números sin separador", () => {
    expect(spellFormattedNumbers("el 18/09/2026 hubo 164 ventas")).toBe("el 18/09/2026 hubo 164 ventas")
    expect(spellFormattedNumbers("1-7 May")).toBe("1-7 May")
    expect(spellFormattedNumbers("2026")).toBe("2026")
  })
})

describe("moneda hablada", () => {
  it("el nombre sale del código ISO, en plural y sin el país", async () => {
    const { spokenCurrencyName, spellCurrencyAmounts } = await import("@/lib/ai/spoken-numbers")
    expect(spokenCurrencyName("ARS")).toBe("pesos")
    expect(spokenCurrencyName("USD")).toBe("dólares")
    expect(spokenCurrencyName("BRL")).toBe("reales")
    expect(spokenCurrencyName("CRC")).toBe("colones")
    expect(spokenCurrencyName("XXX_NO")).toBeNull()
    expect(spellCurrencyAmounts("Vendiste ₡ 1.500.000 y -₡ 475.000 (en ₡)", "₡", "colones")).toBe(
      "Vendiste 1.500.000 colones y -475.000 colones (en ₡)",
    )
    expect(spellCurrencyAmounts("Total $ 1,200.50", "$", "dólares")).toBe("Total 1,200.50 dólares")
  })

  it("de punta a punta: etiqueta + número → monto hablado", async () => {
    const { textForSpeech, speechOptionsFor } = await import("@/lib/ai/tts-chunk")
    const opts = speechOptionsFor({ currency: "₡", country: "CR" } as never)
    expect(textForSpeech("Vendiste **₡ 1.500.000** (-20,7%)", opts)).toBe(
      "Vendiste un millón quinientos mil colones (menos veinte coma siete%)",
    )
    // Sin etiqueta configurada vale la del país.
    expect(speechOptionsFor({ currency: "", country: "AR" } as never).currency).toEqual({ label: "$", spoken: "pesos" })
    // Etiqueta distinta a la del país: no se sabe qué moneda es, se lee tal cual.
    expect(speechOptionsFor({ currency: "US$", country: "CR" } as never).currency).toBeNull()
  })
})
