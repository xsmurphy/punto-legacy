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
  resolveSimpleBlock,
  ITEM_FIELD_RESOLVERS,
  ITEM_LINE_TYPES,
  ITEM_TABLE_TYPES,
  itemTableCells,
  resolvePaymentLines,
  lineGeometry,
  sortBlocksForRender,
  ticketItemName,
} from "./blocks"

/** Relación alto/ancho de la celda de carácter de ESC/POS (Font A, 12x24
 *  puntos). Ver docblock del módulo. */
export const ESC_POS_CELL_ASPECT = 2

/**
 * Tipografía de TODO lo que se imprime en rollo — la del editor y la del papel.
 *
 * No es una preferencia estética: la térmica imprime en modo texto con una
 * celda de ancho FIJO, y toda la geometría de este módulo son columnas de
 * caracteres. Con una fuente proporcional, las mismas 48 columnas que la grilla
 * centró y cortó se pintan cada una de un ancho distinto: el texto sale corrido
 * y se desborda del papel aunque la grilla haya cortado bien (reporte del owner
 * 2026-08-28, con capturas del canvas centrado y la vista previa corrida).
 *
 * Por eso `page_font_family` y `block.family` NO se respetan en rollo: la
 * plantilla puede pedir Arial, pero la impresora no sabe imprimir Arial. Antes
 * el renderer la ponía primero en el stack (`'Arial', monospace`), o sea que la
 * plantilla ganaba y el resultado mentía sobre el papel. En HOJA sí se
 * respetan — ahí el navegador imprime con la fuente que se le pida.
 */
export const ROLL_FONT_STACK =
  "ui-monospace, 'SFMono-Regular', 'Menlo', 'DejaVu Sans Mono', 'Courier New', monospace"

/**
 * Avance horizontal de un carácter monoespaciado, en `em`.
 *
 * 0.6 es el valor de Courier New y SF Mono; Menlo y DejaVu Sans Mono miden
 * 0.602. Con 0.6 exacto, una línea llena calculaba justo el ancho del papel y
 * cualquier fuente un pelo más ancha desbordaba la última columna. 0.605 deja
 * medio punto porcentual de aire: la línea llena ocupa ~99% del papel en vez de
 * 100.3%, diferencia invisible que garantiza que nunca se corte.
 *
 * Es una aproximación VISUAL: los cortes de línea ya los decidió la grilla, así
 * que una métrica distinta cambia cuánto se llena el ancho del papel, nunca
 * dónde corta el texto.
 */
const CHAR_EM_RATIO = 0.605

/**
 * Tamaño de fuente para que `columns` caracteres ocupen exactamente `width`.
 *
 * Una sola fórmula para las dos superficies: el canvas del editor la usa en px
 * y el documento impreso en mm. Cuando vivía solo en `html-renderer.ts`, el
 * canvas dibujaba con el tamaño que la plantilla pedía y el papel con este —
 * y eso es, literalmente, que el editor muestre otra densidad de caracteres que
 * la que sale impresa.
 */
export function rollFontSizeFor(width: number, columns: number): number {
  return width / columns / CHAR_EM_RATIO
}

/**
 * Ajusta la geometría VERTICAL de un bloque a la grilla de caracteres del
 * rollo: `top` a una fila exacta y `height` a un número entero de filas
 * (mínimo una).
 *
 * Es lo que hace que el canvas y el papel digan lo mismo. El renderer mapea
 * píxeles a filas redondeando (`toRow`/`toRows` en `buildRollGrid`): un bloque
 * a 38px sobre filas de 11.97px imprime en la fila 3, y uno de 24px de alto
 * reserva 2 filas aunque muestre una sola línea. Mientras el canvas permita
 * posiciones intermedias, dos bloques pegados en pantalla pueden salir
 * separados por un renglón en blanco, o compartir fila y pisarse.
 *
 * Solo toca el eje Y: el horizontal en ticket ya está resuelto — todo bloque
 * ocupa el ancho completo del papel (regla del owner 2026-08-18).
 */
export function snapBlockToRollRows<T extends { top: number; height: number }>(
  block: T,
  geo: RollGeometry,
): T {
  const row = geo.lineHeightPx
  return {
    ...block,
    top: Math.round(Math.max(0, block.top) / row) * row,
    height: Math.max(1, Math.round(block.height / row)) * row,
  }
}

/**
 * Columnas por ancho REAL de dispositivo. Las térmicas del proyecto son de dos
 * anchos (`PrinterBinding.paperWidthMm`), así que esta
 * tabla tiene exactamente dos entradas.
 *
 * Antes había una tercera (`receipt76: 42`) indexada por el papel de DISEÑO.
 * Era inalcanzable: los dos caminos que llegan acá traen un ancho de
 * dispositivo — el binding en impresión real, y `nearestReceiptPaperWidthMm`
 * en la vista previa, que ya mapea 76mm a 80. Una constante que nadie puede
 * alcanzar es documentación falsa sobre lo que el sistema soporta.
 */
/** Anchos de papel de dispositivo soportados por los bindings. */
export type PaperWidthMm = 58 | 76 | 80

export const ROLL_COLUMNS: Record<PaperWidthMm, number> = {
  58: 32,
  // 76mm = impresoras de IMPACTO (Epson TM-U220 y compatibles, la impresora de
  // tickets más usada en PY — decisión del owner 2026-08-28 de soportarla como
  // dispositivo real). Font A de la TM-U220: 33 columnas de 9x9 puntos. Hasta
  // hoy "76mm" era solo un papel de DISEÑO proyectado a la térmica de 80 (48
  // columnas): en una TM-U220 real eso desborda cada línea en 15 caracteres.
  76: 33,
  80: 48,
}

/**
 * Papel de diseño → ancho de dispositivo. ÚNICA fuente de ese mapeo: la usan
 * la vista previa del editor (que no está atada a una impresora física, ver
 * `buildTemplatePreviewHtml` en index.ts) y esta geometría. 76mm no es un
 * ancho de térmica soportado, así que cae en la de 80 — que es lo que la
 * vista previa ya hacía por su cuenta.
 */
export function nearestReceiptPaperWidthMm(pageSize: PaperSize): PaperWidthMm {
  // 76 dejó de caer en 80: es un dispositivo real (TM-U220). El binding sigue
  // teniendo la última palabra — esto es solo el default sin impresora atada.
  if (pageSize === "receipt57") return 58
  if (pageSize === "receipt76") return 76
  return 80
}

/**
 * Columnas en blanco que se reservan a CADA lado del papel.
 *
 * El texto pegado al borde es ilegible y en una térmica real el corte nunca cae
 * exactamente donde dice la especificación (pedido del owner 2026-08-28: "un
 * carácter de espacio entre el borde y el texto").
 *
 * Se reservan de las columnas del DISPOSITIVO, no del diseño: el encoder
 * ESC/POS sigue emitiendo filas de `columns` caracteres —los márgenes viajan
 * como espacios— así que el papel impreso tiene el mismo margen que la vista
 * previa.
 */
export const ROLL_MARGIN_COLS = 1

export interface RollGeometry {
  columns: number
  canvasWidthPx: number
  /** Columnas ÚTILES: `columns` menos los márgenes de los dos lados. */
  contentColumns: number
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
  paperWidthMm?: PaperWidthMm,
): RollGeometry {
  const deviceWidthMm = paperWidthMm ?? nearestReceiptPaperWidthMm(pageSize)
  const columns = ROLL_COLUMNS[deviceWidthMm]
  const dim = PAPER_DIMENSIONS[pageSize] ?? PAPER_DIMENSIONS.receipt80
  const ratio = mm > 0 ? mm : 3.78
  const canvasWidthPx = dim.widthMm * ratio
  const contentColumns = Math.max(1, columns - ROLL_MARGIN_COLS * 2)
  // El carácter se mide contra las columnas del DISPOSITIVO, siempre: la celda
  // de la térmica mide ancho-del-papel / columnas, y el margen son dos de esas
  // celdas — no un ensanchamiento de las demás. La primera versión del margen
  // (2026-08-28) dividió por `contentColumns`: cada celda del canvas quedó ~4%
  // más ancha que la real, el texto diseñado "hasta el borde" medía 48 celdas
  // reales en un papel de 48 con 2 de margen, y la impresión se pasaba del
  // papel — exactamente el bug que el margen venía a arreglar.
  const charWidthPx = canvasWidthPx / columns
  return {
    columns,
    contentColumns,
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
  // Una línea que YA entra se devuelve TAL CUAL, sin pasar por el wrap.
  //
  // El wrap parte por palabras y las vuelve a unir con UN espacio, así que
  // destruía cualquier relleno intencional: la fila de ítems repartida a lo
  // ancho del papel (`distributeRow`) entraba como
  // "2        50.000        100.000" y salía "2 50.000 100.000", amontonada a
  // la izquierda. El owner lo reportó dos veces como "la lista no respeta las
  // columnas" (2026-08-28) — y no era la distribución, era este re-wrap.
  if (!text.includes("\n") && text.length <= max) return [text]
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

/**
 * Reparte las celdas de una fila a lo ANCHO del papel: la primera pegada al
 * borde izquierdo, la última pegada al derecho, y las del medio centradas en
 * el hueco que queda (pedido del owner 2026-08-26 — la grilla de ítems salía
 * toda amontonada a la izquierda con media línea en blanco).
 *
 *     2              12.000            24.000
 *     ^ cantidad     ^ unitario        ^ total, contra el borde
 *
 * Quién cede espacio cuando NO entra todo está decidido acá y no librado al
 * wrap: los EXTREMOS son intocables (la cantidad es el dato que el cliente
 * chequea primero, y el total contra el borde derecho es lo que hace legible
 * una columna de importes), así que lo que se comprime es la separación. Si ni
 * con un solo espacio entra, la fila cae al empaquetado izquierdo y wrapea
 * como cualquier otro texto — un importe recortado sería peor que una fila de
 * dos líneas.
 */
export function distributeRow(cells: string[], width: number): string {
  const parts = cells.filter((c) => c !== "")
  if (parts.length === 0) return ""
  if (parts.length === 1) return parts[0]

  const minimal = parts.reduce((n, p) => n + p.length, 0) + (parts.length - 1)
  if (minimal > width) return parts.join(" ")

  const first = parts[0]
  const last = parts[parts.length - 1]
  const middles = parts.slice(1, -1)

  const row = first + " ".repeat(width - first.length - last.length) + last
  if (middles.length === 0) return row

  // Cada celda del medio se centra en su propia franja del hueco, para que dos
  // filas consecutivas alineen la columna aunque los textos midan distinto.
  const gapStart = first.length
  const gapWidth = width - first.length - last.length
  const chars = row.split("")
  const slot = gapWidth / middles.length
  middles.forEach((cell, idx) => {
    const slotStart = gapStart + slot * idx
    // Un espacio de guarda a cada lado: sin esto un unitario ancho puede
    // quedar pegado a la cantidad y leerse como un solo número.
    const at = Math.round(slotStart + (slot - cell.length) / 2)
    const from = Math.min(Math.max(at, gapStart + 1), width - last.length - cell.length - 1)
    for (let k = 0; k < cell.length; k++) chars[from + k] = cell[k]
  })
  return chars.join("")
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
  /**
   * Alto reservado en la grilla, en FILAS — el alto del bloque en el canvas.
   * Los renderers dibujan el gráfico DE ese alto y saltean las filas
   * reservadas: sin esto, el hueco del canvas y la imagen se sumaban (el
   * canvas reservaba N filas Y la imagen media lo suyo) y el ticket mostraba
   * el doble de espacio que el editor (reporte del owner 2026-08-29).
   */
  rows: number
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

/** Texto lógico de un bloque que NO es de ítem ni gráfico. `width` es el ancho
 *  del bloque EN CARACTERES: el listado de ítems lo necesita para repartir sus
 *  columnas (`distributeRow`); el resto wrapea después contra el mismo número. */
function blockTextLines(block: PrintBlock, data: TicketData, width: number): string[] {
  if (block.type === "payment_methods") {
    return resolvePaymentLines(block, data)
  }
  if (ITEM_TABLE_TYPES.has(block.type)) {
    const lines: string[] = []
    for (const item of data.items) {
      lines.push(ticketItemName(item))
      // Cantidad sin la `x`: "1,5 Azúcar por kilo" son 1,5 kilos, no "1,5
      // veces" — y en un rollo de 57 mm cada carácter cuenta (pedido owner
      // 2026-08-26). `formatQty` respeta los separadores del tenant.
      const cells = itemTableCells(block, item, data)
      if (cells.length) lines.push(distributeRow(cells, width))
    }
    return lines
  }
  const resolver = BLOCK_VALUE_RESOLVERS[block.type]
  if (resolver) {
    // `resolveSimpleBlock` = resolver + título de la plantilla, en un solo
    // lugar para los tres renderers (ver blocks.ts).
    const value = resolveSimpleBlock(block, data)
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
    Math.min(
      geo.columns - ROLL_MARGIN_COLS - 1,
      Math.max(ROLL_MARGIN_COLS, ROLL_MARGIN_COLS + Math.round(px / geo.charWidthPx)),
    )
  const toRow = (px: number) => Math.max(0, Math.round(px / geo.lineHeightPx))
  const toWidth = (px: number, col: number) =>
    Math.max(
      1,
      Math.min(geo.columns - ROLL_MARGIN_COLS - col, Math.round(px / geo.charWidthPx)),
    )
  const toRows = (px: number) => Math.max(1, Math.round(px / geo.lineHeightPx))

  let pushRows = 0
  /**
   * Crecimiento del grupo de bloques que comparten `top`. `null` = ningún
   * bloque contribuyó aún. Puede ser NEGATIVO: un bloque sin contenido NI
   * título devuelve sus filas reservadas (pedido del owner 2026-09-04 — el
   * dato ausente no deja renglones en blanco). Se toma el MÁXIMO del grupo,
   * así que basta UN bloque con contenido en la misma altura para que la
   * fila se conserve; solo colapsa cuando el grupo entero quedó vacío.
   */
  let pendingGrowth: number | null = null
  const contribute = (delta: number) => {
    pendingGrowth = pendingGrowth === null ? delta : Math.max(pendingGrowth, delta)
  }
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

  /** Primera fila desde `row0` donde un gráfico de `height` filas entra sin
   *  pisar contenido — mismo criterio que `place`, pero a lo ancho del papel
   *  entero (un gráfico no comparte fila con texto). Necesario porque el
   *  colapso de filas vacías puede correr un gráfico sobre filas ya escritas. */
  const freeRowFor = (row0: number, height: number) => {
    let row = row0
    while (!canvas.free(row, 0, geo.columns, height)) row++
    return row
  }

  let i = 0
  while (i < blocks.length) {
    const block = blocks[i]

    // Cambio de altura en el canvas → recién ahí se aplica el crecimiento
    // acumulado por los bloques de la altura anterior (ver docblock).
    if (lastTop !== null && block.top > lastTop) {
      pushRows += pendingGrowth ?? 0
      pendingGrowth = null
    }
    lastTop = block.top

    const col = toCol(block.left)
    const width = toWidth(block.width, col)
    // `pushRows` puede ser negativo (colapso de filas vacías): la fila nunca
    // baja de 0, y si cae sobre contenido ya escrito, `place`/`freeRowFor`
    // la corren hacia abajo hasta donde entre.
    const row0 = Math.max(0, toRow(block.top) + pushRows)
    const reserved = toRows(block.height)

    // ── Líneas ────────────────────────────────────────────────────────────
    // En una grilla de caracteres SÍ existen columnas, así que `ver_line`
    // deja de ser el no-op que era en ESC/POS: es una tira de caracteres.
    // Se dibujan con los caracteres de caja de CP437 (`─` 0xC4, `│` 0xB3) y
    // no con '-'/'|': en el papel salen como línea CONTINUA, sin los cortes
    // entre guiones (pedido del owner 2026-09-04). CP437 es la codepage
    // default universal de ESC/POS — el encoder los traduce siempre.
    const geoLine = lineGeometry(block)
    if (geoLine) {
      if (geoLine.orientation === "horizontal") {
        const len = toWidth(geoLine.length, col)
        const row = row0 + Math.round(geoLine.crossOffset / geo.lineHeightPx)
        place(row, col, len, ["─".repeat(len)])
      } else {
        const rows = toRows(geoLine.length)
        const c = Math.min(geo.columns - 1, col + Math.round(geoLine.crossOffset / geo.charWidthPx))
        place(row0, c, 1, new Array<string>(rows).fill("│"))
      }
      contribute(0)
      i++
      continue
    }

    // ── Gráficos (no caben en la grilla — ver docblock) ───────────────────
    // Un gráfico SIN dato (logo no cargado, venta sin CDC) devuelve sus filas
    // reservadas igual que un bloque de texto vacío — `contribute(-reserved)`.
    if (block.type === "company_logo") {
      // El logo sale del TENANT (TicketData.companyLogoUrl ← PosConfig): el
      // bloque solo dice DÓNDE va. `block.url` queda como override por
      // plantilla (legacy) — nadie lo escribe desde el editor hoy.
      const logoUrl = block.url || data.companyLogoUrl || ""
      if (!logoUrl) {
        contribute(-reserved)
        i++
        continue
      }
      const row = freeRowFor(row0, reserved)
      canvas.reserve(row, reserved)
      graphics.push({ kind: "logo", row, rows: reserved, align: block.align, value: logoUrl })
      contribute(0)
      i++
      continue
    }
    if (block.type === "transaction_id_barcode") {
      if (!data.transactionId) {
        contribute(-reserved)
        i++
        continue
      }
      const row = freeRowFor(row0, reserved)
      canvas.reserve(row, reserved)
      graphics.push({ kind: "barcode", row, rows: reserved, align: block.align, value: data.transactionId })
      contribute(0)
      i++
      continue
    }
    if (block.type === "fe_py") {
      // Sin link (venta sin documento electrónico) no se imprime nada, ni el
      // rótulo — el dato no existe, igual que cualquier bloque sin valor.
      if (data.einvoiceUrl) {
        const row = freeRowFor(row0, reserved)
        canvas.reserve(row, reserved)
        graphics.push({
          kind: "qrcode",
          row,
          rows: reserved,
          align: block.align,
          value: data.einvoiceUrl,
          caption: cased(block.text?.trim() || "Consultá tu factura electrónica"),
        })
        contribute(0)
      } else {
        contribute(-reserved)
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
          const value = resolver ? resolver(item, data, rb) ?? "" : ""
          const lines = value
            ? wrapToWidth(cased(value), rbWidth).map((l) => alignInBox(l, rbWidth, rb.align))
            : []
          return { col: rbCol, width: rbWidth, lines, bold: rb.bold === "bold" }
        })
        // El bloque de listado es DINAMICO: el canvas posiciona su INICIO, y
        // cada item ocupa las filas que su contenido REALMENTE necesita. El
        // cursor avanza hasta la fila mas baja que ocupo la fila entera —
        // usando lo que `place` DEVUELVE, no lo que se le pidio: si una celda
        // tuvo que bajar para no pisar a otra, ignorar eso dejaba el avance
        // corto y el item siguiente se montaba encima, en cascada.
        let bottom = cursor + stepRows
        for (const cell of cells) {
          const placed = place(cursor, cell.col, cell.width, cell.lines, cell.bold)
          if (placed.height > 0) bottom = Math.max(bottom, placed.row + placed.height)
        }
        cursor = bottom
      }
      // El canvas reservaba UNA fila para el grupo; con N items (y su wrap)
      // ocupa `cursor - row0`.
      contribute(Math.max(0, cursor - row0 - stepRows))
      continue
    }

    // ── Texto ─────────────────────────────────────────────────────────────
    const logical = blockTextLines(block, data, width).map(cased)
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
    // Bloque sin contenido NI título (`resolveSimpleBlock` ya sumó el título
    // si existía): devuelve sus filas reservadas — la línea vacía no sale y
    // tampoco deja el hueco (pedido del owner 2026-09-04).
    if (lines.length === 0) {
      contribute(-reserved)
      i++
      continue
    }
    const aligned = lines.map((l) => alignInBox(l, width, block.align))
    const placed = place(row0, col, width, aligned, block.bold === "bold")
    // Crecimiento REAL medido contra donde el bloque TERMINO, no contra la
    // fila que pidio el canvas: `place` pudo haberlo bajado para no pisar a
    // otro bloque, y medir contra `row0` dejaba el empuje corto.
    const bottom = placed.row + placed.height
    contribute(Math.max(0, bottom - (row0 + reserved)))
    i++
  }

  return { columns: geo.columns, rows: canvas.toRows(), graphics }
}

/** ¿Esta plantilla va por el camino de rollo? Mismo criterio que ya usan el
 *  editor y los renderers (`isReceipt`). */
export function isRollTemplate(pageSize: PaperSize): boolean {
  return isReceipt(pageSize)
}
