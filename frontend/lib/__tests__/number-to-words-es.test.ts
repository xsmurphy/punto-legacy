import { describe, expect, it } from "vitest"

import { amountToWordsEs } from "../number-to-words-es"

describe("amountToWordsEs", () => {
  it("cubre los quiebres del español que se escriben distinto", () => {
    const casos: Array<[number, string]> = [
      [0, "Cero"],
      [1, "Uno"],
      [16, "Dieciséis"],
      [21, "Veintiuno"],
      [31, "Treinta y uno"],
      // 100 exacto es "cien"; con resto pasa a "ciento".
      [100, "Cien"],
      [105, "Ciento cinco"],
      [500, "Quinientos"],
      [700, "Setecientos"],
      [900, "Novecientos"],
      // "mil", nunca "un mil".
      [1000, "Mil"],
      [2000, "Dos mil"],
      // Apócope antes de mil/millón: "veintiún", no "veintiuno".
      [21000, "Veintiún mil"],
      [100000, "Cien mil"],
      [1000000, "Un millón"],
      [2000000, "Dos millones"],
      [1000001, "Un millón uno"],
      [20000, "Veinte mil"],
      [999999, "Novecientos noventa y nueve mil novecientos noventa y nueve"],
    ]
    for (const [n, esperado] of casos) {
      expect(amountToWordsEs(n, 0), `${n}`).toBe(esperado)
    }
  })

  it("en moneda sin decimales redondea y no inventa centavos", () => {
    expect(amountToWordsEs(1500.4, 0)).toBe("Mil quinientos")
    expect(amountToWordsEs(1500.6, 0)).toBe("Mil quinientos uno")
  })

  it("en moneda con decimales usa la forma contable NN/100", () => {
    expect(amountToWordsEs(1500.5, 2)).toBe("Mil quinientos con 50/100")
    expect(amountToWordsEs(1500.05, 2)).toBe("Mil quinientos con 05/100")
    // Sin centavos no cuelga el "con 00/100".
    expect(amountToWordsEs(1500, 2)).toBe("Mil quinientos")
  })

  it("devuelve null en vez de una frase inventada cuando no puede expresarlo", () => {
    expect(amountToWordsEs(-1, 0)).toBeNull()
    expect(amountToWordsEs(Number.NaN, 0)).toBeNull()
    expect(amountToWordsEs(1e12, 0)).toBeNull()
  })
})
