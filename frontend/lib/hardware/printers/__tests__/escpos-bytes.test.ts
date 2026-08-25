import { describe, it, expect } from "vitest"

import { defaultBlock, type PrintBlock, type PrintTemplateConfig } from "@/lib/types/print-template"
import { renderTemplateToEscPos } from "../render-template"
import { buildRollGrid, rollGeometry, ROLL_COLUMNS } from "../roll-grid"
import type { TicketData } from "../build-ticket-data"

/**
 * Tests de BYTES — lo que realmente sale por el cable a la térmica.
 *
 * Existen porque los 22 tests de grilla pasaban mientras la impresora imprimía
 * mal: `encoder.text()` pasa por `TextWrap.wrap`, que DESCARTA los espacios de
 * un chunk cuando la línea está vacía, así que todo el padding de alineación
 * se perdía. La grilla decía "  Gs 23.000" y el papel imprimía "Gs 23.000"
 * pegado a la izquierda. Testear la grilla sola no puede ver eso: la grilla
 * era correcta.
 *
 * Criterios de aceptación del owner cubiertos acá: alineación real en papel
 * (izq/centro/der, en los dos anchos), listado de ítems dinámico que empuja
 * todo lo de abajo, y wrap por ancho de papel.
 */

const MM = 3.78

/** Texto imprimible de los bytes ESC/POS, fila por fila. Se descartan los
 *  comandos (ESC/GS) y se corta por LF: lo que queda es, literalmente, lo que
 *  la impresora pone en el papel. */
function printedLines(bytes: Uint8Array): string[] {
  const out: string[] = []
  let line = ""
  for (let i = 0; i < bytes.length; i++) {
    const b = bytes[i]
    if (b === 0x1b || b === 0x1d || b === 0x1c) {
      // ESC/GS/FS + su comando. Los que usamos son de 2-3 bytes; alcanza con
      // saltear el selector y su argumento cuando lo tiene.
      const cmd = bytes[i + 1]
      i += 1
      if (cmd === 0x21 || cmd === 0x45 || cmd === 0x61 || cmd === 0x74 || cmd === 0x4d) i += 1
      else if (cmd === 0x40) {
        /* init: sin argumento */
      } else if (cmd === 0x2e) {
        /* FS . : sin argumento */
      }
      continue
    }
    if (b === 0x0a) {
      out.push(line)
      line = ""
      continue
    }
    if (b === 0x0d) continue
    line += String.fromCharCode(b)
  }
  if (line) out.push(line)
  return out
}

function tpl(data: PrintBlock[], pageSize: PrintTemplateConfig["page_size"] = "receipt80"): PrintTemplateConfig {
  return {
    page_size: pageSize,
    page_size_name: "",
    page_name: "",
    page_font_family: "Arial",
    page_font_size: "8pt",
    page_font_case: "none",
    receipt_left_margin: "7",
    mm: MM,
    data,
  }
}

function ticket(over: Partial<TicketData> = {}): TicketData {
  return {
    docType: "sale",
    companyName: "Almacén Central",
    transactionId: "tx-1",
    date: "24/08/2026",
    // Tenant paraguayo declarado. Estos tests miden ALINEACIÓN (columnas y
    // bytes de padding), así que el ancho de la etiqueta de moneda importa:
    // sin país ni moneda la etiqueta sería el signo genérico y las columnas
    // se correrían. Antes salía "Gs" por el default escondido de formatMoney.
    country: "PY",
    total: 23000,
    items: [],
    payments: [],
    ...over,
  } as unknown as TicketData
}

function emit(config: PrintTemplateConfig, data: TicketData, paperWidthMm: 58 | 80): string[] {
  return printedLines(
    renderTemplateToEscPos({ template: config, data, paperWidthMm, openDrawer: false, copies: 1 }),
  )
}

describe("bytes ESC/POS — alineación real en el papel", () => {
  for (const paperWidthMm of [58, 80] as const) {
    const cols = ROLL_COLUMNS[paperWidthMm]

    it(`${paperWidthMm}mm: alineación izquierda arranca en la columna 0`, () => {
      const geo = rollGeometry("receipt80", MM, paperWidthMm)
      const lines = emit(
        tpl([{ ...defaultBlock("total"), top: 0, left: 0, width: Math.round(geo.canvasWidthPx), height: 12, align: "left" }]),
        ticket(),
        paperWidthMm,
      )
      expect(lines[0]).toBe("Gs 23.000")
    })

    it(`${paperWidthMm}mm: alineación DERECHA conserva el padding (el bug del encoder)`, () => {
      const geo = rollGeometry("receipt80", MM, paperWidthMm)
      const lines = emit(
        tpl([{ ...defaultBlock("total"), top: 0, left: 0, width: Math.round(geo.canvasWidthPx), height: 12, align: "right" }]),
        ticket(),
        paperWidthMm,
      )
      // Lo que importa: los espacios de padding LLEGAN a la impresora.
      expect(lines[0]).toBe(" ".repeat(cols - "Gs 23.000".length) + "Gs 23.000")
      expect(lines[0]).toHaveLength(cols)
    })

    it(`${paperWidthMm}mm: alineación CENTRADA conserva el padding`, () => {
      const geo = rollGeometry("receipt80", MM, paperWidthMm)
      const lines = emit(
        tpl([{ ...defaultBlock("total"), top: 0, left: 0, width: Math.round(geo.canvasWidthPx), height: 12, align: "center" }]),
        ticket(),
        paperWidthMm,
      )
      const expectedLead = Math.floor((cols - "Gs 23.000".length) / 2)
      expect(lines[0]).toBe(" ".repeat(expectedLead) + "Gs 23.000")
    })

    it(`${paperWidthMm}mm: el byte del padding es 0x20 y va antes del contenido`, () => {
      const geo = rollGeometry("receipt80", MM, paperWidthMm)
      const bytes = renderTemplateToEscPos({
        template: tpl([
          { ...defaultBlock("total"), top: 0, left: 0, width: Math.round(geo.canvasWidthPx), height: 12, align: "right" },
        ]),
        data: ticket(),
        paperWidthMm,
        openDrawer: false,
        copies: 1,
      })
      // Índice de la secuencia "Gs" (0x47,0x73) — un 0x47 suelto también
      // aparece dentro de los bytes de comando del encoder.
      let gIdx = -1
      for (let i = 0; i < bytes.length - 1; i++) {
        if (bytes[i] === 0x47 && bytes[i + 1] === 0x73) {
          gIdx = i
          break
        }
      }
      expect(gIdx).toBeGreaterThan(0)
      // El padding son bytes 0x20 REALES en el stream, emitidos por `raw()`.
      // Entre ellos y el contenido el encoder mete su selector de codepage
      // (`ESC t n`), así que se cuentan los espacios de toda la región previa
      // en vez de mirar solo el byte pegado a la "G".
      let spaces = 0
      for (let i = 0; i < gIdx; i++) if (bytes[i] === 0x20) spaces++
      expect(spaces).toBe(cols - "Gs 23.000".length)
    })
  }
})

describe("bytes ESC/POS — el listado de ítems es dinámico", () => {
  const geo = rollGeometry("receipt80", MM, 80)
  const rowH = Math.round(geo.lineHeightPx)
  const c = (n: number) => Math.round(geo.charWidthPx * n)

  /** Ítems arriba, una regla y el total abajo: el pie SIEMPRE tiene que quedar
   *  debajo del último ítem, sin superponerse. */
  const config = tpl([
    { ...defaultBlock("item"), top: rowH * 0, left: 0, width: c(30), height: rowH, textwrap: "wrap" },
    { ...defaultBlock("item_total"), top: rowH * 0, left: c(30), width: c(18), height: rowH, align: "right" },
    { ...defaultBlock("hor_line"), top: rowH * 1, left: 0, width: c(48), height: rowH },
    { ...defaultBlock("total"), top: rowH * 2, left: 0, width: c(48), height: rowH, align: "right" },
  ])

  const mkItems = (n: number, name = "Producto") =>
    Array.from({ length: n }, (_, i) => ({
      name: `${name} ${i + 1}`,
      qty: 1,
      unitPrice: 1000,
      total: 1000,
    })) as unknown as TicketData["items"]

  for (const n of [1, 10]) {
    it(`con ${n} ítem(s), la regla y el total quedan DEBAJO del último`, () => {
      const lines = emit(config, ticket({ items: mkItems(n) }), 80)
      const lastItem = lines.findIndex((l) => l.includes(`Producto ${n}`))
      const rule = lines.findIndex((l) => l.startsWith("---"))
      const total = lines.findIndex((l) => l.includes("Gs 23.000"))
      expect(lastItem).toBeGreaterThanOrEqual(0)
      expect(rule).toBeGreaterThan(lastItem)
      expect(total).toBeGreaterThan(rule)
      // Ningún ítem se pierde ni se superpone con otro.
      for (let i = 1; i <= n; i++) {
        expect(lines.filter((l) => l.includes(`Producto ${i} `) || l.endsWith(`Producto ${i}`)).length).toBeGreaterThan(0)
      }
    })
  }

  it("un ítem de nombre largo wrapea y empuja el pie una fila más", () => {
    const items = [
      { name: "Empanada de carne cortada a cuchillo con cebolla", qty: 1, unitPrice: 1000, total: 1000 },
      { name: "Gaseosa", qty: 1, unitPrice: 1000, total: 1000 },
    ] as unknown as TicketData["items"]
    const lines = emit(config, ticket({ items }), 80)
    const gaseosa = lines.findIndex((l) => l.includes("Gaseosa"))
    const rule = lines.findIndex((l) => l.startsWith("---"))
    const total = lines.findIndex((l) => l.includes("Gs 23.000"))
    // El nombre largo ocupó 2 filas; "Gaseosa" arranca después, no encima.
    expect(gaseosa).toBeGreaterThan(1)
    expect(rule).toBeGreaterThan(gaseosa)
    expect(total).toBeGreaterThan(rule)
    // Y el importe de cada ítem sigue en la fila de SU nombre.
    expect(lines[gaseosa]).toContain("Gs 1.000")
  })
})

describe("bytes ESC/POS — wrap por ancho de papel", () => {
  const geo = rollGeometry("receipt80", MM, 80)

  it("ninguna línea impresa supera las columnas del papel", () => {
    for (const paperWidthMm of [58, 80] as const) {
      const lines = emit(
        tpl([
          {
            ...defaultBlock("custom", "Gracias por su compra, vuelva pronto a visitarnos por nuestro local"),
            top: 0,
            left: 0,
            width: Math.round(geo.canvasWidthPx),
            height: 12,
            textwrap: "wrap",
          },
        ]),
        ticket(),
        paperWidthMm,
      )
      expect(lines.length).toBeGreaterThan(1)
      expect(lines.every((l) => l.length <= ROLL_COLUMNS[paperWidthMm])).toBe(true)
    }
  })
})

describe("bytes ESC/POS — no-ASCII", () => {
  it("é y Ñ se traducen a la codepage de la impresora, no se pierden", () => {
    const bytes = renderTemplateToEscPos({
      template: tpl([
        { ...defaultBlock("custom", "Almacén Ñandú"), top: 0, left: 0, width: 302, height: 12 },
      ]),
      data: ticket(),
      paperWidthMm: 80,
      openDrawer: false,
      copies: 1,
    })
    // CP437: é = 0x82, Ñ = 0xa5. Si salieran como "?" o se perdieran, el
    // ticket imprimiría "Almacn" — que es lo que pasaba antes de que el
    // proyecto fijara la codepage.
    expect(Array.from(bytes)).toContain(0x82)
    expect(Array.from(bytes)).toContain(0xa5)
  })

  it("el ancho se cuenta en caracteres, no en bytes: el acento no desalinea", () => {
    const geo = rollGeometry("receipt80", MM, 80)
    const grid = buildRollGrid(
      tpl([
        {
          ...defaultBlock("custom", "Almacén"),
          top: 0,
          left: 0,
          width: Math.round(geo.canvasWidthPx),
          height: 12,
          align: "right",
        },
      ]),
      ticket(),
      geo,
    )
    expect(grid.rows[0].text).toHaveLength(ROLL_COLUMNS[80])
    const lines = emit(
      tpl([
        {
          ...defaultBlock("custom", "Almacén"),
          top: 0,
          left: 0,
          width: Math.round(geo.canvasWidthPx),
          height: 12,
          align: "right",
        },
      ]),
      ticket(),
      80,
    )
    expect(lines[0].endsWith("n")).toBe(true)
    expect(lines[0]).toHaveLength(ROLL_COLUMNS[80])
  })
})
