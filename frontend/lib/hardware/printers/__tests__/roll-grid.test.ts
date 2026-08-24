import { describe, it, expect } from "vitest"

import { defaultBlock, type PrintBlock, type PrintTemplateConfig } from "@/lib/types/print-template"
import { buildRollGrid, rollGeometry, wrapToWidth, ROLL_COLUMNS } from "../roll-grid"
import type { TicketData } from "../build-ticket-data"

/**
 * La grilla de caracteres es la ÚNICA geometría del rollo: de acá salen tanto
 * los bytes ESC/POS como el HTML de la vista previa. Un cambio que rompa estas
 * expectativas rompe las dos superficies a la vez — que es exactamente el
 * acoplamiento que se buscaba (antes divergían en silencio).
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

function ticket(over: Partial<TicketData> = {}): TicketData {
  return {
    docType: "sale",
    companyName: "Almacén Central",
    transactionId: "tx-1",
    date: "24/08/2026",
    total: 150000,
    items: [],
    payments: [],
    ...over,
  } as unknown as TicketData
}

const geo80 = () => rollGeometry("receipt80", MM)
const rowsOf = (t: PrintTemplateConfig, d: TicketData) =>
  buildRollGrid(t, d, rollGeometry(t.page_size, MM)).rows.map((r) => r.text)

describe("wrapToWidth", () => {
  it("baja a la siguiente línea al superar el ancho (pedido del owner)", () => {
    expect(wrapToWidth("uno dos tres cuatro cinco", 11)).toEqual(["uno dos", "tres cuatro", "cinco"])
  })

  it("parte una palabra sola más larga que la línea en vez de desbordar", () => {
    expect(wrapToWidth("ABCDEFGHIJ", 4)).toEqual(["ABCD", "EFGH", "IJ"])
  })

  it("conserva la sangría del hijo de un add-on solo en la primera línea", () => {
    const out = wrapToWidth("  con queso extra", 10)
    expect(out[0].startsWith("  ")).toBe(true)
    expect(out.every((l) => l.length <= 10)).toBe(true)
  })

  it("nunca devuelve una línea más larga que el ancho", () => {
    const long = "Empanada de carne cortada a cuchillo con cebolla de verdeo"
    for (const w of [12, 20, 32, 48]) {
      expect(wrapToWidth(long, w).every((l) => l.length <= w)).toBe(true)
    }
  })
})

describe("rollGeometry", () => {
  it("usa las columnas del papel de diseño cuando no hay binding", () => {
    expect(rollGeometry("receipt57", MM).columns).toBe(ROLL_COLUMNS.receipt57)
    expect(rollGeometry("receipt80", MM).columns).toBe(ROLL_COLUMNS.receipt80)
  })

  it("el ancho del dispositivo gana sobre el del diseño", () => {
    // Plantilla de 57mm mandada a una térmica de 80mm.
    expect(rollGeometry("receipt57", MM, 80).columns).toBe(ROLL_COLUMNS.receipt80)
  })

  it("el ancho del canvas equivale exactamente a `columns` caracteres", () => {
    const g = geo80()
    expect(g.charWidthPx * g.columns).toBeCloseTo(g.canvasWidthPx, 6)
  })
})

describe("buildRollGrid — la posición del canvas manda", () => {
  it("respeta el `top`: un bloque más abajo cae en una fila posterior", () => {
    const g = geo80()
    const rows = rowsOf(
      tpl([
        { ...defaultBlock("company_name"), top: 0, left: 0, width: 302, height: 12 },
        { ...defaultBlock("date"), top: Math.round(g.lineHeightPx * 3), left: 0, width: 302, height: 12 },
      ]),
      ticket(),
    )
    expect(rows[0]).toContain("Almacén Central")
    expect(rows[3]).toContain("24/08/2026")
    // Las filas intermedias quedan en blanco, como en el canvas.
    expect(rows[1]).toBe("")
    expect(rows[2]).toBe("")
  })

  it("respeta el `left`: dos bloques en la misma altura comparten fila en columnas distintas", () => {
    const g = geo80()
    const half = Math.round(g.charWidthPx * 24)
    const rows = rowsOf(
      tpl([
        { ...defaultBlock("company_name"), top: 0, left: 0, width: half, height: 12 },
        { ...defaultBlock("date"), top: 0, left: half, width: half, height: 12 },
      ]),
      ticket(),
    )
    expect(rows).toHaveLength(1)
    expect(rows[0]).toContain("Almacén Central")
    expect(rows[0]).toContain("24/08/2026")
    expect(rows[0].indexOf("24/08/2026")).toBeGreaterThanOrEqual(24)
  })

  it("ninguna fila supera las columnas del papel", () => {
    const rows = rowsOf(
      tpl([
        {
          ...defaultBlock("custom", "Muchas gracias por su compra, vuelva pronto a visitarnos"),
          top: 0,
          left: 0,
          width: 302,
          height: 12,
          textwrap: "wrap",
        },
      ]),
      ticket(),
    )
    expect(rows.length).toBeGreaterThan(1)
    expect(rows.every((r) => r.length <= ROLL_COLUMNS.receipt80)).toBe(true)
  })

  it("alinea a la derecha dentro de la caja del bloque", () => {
    const rows = rowsOf(
      tpl([{ ...defaultBlock("date"), top: 0, left: 0, width: 302, height: 12, align: "right" }]),
      ticket(),
    )
    expect(rows[0].endsWith("24/08/2026")).toBe(true)
    expect(rows[0].length).toBe(ROLL_COLUMNS.receipt80)
  })

  it("hor_line sale como una fila de guiones del ancho del bloque", () => {
    const g = geo80()
    const rows = rowsOf(
      tpl([
        {
          ...defaultBlock("hor_line"),
          top: 0,
          left: 0,
          width: Math.round(g.charWidthPx * 10),
          height: 12,
        },
      ]),
      ticket(),
    )
    expect(rows[0]).toBe("-".repeat(10))
  })

  it("uppercase se aplica ANTES de wrapear, no como estilo del renderer", () => {
    const rows = rowsOf(
      tpl(
        [{ ...defaultBlock("company_name"), top: 0, left: 0, width: 302, height: 12 }],
        { page_font_case: "uppercase" },
      ),
      ticket(),
    )
    expect(rows[0]).toContain("ALMACÉN CENTRAL")
  })

  it("un bloque sin dato no imprime una línea en blanco de más", () => {
    const rows = rowsOf(
      tpl([
        { ...defaultBlock("company_name"), top: 0, left: 0, width: 302, height: 12 },
        // `customer_tin` sin cliente resuelve null.
        { ...defaultBlock("customer_tin"), top: 200, left: 0, width: 302, height: 12 },
      ]),
      ticket(),
    )
    expect(rows).toHaveLength(1)
  })

  it("NO inyecta el banner de comanda: lo que se imprime lo decide la plantilla", () => {
    const rows = rowsOf(
      tpl([{ ...defaultBlock("company_name"), top: 0, left: 0, width: 302, height: 12 }]),
      ticket({ docType: "order", orderDestination: "Mesa 3", ticketNo: "12" } as Partial<TicketData>),
    )
    expect(rows.join("\n")).not.toContain("COMANDA")
    expect(rows.join("\n")).not.toContain("Mesa 3")
  })

  it("la comanda imprime destino y número cuando la PLANTILLA los tiene", () => {
    const rows = rowsOf(
      tpl([
        { ...defaultBlock("order_number"), top: 0, left: 0, width: 302, height: 12 },
        { ...defaultBlock("order_destination"), top: 20, left: 0, width: 302, height: 12 },
      ]),
      ticket({ docType: "order", orderDestination: "Mesa 3", ticketNo: "12" } as Partial<TicketData>),
    )
    expect(rows.join("\n")).toContain("12")
    expect(rows.join("\n")).toContain("Mesa 3")
  })

  it("la negrita del bloque viaja como atributo del tramo, no como texto", () => {
    const grid = buildRollGrid(
      tpl([{ ...defaultBlock("company_name"), top: 0, left: 0, width: 302, height: 12, bold: "bold" }]),
      ticket(),
      geo80(),
    )
    expect(grid.rows[0].runs.some((r) => r.bold)).toBe(true)
    expect(grid.rows[0].text).toContain("Almacén Central")
  })

  it("el QR queda como gráfico anclado a su fila, no como caracteres", () => {
    const g = geo80()
    const grid = buildRollGrid(
      tpl([
        { ...defaultBlock("company_name"), top: 0, left: 0, width: 302, height: 12 },
        {
          ...defaultBlock("fe_py"),
          top: Math.round(g.lineHeightPx * 4),
          left: 0,
          width: 302,
          height: 40,
        },
      ]),
      ticket({ einvoiceUrl: "https://ekuatia.set.gov.py/consultas/x" } as Partial<TicketData>),
      g,
    )
    expect(grid.graphics).toHaveLength(1)
    expect(grid.graphics[0].kind).toBe("qrcode")
    expect(grid.graphics[0].row).toBe(4)
    expect(grid.rows.join("\n")).not.toContain("https://")
  })
})

describe("buildRollGrid — crecimiento por ítems", () => {
  const items = [
    { name: "Empanada de carne", qty: 2, unitPrice: 8000, total: 16000 },
    { name: "Gaseosa 500ml", qty: 1, unitPrice: 7000, total: 7000 },
  ] as unknown as TicketData["items"]

  it("la fila de ítems se repite una vez por producto y empuja lo de abajo", () => {
    const g = geo80()
    const rowH = Math.round(g.lineHeightPx)
    const rows = rowsOf(
      tpl([
        { ...defaultBlock("item"), top: 0, left: 0, width: Math.round(g.charWidthPx * 30), height: rowH },
        {
          ...defaultBlock("item_total"),
          top: 0,
          left: Math.round(g.charWidthPx * 30),
          width: Math.round(g.charWidthPx * 18),
          height: rowH,
          align: "right",
        },
        { ...defaultBlock("total"), top: rowH * 2, left: 0, width: 302, height: rowH, align: "right" },
      ]),
      ticket({ items, total: 23000 }),
    )
    expect(rows[0]).toContain("Empanada de carne")
    expect(rows[1]).toContain("Gaseosa 500ml")
    // Cada ítem ocupa su fila y el importe queda en la MISMA fila que su
    // nombre — no desplazado, que era el bug del primer rollo posicional.
    expect(rows[0]).toContain("16.000")
    expect(rows[1]).toContain("7.000")
    // El total estaba en la fila 2 del canvas; el grupo de ítems creció una
    // fila (2 ítems donde el canvas reservaba 1), así que baja a la 3.
    expect(rows[3]).toContain("23.000")
  })
})
