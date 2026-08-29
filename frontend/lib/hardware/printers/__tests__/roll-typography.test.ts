import { describe, it, expect } from "vitest"

import { defaultBlock, type PrintBlock, type PrintTemplateConfig } from "@/lib/types/print-template"
import { renderTemplateToHtml } from "../html-renderer"
import {
  buildRollGrid,
  ROLL_FONT_STACK,
  rollFontSizeFor,
  rollGeometry,
  snapBlockToRollRows,
} from "../roll-grid"
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

describe("snapBlockToRollRows — el canvas y el papel cuentan las mismas filas", () => {
  const geo = rollGeometry("receipt76", MM)
  const row = geo.lineHeightPx

  it("lleva `top` a una fila exacta y `height` a filas enteras", () => {
    // El caso real: una plantilla diseñada con snap de 1mm cae ENTRE filas.
    // Relativo a `row` para que el test no dependa de las columnas del
    // dispositivo (76mm pasó de proyectarse a 80 a ser la TM-U220 real).
    const snapped = snapBlockToRollRows({ top: row * 3.17, height: row * 2.05 }, geo)
    expect(snapped.top / row).toBe(3)
    expect(snapped.height / row).toBe(2)
  })

  it("un bloque más bajo que una fila ocupa UNA, nunca cero", () => {
    expect(snapBlockToRollRows({ top: 0, height: 1 }, geo).height).toBe(row)
    expect(snapBlockToRollRows({ top: 0, height: 0 }, geo).height).toBe(row)
  })

  it("dos bloques pegados en el canvas NO dejan un renglón en blanco al imprimir", () => {
    const a = snapBlockToRollRows({ ...defaultBlock("custom", "LINEA UNO"), left: 0, width: 287, top: 0, height: 1 }, geo)
    const b = snapBlockToRollRows({ ...defaultBlock("custom", "LINEA DOS"), left: 0, width: 287, top: a.top + a.height, height: 1 }, geo)
    const grid = buildRollGrid(tpl({ page_size: "receipt76", page_size_name: "Roll 76mm" }, [a, b]), ticket(), geo)
    const texts = grid.rows.map((r) => r.text.trim())
    expect(texts[0]).toBe("LINEA UNO")
    expect(texts[1]).toBe("LINEA DOS")
  })

  it("sin snap, esos mismos bloques salían separados (regresión que se está fijando)", () => {
    // 2 filas de alto para UNA línea de texto: el segundo renglón queda en
    // blanco. Relativo a `row`, no en px fijos (ver el test de arriba).
    const a = { ...defaultBlock("custom", "LINEA UNO"), left: 0, width: 287, top: 0, height: row * 2 }
    const b = { ...defaultBlock("custom", "LINEA DOS"), left: 0, width: 287, top: row * 2, height: row * 2 }
    const texts = buildRollGrid(tpl({ page_size: "receipt76", page_size_name: "Roll 76mm" }, [a, b]), ticket(), geo)
      .rows.map((r) => r.text.trim())
    expect(texts[1]).toBe("")
  })
})
