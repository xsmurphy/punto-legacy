import { describe, expect, it } from "vitest"

import {
  buildFiscalTsv,
  fiscalFileName,
  type FiscalRow,
} from "@/lib/fiscal/legacy-export"
import type { TenantLocaleConfig } from "@/lib/tenant-locale"

/**
 * Guard del formato LEGACY de los dos archivos fiscales PY.
 *
 * Lo que se protege no es "que el export ande": es que el archivo siga siendo
 * byte a byte el que el contador viene presentando (TSV con TAB y CRLF, montos
 * como texto en RG90 y enteros en Libro Ventas, nombre `RG90-dd-mm-YYYY.xls`).
 * Una "mejora" bienintencionada acá —separadores en el Libro Ventas, LF en vez
 * de CRLF, un XLSX real— le rompe la planilla a alguien que ya la presentó así
 * durante años, y no se nota hasta que el archivo está en manos de la SET.
 */

const PY: TenantLocaleConfig = { country: "PY", thousand: "dot", decimal: "no" }

const RG90_ROW: FiscalRow = {
  "CODIGO TIPO DE REGISTRO": "1",
  "CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR": 11,
  "NUMERO DE IDENTIFICACION DEL COMPRADOR": "80012345",
  "NOMBRE O RAZON SOCIAL DEL COMPRADOR": "Ferretería & Cía",
  "CODIGO TIPO DE COMPROBANTE": 109,
  "MONTO TOTAL DEL COMPROBANTE": 1234567,
}

describe("buildFiscalTsv", () => {
  it("separa celdas con TAB y filas con CRLF, con los encabezados en la primera fila", () => {
    const tsv = buildFiscalTsv([RG90_ROW], "rg90", PY)
    const lines = tsv.split("\r\n")

    expect(tsv.endsWith("\r\n")).toBe(true)
    expect(tsv).not.toContain("\n\n")
    expect(lines[0].split("\t")[0]).toBe("CODIGO TIPO DE REGISTRO")
    expect(lines[1].split("\t")[3]).toBe("Ferretería & Cía")
    // Sin \n suelto: un LF pelado en vez de CRLF es el error silencioso que
    // hace que algunas planillas vean una sola fila gigante.
    expect(tsv.replace(/\r\n/g, "")).not.toContain("\n")
  })

  it("RG90: los montos salen como TEXTO con el separador de miles del tenant", () => {
    const cells = buildFiscalTsv([RG90_ROW], "rg90", PY).split("\r\n")[1].split("\t")

    expect(cells[5]).toBe("1.234.567")
  })

  it("RG90: los CÓDIGOS de la SET no llevan separador de miles", () => {
    const row: FiscalRow = { ...RG90_ROW, "CODIGO TIPO DE COMPROBANTE": 1090 }
    const cells = buildFiscalTsv([row], "rg90", PY).split("\r\n")[1].split("\t")

    // Es la razón por la que el escritor decide por NOMBRE de columna y no por
    // `typeof value === "number"`.
    expect(cells[4]).toBe("1090")
    expect(cells[1]).toBe("11")
  })

  it("Libro Ventas: los montos salen como enteros redondeados, sin separadores", () => {
    const row: FiscalRow = {
      "FECHA DE EMISION": "01/09/2026",
      "GRAV. 10%": 909090.9,
      TOTAL: 1000000.4,
      EXENTO: 0,
    }
    const cells = buildFiscalTsv([row], "libro-ventas", PY).split("\r\n")[1].split("\t")

    expect(cells[1]).toBe("909091")
    // TOTAL redondeado: el legacy lo dejaba crudo (único monto sin `round()`),
    // ese descuido no se copia.
    expect(cells[2]).toBe("1000000")
    expect(cells[3]).toBe("0")
  })

  it("colapsa TABs y saltos dentro de una celda para no correr las columnas", () => {
    const row: FiscalRow = { ...RG90_ROW, "NOMBRE O RAZON SOCIAL DEL COMPRADOR": "Casa\tCentral\nS.A." }
    const cells = buildFiscalTsv([row], "rg90", PY).split("\r\n")[1].split("\t")

    expect(cells).toHaveLength(6)
    expect(cells[3]).toBe("Casa Central S.A.")
  })

  it("sin filas devuelve string vacío (no un archivo con solo encabezados)", () => {
    expect(buildFiscalTsv([], "rg90", PY)).toBe("")
  })
})

describe("fiscalFileName", () => {
  it("usa el nombre del legacy con la fecha de HOY, no la del rango", () => {
    const day = new Date(2026, 8, 11)

    expect(fiscalFileName("rg90", day)).toBe("RG90-11-09-2026.xls")
    expect(fiscalFileName("libro-ventas", day)).toBe("VENTAS-11-09-2026.xls")
  })

  it("padea día y mes a dos dígitos", () => {
    expect(fiscalFileName("rg90", new Date(2026, 0, 5))).toBe("RG90-05-01-2026.xls")
  })
})
