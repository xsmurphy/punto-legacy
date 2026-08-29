import { describe, it, expect } from "vitest"

import { defaultBlock, type PrintBlock, type PrintTemplateConfig } from "@/lib/types/print-template"
import {
  formatQty,
  resolvePaymentLines,
  resolveSimpleBlock,
  resolveSingleBlockPreview,
  withBlockLabel,
} from "../blocks"
import { renderTemplateToHtml } from "../html-renderer"
import { buildRollGrid, distributeRow, rollGeometry, ROLL_MARGIN_COLS } from "../roll-grid"
import type { TicketData, TicketItem } from "../build-ticket-data"

/**
 * Guard del pedido del owner (2026-08-26, foto de un ticket impreso):
 *
 *   1. El ticket imprime "Fecha: 12-03-2026", no "12-03-2026" pelado — y el
 *      título sale de la PLANTILLA (`block.label`), no de una tabla en el
 *      renderer. Esa distinción es la regla del proyecto (context/20): el
 *      mismo bloque `date` quiere "Fecha:" en la factura y nada en la comanda.
 *   2. La cantidad va sin la `x` y con 2 decimales como máximo: "1,5 Azúcar
 *      por kilo" son 1,5 kilos, y en 57 mm cada carácter cuenta.
 *
 * Los dos se verifican en las TRES superficies (valor compartido, ESC/POS vía
 * grilla, HTML) porque la historia del módulo es justamente tres switches que
 * divergían en silencio.
 */

const MM = 3.78

function tpl(data: PrintBlock[], over: Partial<PrintTemplateConfig> = {}): PrintTemplateConfig {
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

/**
 * Plantilla de HOJA. El renderer HTML solo pinta bloque por bloque
 * (`renderBlockHtml`/`renderItemTable`) en hoja: en rollo delega en la MISMA
 * grilla de caracteres que el ESC/POS. Para cubrir las dos rutas del HTML hay
 * que probar con hoja, si no se testea dos veces la grilla.
 */
function sheet(data: PrintBlock[]): PrintTemplateConfig {
  return tpl(data, { page_size: "a4page", page_size_name: "A4 (Vertical)" })
}

function item(over: Partial<TicketItem> = {}): TicketItem {
  return {
    name: "Azúcar por kilo",
    qty: 1.5,
    unitPrice: 8000,
    discountAmount: 0,
    discountPercent: 0,
    total: 12000,
    categoryId: null,
    id: null,
    uid: null,
    note: null,
  } as unknown as TicketItem
}

function ticket(over: Partial<TicketData> = {}): TicketData {
  return {
    docType: "sale",
    companyName: "Almacén Central",
    transactionId: "tx-1",
    date: "12-03-2026",
    documentNumber: "001-001-1233454",
    total: 12000,
    // `thousand: "dot"` = separador decimal coma, que es el caso del owner.
    thousand: "dot",
    country: "",
    items: [item()],
    payments: [],
    ...over,
  } as unknown as TicketData
}

const rowsOf = (t: PrintTemplateConfig, d: TicketData) =>
  buildRollGrid(t, d, rollGeometry(t.page_size, MM)).rows.map((r) => r.text)

describe("withBlockLabel — título declarado por la plantilla", () => {
  it("antepone el título al valor con un solo espacio", () => {
    expect(withBlockLabel({ ...defaultBlock("date"), label: "Fecha:" }, "12-03-2026")).toBe(
      "Fecha: 12-03-2026",
    )
  })

  it("sin título, el valor sale igual que antes de la feature", () => {
    expect(withBlockLabel({ ...defaultBlock("date"), label: "" }, "12-03-2026")).toBe("12-03-2026")
    // Plantillas guardadas antes de 2026-08-26 no traen la clave.
    const legacy = { ...defaultBlock("date") } as PrintBlock
    delete (legacy as { label?: string }).label
    expect(withBlockLabel(legacy, "12-03-2026")).toBe("12-03-2026")
  })

  it("un bloque SIN valor no imprime su título suelto", () => {
    const block = { ...defaultBlock("date"), label: "Fecha:" }
    expect(withBlockLabel(block, null)).toBeNull()
    expect(withBlockLabel(block, "")).toBeNull()
  })

  it("no impone puntuación: la escribe el operador", () => {
    expect(withBlockLabel({ ...defaultBlock("date"), label: "FECHA" }, "12-03-2026")).toBe(
      "FECHA 12-03-2026",
    )
  })
})

describe("resolveSimpleBlock — un solo lugar para los tres renderers", () => {
  it("resuelve el valor real y le pone el título", () => {
    const data = ticket()
    expect(resolveSimpleBlock({ ...defaultBlock("date"), label: "Fecha:" }, data)).toBe(
      "Fecha: 12-03-2026",
    )
    expect(
      resolveSimpleBlock({ ...defaultBlock("document_number"), label: "Fact. Nro.:" }, data),
    ).toBe("Fact. Nro.: 001-001-1233454")
    expect(resolveSimpleBlock({ ...defaultBlock("sale_type"), label: "Condición:" }, data)).toBe(
      "Condición: Contado",
    )
  })

  it("un tipo sin resolver no inventa una línea con el título", () => {
    expect(
      resolveSimpleBlock({ ...defaultBlock("hor_line"), label: "Fecha:" }, ticket()),
    ).toBeNull()
  })
})

describe("el título llega a las dos superficies de impresión", () => {
  const blocks = [
    { ...defaultBlock("date"), label: "Fecha:", top: 0, left: 0, width: 302, height: 12 },
  ]

  it("ESC/POS (grilla del rollo)", () => {
    expect(rowsOf(tpl(blocks), ticket()).join("\n")).toContain("Fecha: 12-03-2026")
  })

  it("HTML de hoja (fallback del navegador)", () => {
    expect(renderTemplateToHtml(sheet(blocks), ticket())).toContain("Fecha: 12-03-2026")
  })
})

describe("formatQty — cantidad sin `x` y con 2 decimales máximo", () => {
  const data = ticket()

  it("usa el separador decimal del tenant", () => {
    expect(formatQty(1.5, data)).toBe("1,5")
    expect(formatQty(1.5, { thousand: "comma", country: "" })).toBe("1.5")
  })

  it("no rellena con ceros: 1 kilo es '1', no '1,00'", () => {
    expect(formatQty(1, data)).toBe("1")
  })

  it("corta en 2 decimales", () => {
    expect(formatQty(1.239, data)).toBe("1,24")
  })
})

describe("la tabla de ítems imprime la cantidad sin la `x`", () => {
  const blocks = [
    { ...defaultBlock("item_receipt"), top: 0, left: 0, width: 302, height: 36 },
  ]

  it("ESC/POS (grilla del rollo)", () => {
    const text = rowsOf(tpl(blocks), ticket()).join("\n")
    expect(text).toContain("1,5")
    expect(text).not.toContain("1,5x")
    expect(text).not.toContain("1.5x")
  })

  it("HTML de hoja (fallback del navegador)", () => {
    const html = renderTemplateToHtml(sheet(blocks), ticket())
    expect(html).toContain("1,5")
    expect(html).not.toContain("1.5</td>")
  })
})

describe("distributeRow — la fila de ítems usa todo el ancho del papel", () => {
  it("cantidad a la izquierda y total contra el borde derecho (48 columnas)", () => {
    const row = distributeRow(["2", "12.000", "24.000"], 48)
    expect(row).toHaveLength(48)
    expect(row.startsWith("2")).toBe(true)
    expect(row.endsWith("24.000")).toBe(true)
    // El unitario queda en el medio, separado de los dos extremos.
    expect(row).toMatch(/^2 {2,}12\.000 {2,}24\.000$/)
  })

  it("mismo reparto en 32 columnas (papel de 57mm)", () => {
    const row = distributeRow(["2", "12.000", "24.000"], 32)
    expect(row).toHaveLength(32)
    expect(row).toMatch(/^2 {2,}12\.000 {2,}24\.000$/)
  })

  it("la columna del medio alinea entre filas aunque el texto mida distinto", () => {
    const a = distributeRow(["2", "12.000", "24.000"], 48)
    const b = distributeRow(["10", "1.500", "15.000"], 48)
    // Centradas en la misma franja: los centros caen a menos de un carácter.
    const centerOf = (row: string, cell: string) => row.indexOf(cell) + cell.length / 2
    expect(Math.abs(centerOf(a, "12.000") - centerOf(b, "1.500"))).toBeLessThanOrEqual(1)
  })

  it("con dos celdas, una a cada borde", () => {
    const row = distributeRow(["2", "24.000"], 20)
    expect(row).toBe("2" + " ".repeat(13) + "24.000")
  })

  it("una sola celda no se estira", () => {
    expect(distributeRow(["2"], 32)).toBe("2")
  })

  it("cuando no entra, cede la separación y deja que wrapee — nunca recorta un importe", () => {
    const row = distributeRow(["1,25", "1.250.000", "1.562.500"], 20)
    expect(row).toBe("1,25 1.250.000 1.562.500")
    expect(row).toContain("1.562.500")
  })
})

describe("la fila de ítems repartida llega al rollo", () => {
  it("el total queda contra el borde derecho del bloque", () => {
    const g = rollGeometry("receipt80", MM)
    const blocks = [
      {
        ...defaultBlock("item_receipt"),
        top: 0,
        left: 0,
        width: Math.round(g.canvasWidthPx),
        height: 36,
      },
    ]
    const rows = rowsOf(tpl(blocks), ticket())
    const row = rows.find((r) => r.includes("12.000"))
    expect(row).toBeDefined()
    expect(row!.trimEnd().endsWith("12.000")).toBe(true)
    expect(row!.trimStart().startsWith("1,5")).toBe(true)
  })
})

describe("el renderer HTML dejó de decidir por su cuenta", () => {
  it("`total` respeta la negrita de la plantilla en vez de forzarla", () => {
    const normal = renderTemplateToHtml(
      sheet([{ ...defaultBlock("total"), bold: "normal", top: 0, left: 0, width: 300, height: 12 }]),
      ticket(),
    )
    expect(normal).not.toContain("font-weight:bold")
  })

  it("`total` y `company_name` aceptan título como cualquier otro bloque", () => {
    const html = renderTemplateToHtml(
      sheet([
        { ...defaultBlock("total"), label: "TOTAL A PAGAR:", top: 0, left: 0, width: 300, height: 12 },
      ]),
      ticket(),
    )
    expect(html).toContain("TOTAL A PAGAR:")
  })
})

describe("la moneda se declara UNA vez, en el total (owner 2026-08-26)", () => {
  // Moneda neutra a propósito: el guard de `no-hardcoded-paraguay` es la regla
  // (nada de "Gs" en fixtures), y lo que se prueba acá es DÓNDE aparece la
  // etiqueta del tenant, no cuál es.
  const data = ticket({ currency: "$", subtotal: 12000, taxTotal: 1091, discount: 500 })

  it("el total la lleva", () => {
    expect(resolveSimpleBlock(defaultBlock("total"), data)).toBe("$ 12.000")
  })

  it("los importes de ítem NO la llevan", () => {
    for (const type of ["item_uni_price", "item_price", "item_total", "item_subtotal"] as const) {
      const printed = resolveSingleBlockPreview(defaultBlock(type), data)
      expect(printed).not.toContain("$")
      expect(printed).toMatch(/^[\d.,]+$/)
    }
  })

  it("subtotal, descuento e IVA tampoco", () => {
    expect(resolveSimpleBlock(defaultBlock("subtotal"), data)).toBe("12.000")
    expect(resolveSimpleBlock(defaultBlock("tax_total"), data)).toBe("1.091")
    expect(resolveSimpleBlock(defaultBlock("discount"), data)).toBe("500")
  })

  it("la tabla de ítems del rollo sale sin moneda", () => {
    const g = rollGeometry("receipt80", MM)
    const rows = rowsOf(
      tpl([
        {
          ...defaultBlock("item_receipt"),
          top: 0,
          left: 0,
          width: Math.round(g.canvasWidthPx),
          height: 36,
        },
      ]),
      data,
    )
    expect(rows.join("\n")).not.toContain("$")
  })

  it("sigue usando los separadores del tenant, no un formato fijo", () => {
    const comma = ticket({ currency: "US$", thousand: "comma", subtotal: 12000 })
    expect(resolveSimpleBlock(defaultBlock("subtotal"), comma)).toBe("12,000")
  })
})

describe("la fila repartida SOBREVIVE al wrap de la grilla", () => {
  it("la lista de ítems llega al papel con sus columnas, no amontonada", () => {
    const g = rollGeometry("receipt80", MM)
    const rows = rowsOf(
      tpl([
        {
          ...defaultBlock("item_receipt"),
          top: 0,
          left: 0,
          width: Math.round(g.canvasWidthPx),
          height: 36,
        },
      ]),
      ticket(),
    )
    const row = rows.find((r) => r.includes("12.000"))!
    // Si `wrapToWidth` volviera a re-unir por palabras, esto sería
    // "1,5 8.000 12.000" con un espacio entre cada campo.
    expect(row).toMatch(/1,5 {2,}8\.000 {2,}12\.000/)
  })
})

describe("payment_methods — el título encabeza la lista", () => {
  const data = ticket({
    payments: [
      { method: "Efectivo", amount: 200000 },
      { method: "T. de Crédito", amount: 100000 },
    ],
  })

  it("el título va en su propia línea y cada pago en la suya", () => {
    expect(
      resolvePaymentLines({ ...defaultBlock("payment_methods"), label: "Formas de pago:" }, data),
    ).toEqual(["Formas de pago:", "Efectivo: 200.000", "T. de Crédito: 100.000"])
  })

  it("sin título, solo los pagos", () => {
    expect(resolvePaymentLines(defaultBlock("payment_methods"), data)).toEqual([
      "Efectivo: 200.000",
      "T. de Crédito: 100.000",
    ])
  })
})

describe("margen del papel", () => {
  it("el contenido no toca el borde: una columna en blanco de cada lado", () => {
    const g = rollGeometry("receipt80", MM)
    const rows = rowsOf(
      tpl([
        {
          ...defaultBlock("custom", "X".repeat(80)),
          top: 0,
          left: 0,
          width: Math.round(g.canvasWidthPx),
          height: 12,
          textwrap: "wrap",
        },
      ]),
      ticket(),
    )
    expect(rows[0].startsWith(" ".repeat(ROLL_MARGIN_COLS))).toBe(true)
    expect(rows[0].length).toBeLessThanOrEqual(g.columns - ROLL_MARGIN_COLS)
  })
})

describe("document_number — el título por defecto sigue al TIPO de documento", () => {
  const base = ticket({ documentNumber: "001-001-0000123" })

  it("venta = Factura, orden = Orden, recibo = Recibo", () => {
    const block = defaultBlock("document_number")
    expect(resolveSimpleBlock(block, { ...base, docType: "sale" })).toBe(
      "Factura Nro.: 001-001-0000123",
    )
    expect(resolveSimpleBlock(block, { ...base, docType: "order" })).toBe(
      "Orden Nro.: 001-001-0000123",
    )
    expect(resolveSimpleBlock(block, { ...base, docType: "receipt" })).toBe(
      "Recibo Nro.: 001-001-0000123",
    )
  })

  it("el título escrito por el operador SIEMPRE gana", () => {
    expect(
      resolveSimpleBlock({ ...defaultBlock("document_number"), label: "Fact. Nro.:" }, {
        ...base,
        docType: "order",
      }),
    ).toBe("Fact. Nro.: 001-001-0000123")
  })

  it("un docType desconocido cae a un título neutro, nunca a ninguno", () => {
    expect(resolveSimpleBlock(defaultBlock("document_number"), { ...base, docType: "algoRaro" })).toBe(
      "Nro.: 001-001-0000123",
    )
  })
})

describe("bloques de orden — sin gate por docType (context/20)", () => {
  it("espacio, mesa y número imprimen si el dato EXISTE, en cualquier documento", () => {
    const data = ticket({ docType: "sale", ticketNo: "123", orderDestination: "Mesa 10" })
    expect(resolveSimpleBlock({ ...defaultBlock("order_number"), label: "Orden Nro.:" }, data)).toBe(
      "Orden Nro.: 123",
    )
    expect(
      resolveSimpleBlock({ ...defaultBlock("order_destination"), label: "Espacio:" }, data),
    ).toBe("Espacio: Mesa 10")
    expect(resolveSimpleBlock(defaultBlock("table_number"), data)).toBe("Mesa 10")
  })

  it("sin dato, el bloque sale en blanco solo — sin título huérfano", () => {
    const data = ticket({ docType: "order" })
    expect(resolveSimpleBlock({ ...defaultBlock("order_number"), label: "Orden Nro.:" }, data)).toBeNull()
  })
})
