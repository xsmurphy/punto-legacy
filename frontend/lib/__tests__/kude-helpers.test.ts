import { describe, expect, it } from "vitest"

import { consultationUrl, groupCdc } from "@/lib/einvoice/kude"

/**
 * Helpers puros del KuDE. Se testean estos y no el layout de los bloques
 * porque son los que pueden imprimir un CÓDIGO equivocado en un documento
 * fiscal: el resto es tipografía.
 */

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
