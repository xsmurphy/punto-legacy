import { describe, it, expect } from "vitest"

import { defaultBlock, type PrintBlock, type PrintTemplateConfig } from "@/lib/types/print-template"
import { renderTemplateToHtml } from "../html-renderer"
import { ROLL_FONT_STACK, rollFontSizeFor, rollGeometry } from "../roll-grid"
import type { TicketData } from "../build-ticket-data"

/**
 * La grilla de caracteres solo dice la verdad si el papel la pinta con celdas
 * de ancho fijo. El renderer ponía la fuente de la plantilla ADELANTE de
 * `monospace` en el stack, así que una plantilla con Arial se imprimía
 * proporcional: las columnas que la grilla centró y cortó salían de anchos
 * distintos, el texto quedaba corrido y se desbordaba del papel (reporte del
 * owner 2026-08-28).
 */

const MM = 3.78

function tpl(over: Partial<PrintTemplateConfig> = {}, data: PrintBlock[] = []): PrintTemplateConfig {
  return {
    page_size: "receipt80",
    page_size_name: "Roll 80mm",
    page_name: "",
    page_font_family: "Arial",
    page_font_size: "8pt",
    page_font_case: "none",
    receipt_left_margin: "7",
    mm: MM,
    data,
    ...over,
  }
}

function ticket(): TicketData {
  return {
    docType: "sale",
    companyName: "Almacén Central",
    transactionId: "tx-1",
    date: "28/08/2026",
    total: 200000,
    thousand: "dot",
    country: "",
    items: [],
    payments: [],
  } as unknown as TicketData
}

const block = (): PrintBlock[] => [
  { ...defaultBlock("company_name"), top: 0, left: 0, width: 302, height: 12, align: "center" },
]

describe("tipografía del rollo", () => {
  it("ignora la fuente de la plantilla: el papel es monoespaciado", () => {
    const html = renderTemplateToHtml(tpl({}, block()), ticket())
    expect(html).toContain(ROLL_FONT_STACK)
    expect(html).not.toContain("'Arial'")
  })

  it("el tamaño hace que `columns` caracteres llenen el ancho del papel", () => {
    const geo = rollGeometry("receipt80", MM)
    const expected = rollFontSizeFor(80, geo.columns).toFixed(3)
    expect(renderTemplateToHtml(tpl({}, block()), ticket())).toContain(`font-size: ${expected}mm`)
  })

  it("con térmica de 58mm el tamaño se recalcula contra ESE ancho", () => {
    const geo = rollGeometry("receipt80", MM, 58)
    const expected = rollFontSizeFor(58, geo.columns).toFixed(3)
    const html = renderTemplateToHtml(tpl({}, block()), ticket(), { paperWidthMm: 58 })
    expect(html).toContain(`font-size: ${expected}mm`)
  })

  it("en HOJA sí manda la plantilla — ahí imprime el navegador", () => {
    const html = renderTemplateToHtml(
      tpl({ page_size: "a4page", page_size_name: "A4 (Vertical)" }, block()),
      ticket(),
    )
    expect(html).toContain("'Arial'")
  })
})
