/**
 * Geometría del ROLLO (57/76/80mm) — proyección del canvas del editor sobre la
 * grilla de caracteres de una impresora térmica.
 *
 * ── Por qué existe ───────────────────────────────────────────────────────
 *
 * Hasta 2026-08-24 el rollo era el agujero de la unificación de geometría:
 * `renderSheetBody` (hoja A4/Carta/Legal) respetaba `top`/`left` del canvas,
 * pero `renderTicketBody` los DESCARTABA — flujo lineal, un `<div>` por bloque
 * en orden de lectura. El editor mostraba un canvas posicional y ni la vista
 * previa ni el papel se le parecían (bug reportado por el owner). ESC/POS hacía
 * lo mismo por su cuenta: tercera implementación, tercer resultado.
 *
 * Decisión del owner (2026-08-24): "la posición del bloque debe ser respetada
 * según el canvas visual y debe mapearse para replicarlo en el ESC/POS", y "si
 * la línea supera los caracteres, debe bajar a la siguiente línea".
 *
 * Este módulo es esa proyección, y es UNA SOLA: construye una grilla de
 * caracteres a partir de la geometría del canvas, y de esa misma grilla salen
 * los DOS destinos del rollo — los bytes ESC/POS (`render-template.ts`) y el
 * HTML de vista previa / impresión por navegador (`html-renderer.ts`). El
 * preview no "imita" a la impresora: renderiza literalmente las mismas filas
 * de caracteres, así que el wrap no puede divergir entre uno y otro.
 *
 * ── Cómo se mapea px del canvas → fila/columna ───────────────────────────
 *
 * El ancho del canvas equivale a `columns` caracteres, así que:
 *
 *     charWidthPx  = canvasWidthPx / columns
 *     lineHeightPx = charWidthPx * ESC_POS_CELL_ASPECT
 *
 * `ESC_POS_CELL_ASPECT` = 2 porque la celda de la Font A de ESC/POS es de
 * 12x24 puntos — el alto es el doble del ancho. No es un número elegido a ojo:
 * es la forma real del carácter que la impresora va a poner en esa posición,
 * y es lo que hace que "una caja el doble de alta que ancha" en el canvas
 * ocupe una cantidad de filas coherente con lo que el operador ve.
 *
 * ── Regla de redondeo (alguien la va a cuestionar) ───────────────────────
 *
 * Fila y columna usan `Math.round`, NO `floor` ni `ceil`. El criterio es
 * "cae en la celda más cercana": un bloque que el operador dejó a 2.4 celdas
 * del borde sale en la celda 2, uno a 2.6 sale en la 3. Con `floor` todo se
 * corre sistemáticamente hacia arriba/izquierda (un bloque a 1.9 filas
 * aterrizaría en la fila 1) y el acumulado de ese sesgo desarma un layout
 * centrado; con `ceil` pasa lo mismo en el otro sentido. `round` es el único
 * de los tres cuyo error es simétrico y acotado a media celda.
 *
 * Los ANCHOS (`widthChars`, largo de reglas) también usan `round`, con piso 1:
 * un bloque nunca vale 0 caracteres — si el operador lo dibujó, algo tiene que
 * salir.
 *
 * ── Qué NO entra en la grilla ────────────────────────────────────────────
 *
 * Logo, código de barras y QR (`fe_py`) no son caracteres: son comandos
 * gráficos que la térmica imprime ocupando su propio alto, y no se pueden
 * ubicar en una columna arbitraria (ESC/POS los emite en el flujo, alineados
 * a izquierda/centro/derecha). Se tratan como "gráficos anclados": conservan
 * su FILA (la posición vertical del canvas se respeta) y su alineación, pero
 * su `left` se ignora. Reservan sus filas en la grilla para que ningún texto
 * se les superponga. Es una limitación del dispositivo, no del modelo.
 */

import {
  isReceipt,
  PAPER_DIMENSIONS,
  type PaperSize,
  type PrintBlock,
  type PrintTemplateConfig,
} from "@/lib/types/print-template"
import type { TicketData } from "./build-ticket-data"
import {
  BLOCK_VALUE_RESOLVERS,
  ITEM_FIELD_RESOLVERS,
  ITEM_LINE_TYPES,
  ITEM_TABLE_TYPES,
  formatMoney,
  itemTableColumns,
  lineGeometry,
  sortBlocksForRender,
  ticketItemName,
} from "./blocks"

/** Relación alto/ancho de la celda de carácter de ESC/POS (Font A, 12x24
 *  puntos). Ver docblock del módulo. */
export const ESC_POS_CELL_ASPECT = 2

/**
 * Columnas por ancho de rollo. 32/48 son los valores que el proyecto ya
 * manejaba para 58/80mm (`render-template.ts`); 76mm (impresoras de 3" de
 * matriz de punto y algunas térmicas industriales) va a 42, el valor estándar
 * para ese ancho. Un rollo NO listado no existe: `PaperSize` solo tiene estos
 * tres tamaños de receipt.
 */
export const ROLL_COLUMNS: Record<"receipt57" | "receipt76" | "receipt80", number> = {
  receipt57: 32,
  receipt76: 42,
  receipt80: 48,
}

export interface RollGeometry {
  columns: number
  canvasWidthPx: number
  charWidthPx: number
  lineHeightPx: number
}

/**
 * Geometría del rollo para una plantilla.
 *
 * `paperWidthMm` (58/80) viene del BINDING de la impresora y gana sobre el
 * `page_size` de la plantilla cuando está presente: la grilla tiene que ser la
 * del dispositivo que va a imprimir, no la del papel con el que se diseñó. Una
 * plantilla de 57mm mandada a una térmica de 80mm se proyecta sobre 48
 * columnas y las posiciones se reparten proporcionalmente — que es justo por
 * qué el mapeo usa FRACCIONES del ancho del canvas y no píxeles absolutos.
 */
export function rollGeometry(
  pageSize: PaperSize,
  mm: number,
  paperWidthMm?: 58 | 80,
): RollGeometry {
  const columns = paperWidthMm
    ? paperWidthMm === 58
      ? ROLL_COLUMNS.receipt57
      : ROLL_COLUMNS.receipt80
    : ROLL_COLUMNS[pageSize as keyof typeof ROLL_COLUMNS] ?? ROLL_COLUMNS.receipt80
  const dim = PAPER_DIMENSIONS[pageSize] ?? PAPER_DIMENSIONS.receipt80
  const ratio = mm > 0 ? mm : 3.78
  const canvasWidthPx = dim.widthMm * ratio
  const charWidthPx = canvasWidthPx / columns
  return {
    columns,
    canvasWidthPx,
    charWidthPx,
    lineHeightPx: charWidthPx * ESC_POS_CELL_ASPECT,
  }
}

/**
 * Corta un texto para que ninguna línea supere `width` caracteres — el pedido
 * literal del owner ("si la línea supera los caracteres, debe bajar a la
 * siguiente línea"). Antes NO existía: ESC/POS mandaba la línea entera y la
 * impresora la truncaba o la partía a su criterio, y el HTML la desbordaba.
 *
 * Corta por palabras; una palabra sola más larga que el ancho (un código de
 * barras tipeado, una URL) se parte a lo bruto, porque la alternativa es
 * desbordar y volver al problema original. La indentación inicial se preserva
 * en la PRIMERA línea nada más — es la sangría del hijo de un add-on
 * (`ticketItemName`), y repetirla en cada línea de continuación desalinearía
 * el corte.
 */
export function wrapToWidth(text: string, width: number): string[] {
  const max = Math.max(1, Math.floor(width))
  if (text === "") return []
  const out: string[] = []
  for (const paragraph of text.split("\n")) {
    if (paragraph === "") {
      out.push("")
      continue
    }
    const indentMatch = /^\s*/.exec(paragraph)
    const indent = indentMatch ? indentMatch[0] : ""
    let current = indent
    let hasWord = false
    const words = paragraph.slice(indent.length).split(/\s+/).filter(Boolean)
    for (let word of words) {
      // Palabra sola más larga que la línea: se parte en pedazos de `max`.
      while (word.length > max) {
        if (hasWord) {
          out.push(current)
          current = ""
          hasWord = false
        }
        out.push(word.slice(0, max))
        word = word.slice(max)
      }
      const candidate = hasWord ? `${current} ${word}` : `${current}${word}`
      if (candidate.length > max && hasWord) {
        out.push(current)
        current = word
      } else {
        current = candidate
        hasWord = true
      }
    }
    if (hasWord || out.length === 0) out.push(current)
  }
  return out
}

/** Alinea una línea dentro de una caja de `width` caracteres. El relleno son
 *  espacios: la grilla es de ancho fijo y la alineación es POSICIÓN dentro de
 *  la caja del bloque, no del papel. */
function alignInBox(line: string, width: number, align: PrintBlock["align"]): string {
  const clipped = line.length > width ? line.slice(0, width) : line
  const slack = width - clipped.length
  if (slack <= 0) return clipped
  if (align === "right") return " ".repeat(slack) + clipped
  if (align === "center") return " ".repeat(Math.floor(slack / 2)) + clipped
  return clipped
}

/** Gráfico anclado a una fila — no cabe en la grilla de caracteres (ver
 *  docblock del módulo). */
export interface RollGraphic {
  kind: "logo" | "barcode" | "qrcode"
  /** Fila de la grilla donde va, en el flujo de salida. */
  row: number
  align: PrintBlock["align"]
  /** Contenido: url del logo, id para el barcode, link para el QR. */
  value: string
  /** Rótulo bajo el QR (`fe_py` lo guarda en `block.text`). */
  caption?: string
}

/** Tramo contiguo de una fila con los mismos atributos. Hoy el único atributo
 *  es la negrita — que ESC/POS YA soportaba por bloque antes de la grilla, así
 *  que se conserva (no es formato nuevo: es no regresar). Doble alto/ancho
 *  entrarían acá el día que se implementen, sin tocar el resto del modelo. */
export interface RollRun {
  text: string
  bold: boolean
}

export interface RollRow {
  /** Fila completa en texto plano (sin atributos). */
  text: string
  /** La misma fila partida en tramos por atributo. */
  runs: RollRun[]
}

export interface RollGrid {
  columns: number
  /** Filas de caracteres, ya wrapeadas y alineadas. */
  rows: RollRow[]
  graphics: RollGraphic[]
}

/** Texto lógico (sin wrapear) de un bloque que NO es de ítem ni gráfico. */
function blockTextLines(block: PrintBlock, data: TicketData): string[] {
  if (block.type === "payment_methods") {
    return data.payments.map((p) => `${p.method}: ${formatMoney(p.amount, data)}`)
  }
  if (ITEM_TABLE_TYPES.has(block.type)) {
    const cols = itemTableColumns(block.type)
    const lines: string[] = []
    for (const item of data.items) {
      lines.push(ticketItemName(item))
      const parts: string[] = []
      if (cols.qty) parts.push(`${item.qty}x`)
      if (cols.unitPrice) parts.push(formatMoney(item.unitPrice, data))
      if (cols.total) parts.push(formatMoney(item.total, data))
      if (parts.length) lines.push(parts.join("  "))
    }
    return lines
  }
  const resolver = BLOCK_VALUE_RESOLVERS[block.type]
  if (resolver) {
    const value = resolver(data, block)
    return value ? [value] : []
  }
  console.error("[roll-grid] BlockType desconocido, no implementado:", block.type)
  return [`[${block.type}]`]
}

/** Celda ocupada por un gráfico: bloquea el paso a `free()` pero imprime un
 *  espacio. Un carácter de control nunca aparece en un dato real. */
const RESERVED = "\u0000"

/** Mesa de caracteres que crece por demanda. */
class CharCanvas {
  private grid: string[][] = []
  /** Atributo por celda, en paralelo a `grid` — ver `RollRun`. */
  private boldGrid: boolean[][] = []
  constructor(private readonly columns: number) {}

  private ensure(row: number) {
    while (this.grid.length <= row) {
      this.grid.push(new Array<string>(this.columns).fill(" "))
      this.boldGrid.push(new Array<boolean>(this.columns).fill(false))
    }
  }

  /** ¿Están libres (solo espacios) las `height` filas desde `row` en el rango
   *  de columnas dado? */
  free(row: number, col: number, width: number, height: number): boolean {
    for (let r = row; r < row + height; r++) {
      if (r >= this.grid.length) continue // fila virgen
      for (let c = col; c < Math.min(col + width, this.columns); c++) {
        if (this.grid[r][c] !== " ") return false
      }
    }
    return true
  }

  write(row: number, col: number, text: string, bold = false) {
    this.ensure(row)
    for (let i = 0; i < text.length; i++) {
      const c = col + i
      if (c < 0 || c >= this.columns) continue
      this.grid[row][c] = text[i]
      this.boldGrid[row][c] = bold
    }
  }

  /** Ocupa un rango sin pintar nada — para que un gráfico reserve su alto y
   *  ningún texto le caiga encima. `RESERVED` cuenta como ocupado para
   *  `free()` y se borra al serializar. */
  reserve(row: number, height: number) {
    for (let r = row; r < row + Math.max(1, height); r++) {
      this.ensure(r)
      for (let c = 0; c < this.columns; c++) {
        if (this.grid[r][c] === " ") this.grid[r][c] = RESERVED
      }
    }
  }

  toRows(): RollRow[] {
    const rows: RollRow[] = this.grid.map((cells, r) => {
      const text = cells.join("").split(RESERVED).join(" ").replace(/\s+$/, "")
      const runs: RollRun[] = []
      for (let c = 0; c < text.length; c++) {
        const bold = this.boldGrid[r][c] === true
        const last = runs[runs.length - 1]
        if (last && last.bold === bold) last.text += text[c]
        else runs.push({ text: text[c], bold })
      }
      return { text, runs }
    })
    // Cola en blanco: papel desperdiciado, nunca es diseño.
    while (rows.length > 0 && rows[rows.length - 1].text === "") rows.pop()
    return rows
  }
}

/**
 * Proyecta la plantilla sobre la grilla de caracteres del rollo.
 *
 * Reglas de crecimiento (el rollo es infinito hacia abajo, el canvas no):
 *
 *  - Los bloques de ÍTEM (`ITEM_LINE_TYPES`) son una fila plantilla que se
 *    repite una vez por producto, igual que en hoja (`renderSheetBody`): el
 *    paso por ítem es el alto MÁXIMO del grupo, para que todas las columnas
 *    de una misma fila avancen juntas.
 *
 *  - Cualquier bloque que ocupe MÁS filas de las que el operador le reservó
 *    (texto que wrapeó, tabla de ítems que creció) empuja hacia abajo lo que
 *    viene DESPUÉS. El empuje se acumula por `top`: bloques que comparten la
 *    misma altura en el canvas (un layout de dos columnas) no se empujan entre
 *    sí — se toma el MÁXIMO del grupo y se aplica recién al siguiente `top`.
 *    Sin esto, la segunda columna de una fila se hundiría cada vez que la
 *    primera wrapeara.
 *
 *  - Si aun así dos bloques caen sobre las mismas celdas (redondeo, diseño
 *    superpuesto), el segundo baja hasta la primera fila donde entra, en vez
 *    de pisar al primero. Perder texto impreso es peor que correrlo.
 */
export function buildRollGrid(
  template: PrintTemplateConfig,
  data: TicketData,
  geo: RollGeometry,
): RollGrid {
  const blocks = sortBlocksForRender(template.data ?? [])
  const canvas = new CharCanvas(geo.columns)
  const graphics: RollGraphic[] = []
  const upper = template.page_font_case === "uppercase"
  const cased = (s: string) => (upper ? s.toUpperCase() : s)

  const toCol = (px: number) =>
    Math.min(geo.columns - 1, Math.max(0, Math.round(px / geo.charWidthPx)))
  const toRow = (px: number) => Math.max(0, Math.round(px / geo.lineHeightPx))
  const toWidth = (px: number, col: number) =>
    Math.max(1, Math.min(geo.columns - col, Math.round(px / geo.charWidthPx)))
  const toRows = (px: number) => Math.max(1, Math.round(px / geo.lineHeightPx))

  let pushRows = 0
  let pendingGrowth = 0
  let lastTop: number | null = null

  /** Coloca líneas ya alineadas a partir de `row0`, bajando si está ocupado.
   *  Devuelve cuántas filas ocupó y en cuál arrancó. */
  const place = (row0: number, col: number, width: number, lines: string[], bold = false) => {
    if (lines.length === 0) return { row: row0, height: 0 }
    let row = row0
    while (!canvas.free(row, col, width, lines.length)) row++
    lines.forEach((line, i) => canvas.write(row + i, col, line, bold))
    return { row, height: lines.length }
  }

  let i = 0
  while (i < blocks.length) {
    const block = blocks[i]

    // Cambio de altura en el canvas → recién ahí se aplica el crecimiento
    // acumulado por los bloques de la altura anterior (ver docblock).
    if (lastTop !== null && block.top > lastTop) {
      pushRows += pendingGrowth
      pendingGrowth = 0
    }
    lastTop = block.top

    const col = toCol(block.left)
    const width = toWidth(block.width, col)
    const row0 = toRow(block.top) + pushRows
    const reserved = toRows(block.height)

    // ── Líneas ────────────────────────────────────────────────────────────
    // En una grilla de caracteres SÍ existen columnas, así que `ver_line`
    // deja de ser el no-op que era en ESC/POS: es una tira de '|'.
    const geoLine = lineGeometry(block)
    if (geoLine) {
      if (geoLine.orientation === "horizontal") {
        const len = toWidth(geoLine.length, col)
        const row = row0 + Math.round(geoLine.crossOffset / geo.lineHeightPx)
        place(row, col, len, ["-".repeat(len)])
      } else {
        const rows = toRows(geoLine.length)
        const c = Math.min(geo.columns - 1, col + Math.round(geoLine.crossOffset / geo.charWidthPx))
        place(row0, c, 1, new Array<string>(rows).fill("|"))
      }
      i++
      continue
    }

    // ── Gráficos (no caben en la grilla — ver docblock) ───────────────────
    if (block.type === "company_logo") {
      canvas.reserve(row0, reserved)
      graphics.push({ kind: "logo", row: row0, align: block.align, value: block.url ?? "" })
      i++
      continue
    }
    if (block.type === "transaction_id_barcode") {
      canvas.reserve(row0, reserved)
      graphics.push({ kind: "barcode", row: row0, align: block.align, value: data.transactionId })
      i++
      continue
    }
    if (block.type === "fe_py") {
      // Sin link (venta sin documento electrónico) no se imprime nada, ni el
      // rótulo — el dato no existe, igual que cualquier bloque sin valor.
      if (data.einvoiceUrl) {
        canvas.reserve(row0, reserved)
        graphics.push({
          kind: "qrcode",
          row: row0,
          align: block.align,
          value: data.einvoiceUrl,
          caption: cased(block.text?.trim() || "Consultá tu factura electrónica"),
        })
      }
      i++
      continue
    }

    // ── Fila de ítems: se repite una vez por producto ─────────────────────
    if (ITEM_LINE_TYPES.has(block.type)) {
      const start = i
      while (i < blocks.length && ITEM_LINE_TYPES.has(blocks[i].type)) i++
      const rowBlocks = blocks.slice(start, i)
      const stepRows = Math.max(...rowBlocks.map((b) => toRows(b.height)), 1)
      let cursor = row0
      for (const item of data.items) {
        // Se resuelven TODAS las columnas de la fila ANTES de escribir ninguna:
        // el alto real de la fila es el del campo que mas wrapeo, y el item
        // siguiente tiene que arrancar despues de eso. Si cada columna avanzara
        // por su cuenta a paso fijo, un nombre de producto largo dejaba el
        // precio del item siguiente pegado a la linea equivocada — asi se veia
        // en el primer dump del rollo posicional:
        //
        //   Empanada de carne cortada a      2     Gs 16.000
        //   cuchillo                         1      Gs 7.000   <- precio del 2do
        //   Gaseosa 500ml                                      <- su nombre, 1 fila abajo
        const cells = rowBlocks.map((rb) => {
          const rbCol = toCol(rb.left)
          const rbWidth = toWidth(rb.width, rbCol)
          const resolver = ITEM_FIELD_RESOLVERS[rb.type]
          const value = resolver ? resolver(item, data) ?? "" : ""
          const lines = value
            ? wrapToWidth(cased(value), rbWidth).map((l) => alignInBox(l, rbWidth, rb.align))
            : []
          return { col: rbCol, width: rbWidth, lines, bold: rb.bold === "bold" }
        })
        const used = Math.max(stepRows, ...cells.map((c) => c.lines.length))
        for (const cell of cells) place(cursor, cell.col, cell.width, cell.lines, cell.bold)
        cursor += used
      }
      // El canvas reservaba UNA fila para el grupo; con N items (y su wrap)
      // ocupa `cursor - row0`.
      pendingGrowth = Math.max(pendingGrowth, Math.max(0, cursor - row0 - stepRows))
      continue
    }

    // ── Texto ─────────────────────────────────────────────────────────────
    const logical = blockTextLines(block, data).map(cased)
    const lines: string[] = []
    for (const l of logical) {
      // `textwrap: "cut"` recorta a una línea; "wrap" deja bajar. El wrap por
      // ancho de papel es OBLIGATORIO en los dos casos: "cut" recorta el
      // EXCEDENTE del bloque, no habilita desbordar el rollo.
      const wrapped = wrapToWidth(l, width)
      if (block.textwrap === "cut") {
        if (wrapped.length) lines.push(wrapped[0])
      } else {
        lines.push(...wrapped)
      }
    }
    const aligned = lines.map((l) => alignInBox(l, width, block.align))
    const placed = place(row0, col, width, aligned, block.bold === "bold")
    pendingGrowth = Math.max(pendingGrowth, Math.max(0, placed.height - reserved))
    i++
  }

  return { columns: geo.columns, rows: canvas.toRows(), graphics }
}

/** ¿Esta plantilla va por el camino de rollo? Mismo criterio que ya usan el
 *  editor y los renderers (`isReceipt`). */
export function isRollTemplate(pageSize: PaperSize): boolean {
  return isReceipt(pageSize)
}
