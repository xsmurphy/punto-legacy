import { readFileSync } from "node:fs"
import path from "node:path"

import { describe, expect, it } from "vitest"

import {
  CONTACT_ID_TYPES,
  contactIdTypeLabel,
  contactIdTypesFor,
  hasContactIdTypes,
  personalIdFieldCopy,
  taxIdFieldCopy,
} from "@/lib/contact-id-types"
import {
  COUNTRY_LOCALE,
  UNKNOWN_PERSONAL_ID_LABEL,
  UNKNOWN_TAX_ID_LABEL,
  resolvePersonalIdLabel,
  resolveTaxIdLabel,
} from "@/lib/tenant-locale"

/**
 * El catálogo de identificadores por país (pedido del owner 2026-08-31: «en
 * otros países no se llama RUC... en Argentina se usa DNI»).
 *
 * Lo que estos tests protegen, en orden de importancia:
 *  1. Paraguay no cambia: los 7 códigos de la Tabla 3 con sus campos y flags.
 *  2. Ningún país ve el nombre paraguayo de un documento que no usa.
 *  3. El string VACÍO del bootstrap NO cuenta como valor configurado — es la
 *     trampa que ya había costado el símbolo de moneda.
 */

describe("Tabla 3 SET — Paraguay no cambia de comportamiento", () => {
  it("conserva los 7 códigos con su campo y su flag de facturación", () => {
    expect(
      CONTACT_ID_TYPES.map((t) => [t.code, t.numberField, t.noEinvoice]),
    ).toEqual([
      [11, "tin", false],
      [12, "ci", false],
      [13, "ci", false],
      [14, "ci", true],
      [15, "ci", false],
      [16, "ci", false],
      [17, "ci", true],
    ])
  })

  it("un tenant PY tiene selector con los 7 tipos", () => {
    const py = { country: "PY" }
    expect(hasContactIdTypes(py)).toBe(true)
    expect(contactIdTypesFor(py)).toHaveLength(7)
  })

  it("el label del campo personal sigue al tipo elegido", () => {
    const py = { country: "PY" }
    expect(personalIdFieldCopy(py, 13).label).toBe("Pasaporte")
    expect(personalIdFieldCopy(py, 16).label).toBe("Carnet diplomático")
    expect(personalIdFieldCopy(py, 12).label).toBe("Cédula de identidad")
  })

  it("contactIdTypeLabel devuelve el guión largo para un código desconocido", () => {
    expect(contactIdTypeLabel(11)).toBe("RUC")
    expect(contactIdTypeLabel(99)).toBe("—")
    expect(contactIdTypeLabel(null)).toBe("—")
  })
})

describe("Otros países — nombres correctos, sin taxonomía inventada", () => {
  // (país, documento fiscal, documento personal) — los que pidió el brief.
  const CASES: Array<[string, string, string]> = [
    ["AR", "CUIT", "DNI"],
    ["UY", "RUT", "Cédula de identidad"],
    ["CL", "RUT", "RUT"],
    ["BR", "CNPJ", "CPF"],
    ["BO", "NIT", "Cédula de identidad"],
  ]

  it.each(CASES)("%s rotula sus documentos con su propio nombre", (country, tax, personal) => {
    expect(taxIdFieldCopy({ country }).label).toBe(tax)
    expect(personalIdFieldCopy({ country }).label).toBe(personal)
  })

  it.each(CASES)("%s no ofrece selector de tipo de documento", (country) => {
    // Sin columna donde guardar códigos que no sean de la SET, un selector
    // sería un control que promete un dato que no se persiste.
    expect(contactIdTypesFor({ country })).toEqual([])
    expect(hasContactIdTypes({ country })).toBe(false)
  })

  it("los países que NO usan cédula ni RUC no los heredan de Paraguay", () => {
    // Ojo: UY/BO/EC/VE sí dicen "Cédula de identidad" y PE/EC sí dicen "RUC" —
    // eso es correcto, no una fuga del default paraguayo. La regresión que
    // este test busca es el país que rotula con un nombre que no es el suyo.
    for (const country of ["AR", "BR", "CL", "MX", "CO", "ES", "US"]) {
      expect(personalIdFieldCopy({ country }).label, country).not.toBe("Cédula de identidad")
      expect(taxIdFieldCopy({ country }).label, country).not.toBe("RUC")
    }
  })

  it("cada país rotula con SU fila del catálogo, sin fuga entre países", () => {
    for (const [country, row] of Object.entries(COUNTRY_LOCALE)) {
      expect(taxIdFieldCopy({ country }).label, country).toBe(row.tinName)
      // En PY el label del campo personal lo decide el tipo elegido; sin tipo
      // cae al del país, igual que el resto.
      expect(personalIdFieldCopy({ country }).label, country).toBe(row.personalIdName)
    }
  })

  it("no inventa placeholders para países cuyo formato no conocemos", () => {
    expect(taxIdFieldCopy({ country: "AR" }).placeholder).toBeUndefined()
    expect(personalIdFieldCopy({ country: "BR" }).placeholder).toBeUndefined()
    expect(taxIdFieldCopy({ country: "PY" }).placeholder).toBe("Ej: 80012345-6")
  })
})

describe("Fallback genérico y la trampa del string vacío", () => {
  it("un país desconocido o ausente cae a las etiquetas genéricas", () => {
    for (const config of [{ country: "ZZ" }, { country: "" }, {}, null, undefined]) {
      expect(taxIdFieldCopy(config).label).toBe(UNKNOWN_TAX_ID_LABEL)
      expect(personalIdFieldCopy(config).label).toBe(UNKNOWN_PERSONAL_ID_LABEL)
    }
  })

  it("el formulario nunca se queda sin label", () => {
    expect(UNKNOWN_TAX_ID_LABEL).not.toBe("")
    expect(UNKNOWN_PERSONAL_ID_LABEL).not.toBe("")
  })

  it("`tinName` vacío del bootstrap NO pisa el default del país", () => {
    // El BFF normaliza el campo ausente a "" (no a null): si `present()` no lo
    // tratara como ausente, un comercio argentino vería un label vacío.
    expect(resolveTaxIdLabel({ country: "AR", tinName: "" })).toBe("CUIT")
    expect(resolveTaxIdLabel({ country: "AR", tinName: "   " })).toBe("CUIT")
  })

  it("`tinName` configurado por el tenant gana sobre el del país", () => {
    expect(resolveTaxIdLabel({ country: "PY", tinName: "R.U.C." })).toBe("R.U.C.")
  })

  it("el documento personal se deriva del país, sin ajuste que lo pise", () => {
    expect(resolvePersonalIdLabel({ country: "AR", tinName: "CUIT" })).toBe("DNI")
  })
})

describe("Catálogo completo", () => {
  it("todo país del catálogo de locale tiene los dos nombres, no vacíos", () => {
    for (const [iso, row] of Object.entries(COUNTRY_LOCALE)) {
      expect(row.tinName.trim(), iso).not.toBe("")
      expect(row.personalIdName.trim(), iso).not.toBe("")
    }
  })
})

describe("Espejo front ↔ backend", () => {
  /**
   * GUARD — `COUNTRY_LOCALE` (front) y `CountryDefaults::ID_LABELS` (PHP)
   * tienen que decir lo mismo.
   *
   * Por qué con un guard y no con un comentario: son dos tablas en dos
   * lenguajes, y quien agregue un país va a tocar la que tenga delante. La
   * divergencia no rompe nada al build — se ve como un campo que se llama
   * distinto según por dónde se lo mire (el front rotula el formulario, el
   * backend siembra la etiqueta del tenant en el alta), que es justo la clase
   * de bug que nadie reporta. Mismo enfoque que el guard de literales
   * paraguayos: leer el archivo del otro lado y comparar.
   */
  const PHP_PATH = path.resolve(
    import.meta.dirname,
    "..",
    "..",
    "..",
    "api/lib/Support/CountryDefaults.php",
  )

  /** Parsea las filas `'XX' => ['tax' => 'A', 'personal' => 'B'],` del PHP. */
  function phpIdLabels(): Record<string, { tax: string; personal: string }> {
    const src = readFileSync(PHP_PATH, "utf8")
    const table = src.split("private const ID_LABELS = [")[1]?.split("];")[0] ?? ""
    const out: Record<string, { tax: string; personal: string }> = {}
    const row = /'([A-Z]{2})'\s*=>\s*\['tax'\s*=>\s*'([^']+)',\s*'personal'\s*=>\s*'([^']+)'\]/g
    for (const m of table.matchAll(row)) out[m[1]] = { tax: m[2], personal: m[3] }
    return out
  }

  it("el PHP declara la tabla en el formato que este guard sabe leer", () => {
    // Si el parseo devuelve vacío el resto de los asserts pasarían solos.
    expect(Object.keys(phpIdLabels()).length).toBeGreaterThan(0)
  })

  it("los dos catálogos cubren exactamente los mismos países", () => {
    expect(Object.keys(phpIdLabels()).sort()).toEqual(Object.keys(COUNTRY_LOCALE).sort())
  })

  it("cada país dice lo mismo de sus dos documentos en ambos lados", () => {
    const php = phpIdLabels()
    for (const [iso, row] of Object.entries(COUNTRY_LOCALE)) {
      expect(php[iso]?.tax, `${iso} — documento fiscal`).toBe(row.tinName)
      expect(php[iso]?.personal, `${iso} — documento personal`).toBe(row.personalIdName)
    }
  })
})
