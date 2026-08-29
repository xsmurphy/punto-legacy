import { describe, it, expect } from "vitest"

import { defaultBlock, type PrintTemplateConfig } from "@/lib/types/print-template"
import { buildRollGrid, rollGeometry } from "../roll-grid"
import type { TicketData } from "../build-ticket-data"

/**
 * Ticket de 80mm COMPLETO, del tipo que un comercio arma de verdad: encabezado
 * centrado, reglas separadoras, fecha y número en dos columnas, filas de ítems
 * con nombre/cantidad/importe, total a la derecha y un cierre multilínea.
 *
 * Es la prueba de aceptación del cambio de 2026-08-24 (el rollo dejó de
 * descartar la geometría del canvas). Vale como regresión de TODO el camino a
 * la vez: posición, columnas, wrap por ancho de papel, crecimiento por ítems y
 * empuje de lo que viene abajo. Si esta salida cambia, cambió lo que sale
 * impreso en cada caja — que se vea en el diff es el punto.
 */
describe("rollo 80mm — ticket típico", () => {
  const MM = 3.78
  const geo = rollGeometry("receipt80", MM)
  const rowH = Math.round(geo.lineHeightPx)
  const c = (n: number) => Math.round(geo.charWidthPx * n)

  const config = {
    page_size: "receipt80",
    page_size_name: "",
    page_name: "",
    page_font_family: "Arial",
    page_font_size: "8pt",
    page_font_case: "none",
    receipt_left_margin: "7",
    mm: MM,
    data: [
      { ...defaultBlock("company_name"), top: 0, left: 0, width: c(48), height: rowH, align: "center", bold: "bold" },
      { ...defaultBlock("company_address"), top: rowH, left: 0, width: c(48), height: rowH, align: "center" },
      { ...defaultBlock("hor_line"), top: rowH * 2, left: 0, width: c(48), height: rowH },
      { ...defaultBlock("date"), top: rowH * 3, left: 0, width: c(24), height: rowH },
      { ...defaultBlock("document_number"), top: rowH * 3, left: c(24), width: c(24), height: rowH, align: "right" },
      { ...defaultBlock("hor_line"), top: rowH * 4, left: 0, width: c(48), height: rowH },
      { ...defaultBlock("item"), top: rowH * 5, left: 0, width: c(28), height: rowH, textwrap: "wrap" },
      { ...defaultBlock("item_units"), top: rowH * 5, left: c(28), width: c(6), height: rowH, align: "right" },
      { ...defaultBlock("item_total"), top: rowH * 5, left: c(34), width: c(14), height: rowH, align: "right" },
      { ...defaultBlock("hor_line"), top: rowH * 6, left: 0, width: c(48), height: rowH },
      { ...defaultBlock("total"), top: rowH * 7, left: 0, width: c(48), height: rowH, align: "right", bold: "bold" },
      {
        ...defaultBlock("custom", "Gracias por su compra, vuelva pronto a visitarnos"),
        top: rowH * 9,
        left: 0,
        width: c(48),
        height: rowH,
        align: "center",
        textwrap: "wrap",
      },
    ],
  } as unknown as PrintTemplateConfig

  const data = {
    docType: "sale",
    companyName: "Almacén Central S.A.",
    companyAddress: "Avda. Mcal. López 1234, Asunción",
    transactionId: "tx-1",
    date: "24/08/2026",
    documentNumber: "001-001-0000123",
    // País del tenant declarado explícitamente. Antes el fixture no traía ni
    // moneda ni país y el ticket igual salía en "Gs", porque ese era el
    // default escondido de `formatMoney` — o sea, el test verificaba el
    // default paraguayo sin decirlo. Ahora la etiqueta sale de que ESTE
    // comercio es paraguayo (`COUNTRY_LOCALE.PY.currency`), que es lo que el
    // test quiere afirmar; un fixture con `country: "BR"` imprimiría "R$".
    country: "PY",
    total: 23000,
    items: [
      { name: "Empanada de carne cortada a cuchillo", qty: 2, unitPrice: 8000, total: 16000 },
      { name: "Gaseosa 500ml", qty: 1, unitPrice: 7000, total: 7000 },
    ],
    payments: [],
  } as unknown as TicketData

  it("sale exactamente como se diseñó en el canvas", () => {
    const grid = buildRollGrid(config, data, geo)
    expect(grid.rows.map((r) => r.text)).toEqual([
      "              Almacén Central S.A.",
      "        Avda. Mcal. López 1234, Asunción",
      // Desde 2026-08-28 la primera y la última columna del papel son MARGEN
      // (`ROLL_MARGIN_COLS`): el contenido arranca en la columna 1 y termina
      // una antes del borde, así que las filas miden 47 y no 48.
      " ----------------------------------------------",
      " 24/08/2026                     001-001-0000123",
      " ----------------------------------------------",
      // Los importes de ítem van SIN moneda desde 2026-08-26 (decisión del
      // owner): el símbolo se declara una sola vez, en el total de abajo.
      " Empanada de carne cortada a      2      16.000",
      " cuchillo",
      " Gaseosa 500ml                    1       7.000",
      " ----------------------------------------------",
      "                                      Gs 23.000",
      "",
      "     Gracias por su compra, vuelva pronto a",
      "                   visitarnos",
    ])
  })

  it("ninguna fila supera el ancho del papel", () => {
    const grid = buildRollGrid(config, data, geo)
    expect(grid.rows.every((r) => r.text.length <= grid.columns)).toBe(true)
  })

  it("la misma plantilla en una térmica de 58mm se reparte sobre 32 columnas", () => {
    const narrow = rollGeometry("receipt80", MM, 58)
    const grid = buildRollGrid(config, data, narrow)
    expect(grid.columns).toBe(32)
    expect(grid.rows.every((r) => r.text.length <= 32)).toBe(true)
    // El total sigue pegado a la derecha, ahora del papel angosto.
    const totalRow = grid.rows.find((r) => r.text.includes("23.000"))
    expect(totalRow?.text.endsWith("Gs 23.000")).toBe(true)
  })
})
