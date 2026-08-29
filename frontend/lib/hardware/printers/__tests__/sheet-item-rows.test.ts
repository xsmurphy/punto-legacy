import { describe, it, expect } from "vitest"

import {
  defaultBlock,
  type PrintBlock,
  type PrintTemplateConfig,
} from "@/lib/types/print-template"
import { renderTemplateToHtml } from "../html-renderer"
import { ITEM_FIELD_RESOLVERS, ITEM_LINE_TYPES } from "../blocks"
import type { TicketData, TicketItem } from "../build-ticket-data"

/**
 * Dos bugs de la factura de HOJA reportados por el owner el 2026-08-29, con
 * capturas del editor y de la vista previa lado a lado.
 *
 *   1. El pie se despegaba del resto y se iba a una segunda página. El
 *      renderer trataba `block.height` —la REGIÓN del cuerpo de la tabla que
 *      el operador dibuja, ~640px— como el alto de UNA fila, así que el
 *      segundo ítem caía 640px abajo y `pushDown` empujaba todo lo que
 *      seguía fuera de la hoja.
 *   2. Las columnas Exentas / IVA 5% / IVA 10% no existían como bloque: los
 *      `*_by_rate` son agregados de documento (se imprimen UNA vez con la
 *      suma del bucket), así que la única forma de poner algo en esas
 *      columnas era un `item_total`, que imprime el monto de todo ítem en la
 *      misma columna sin importar su tasa.
 */

const MM = 3.78

function sheet(data: PrintBlock[]): PrintTemplateConfig {
  return {
    page_size: "a4page",
    page_size_name: "A4 (Vertical)",
    page_name: "",
    page_font_family: "Arial",
    page_font_size: "8pt",
    page_font_case: "none",
    receipt_left_margin: "7",
    mm: MM,
    data,
  }
}

/** Las tres tasas del ejemplo del owner: Arroz exento, Agua 5%, Carne 10%. */
function item(over: Partial<TicketItem>): TicketItem {
  return {
    name: "Producto",
    qty: 1,
    unitPrice: 1000,
    discountAmount: 0,
    discountPercent: 0,
    total: 1000,
    categoryId: null,
    id: null,
    uid: null,
    note: null,
    taxId: null,
    taxRate: null,
    taxKind: null,
    taxNet: null,
    taxAmount: null,
    ...over,
  } as unknown as TicketItem
}

const ARROZ = item({
  name: "Arroz",
  taxId: "tax-exenta", taxRate: 0, taxKind: "exempt",
  total: 10_000, taxNet: 10_000, taxAmount: 0,
})
const AGUA = item({
  name: "Agua",
  taxId: "tax-5", taxRate: 5, taxKind: "rate",
  total: 21_000, taxNet: 20_000, taxAmount: 1_000,
})
const CARNE = item({
  name: "Carne",
  taxId: "tax-10", taxRate: 10, taxKind: "rate",
  total: 33_000, taxNet: 30_000, taxAmount: 3_000,
})

function ticket(items: TicketItem[]): TicketData {
  return {
    docType: "sale",
    companyName: "Mi Empresa S.A.",
    transactionId: "tx-1",
    date: "12-06-2026",
    documentNumber: "001-001-0000001",
    total: items.reduce((s, i) => s + i.total, 0),
    thousand: "dot",
    country: "",
    items,
    payments: [],
  } as unknown as TicketData
}

/** `top` en mm de cada div absoluto, en orden de emisión. */
function topsMm(html: string): number[] {
  return [...html.matchAll(/position:absolute;top:([\d.]+)mm/g)].map((m) => Number(m[1]))
}

describe("hoja — filas de ítem", () => {
  it("las filas avanzan por el alto de LÍNEA, no por la región dibujada", () => {
    // La columna mide 600px de alto: es la región del cuerpo de la tabla, no
    // una fila. Con el bug, el 2do ítem caía 600px (~159mm) más abajo.
    const col: PrintBlock = { ...defaultBlock("item"), top: 400, left: 20, width: 300, height: 600 }
    const html = renderTemplateToHtml(sheet([col]), ticket([ARROZ, AGUA, CARNE]))
    const tops = topsMm(html)

    expect(tops).toHaveLength(3)
    const step = tops[1] - tops[0]
    // `toFixed(2)` en el renderer: 0.01mm de redondeo por valor.
    expect(tops[2] - tops[1]).toBeCloseTo(step, 1)
    // 8pt * 25.4/72 * 1.2 ≈ 3.39mm. Lo que importa es que sea el orden de una
    // línea de texto y no el de la región (600px / 3.78 ≈ 159mm).
    expect(step).toBeGreaterThan(2)
    expect(step).toBeLessThan(6)
  })

  it("nada de lo que va debajo del grupo se empuja — el pie se queda en su sitio", () => {
    const col: PrintBlock = { ...defaultBlock("item"), top: 400, left: 20, width: 300, height: 600 }
    const pie: PrintBlock = { ...defaultBlock("total"), top: 1100, left: 20, width: 300, height: 24 }
    const tpl = sheet([col, pie])

    const conUno = topsMm(renderTemplateToHtml(tpl, ticket([ARROZ])))
    const conTres = topsMm(renderTemplateToHtml(tpl, ticket([ARROZ, AGUA, CARNE])))

    // El pie es el ÚLTIMO div en los dos casos, y está en el mismo mm.
    expect(conUno.at(-1)).toBeCloseTo(1100 / MM, 1)
    expect(conTres.at(-1)).toBeCloseTo(1100 / MM, 1)
    // Y sobre todo: idénticos entre sí — ahí vivía el bug.
    expect(conTres.at(-1)).toBe(conUno.at(-1))
  })

  it("cada celda de fila declara su line-height, así el browser no elige otro", () => {
    const col: PrintBlock = { ...defaultBlock("item"), top: 400, left: 20, width: 300, height: 600 }
    const html = renderTemplateToHtml(sheet([col]), ticket([ARROZ, AGUA]))
    expect(html).toMatch(/line-height:[\d.]+mm/)
  })
})

describe("item_total_if_rate — el monto cae en la columna de SU tasa", () => {
  const cell = (taxId: string, it: TicketItem) =>
    ITEM_FIELD_RESOLVERS.item_total_if_rate!(it, ticket([it]), {
      ...defaultBlock("item_total_if_rate"),
      text: taxId,
    })

  it("imprime el monto solo en su columna y deja las otras en blanco", () => {
    expect(cell("tax-10", CARNE)).toBe("33.000")
    expect(cell("tax-5", CARNE)).toBe("")
    expect(cell("tax-exenta", CARNE)).toBe("")

    expect(cell("tax-5", AGUA)).toBe("21.000")
    expect(cell("tax-exenta", ARROZ)).toBe("10.000")
  })

  it("no confunde una exenta con una tasa 0 — el match es por identidad", () => {
    // Fiscalmente distintas (context/38): comparar por `rate === 0` las
    // metería en la misma columna.
    const tasaCero = item({
      name: "Tasa cero",
      taxId: "tax-cero", taxRate: 0, taxKind: "rate",
      total: 7_000, taxNet: 7_000, taxAmount: 0,
    })
    expect(cell("tax-exenta", tasaCero)).toBe("")
    expect(cell("tax-cero", tasaCero)).toBe("7.000")
    expect(cell("tax-cero", ARROZ)).toBe("")
  })

  it("un bloque sin taxId guardado no imprime en ninguna columna", () => {
    expect(cell("", CARNE)).toBe("")
  })

  it("es un bloque de LÍNEA — se repite por ítem, a diferencia de los *_by_rate", () => {
    expect(ITEM_LINE_TYPES.has("item_total_if_rate")).toBe(true)
    expect(ITEM_LINE_TYPES.has("item_total_by_rate")).toBe(false)
  })

  it("las tres columnas juntas suman el total de la venta", () => {
    const items = [ARROZ, AGUA, CARNE]
    const parse = (s: string | null) => (s ? Number(s.replace(/\./g, "")) : 0)
    const suma = items.reduce(
      (acc, it) =>
        acc + ["tax-exenta", "tax-5", "tax-10"].reduce((a, id) => a + parse(cell(id, it)), 0),
      0,
    )
    expect(suma).toBe(64_000)
  })
})
