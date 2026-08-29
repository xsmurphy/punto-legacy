import { isReceipt, PAPER_DIMENSIONS, type PrintTemplateConfig, type PrintBlock } from "@/lib/types/print-template"
import type { TicketData } from "./build-ticket-data"
import {
  BLOCK_VALUE_RESOLVERS,
  resolveSimpleBlock,
  ITEM_FIELD_RESOLVERS,
  ITEM_LINE_TYPES,
  ITEM_TABLE_TYPES,
  formatAmountOnly,
  formatQty,
  resolvePaymentLines,
  itemTableColumns,
  lineGeometry,
  sortBlocksForRender,
  ticketItemName,
} from "./blocks"
import type { LineGeometry } from "./blocks"
import {
  buildRollGrid,
  rollFontSizeFor,
  rollGeometry,
  ROLL_FONT_STACK,
  type PaperWidthMm,
  type RollGraphic,
  type RollGrid,
} from "./roll-grid"

function esc(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
}

function blockAlign(block: PrintBlock): string {
  if (block.align === "center") return "text-align:center"
  if (block.align === "right") return "text-align:right"
  return "text-align:left"
}

function blockFont(block: PrintBlock): string {
  const parts: string[] = []
  if (block.bold === "bold") parts.push("font-weight:bold")
  if (block.size && block.size !== "inherit") parts.push(`font-size:${block.size}`)
  return parts.join(";")
}

function blockStyleAttr(block: PrintBlock): string {
  const style = [blockAlign(block), blockFont(block)].filter(Boolean).join(";")
  return style ? ` style="${style}"` : ""
}

/** Listado completo de ítems para `item_receipt*` — columnas según variante
 *  (ver `itemTableColumns` en blocks.ts). */
function renderItemTable(block: PrintBlock, data: TicketData): string {
  const cols = itemTableColumns(block.type)
  const headerCells =
    `<th style="text-align:left">Ítem</th>` +
    (cols.qty ? `<th style="text-align:right">Cant.</th>` : "") +
    (cols.unitPrice ? `<th style="text-align:right">P.Unit</th>` : "") +
    (cols.total ? `<th style="text-align:right">Total</th>` : "")
  const rows = data.items
    .map((item) => {
      const cells =
        // `white-space:pre-wrap`: la indentación de un add-on hijo viaja como
        // espacios en el texto (ticketItemName) para que el ESC/POS y el HTML
        // impriman lo mismo. HTML colapsa espacios repetidos por default y
        // comería justamente esa sangría.
        `<td style="white-space:pre-wrap">${esc(ticketItemName(item))}</td>` +
        // Misma cantidad formateada que el ESC/POS (formatQty, blocks.ts): sin
        // la `x` y con 2 decimales como máximo.
        (cols.qty ? `<td style="text-align:right">${esc(formatQty(item.qty, data))}</td>` : "") +
        (cols.unitPrice ? `<td style="text-align:right">${esc(formatAmountOnly(item.unitPrice, data))}</td>` : "") +
        (cols.total ? `<td style="text-align:right">${esc(formatAmountOnly(item.total, data))}</td>` : "")
      return `<tr>${cells}</tr>`
    })
    .join("")
  return (
    `<table style="width:100%;border-collapse:collapse;font-size:inherit">` +
    `<thead><tr>${headerCells}</tr></thead><tbody>${rows}</tbody></table>`
  )
}

function renderBlockHtml(block: PrintBlock, data: TicketData): string {
  const styleAttr = blockStyleAttr(block)

  // `hor_line`/`ver_line` NO llegan acá: los dos caminos las interceptan
  // antes (la hoja con `positionedLine`, el rollo con la grilla de caracteres
  // de roll-grid.ts). Un `case` para ellas acá sería código muerto que miente
  // sobre quién dibuja una línea.
  // `company_name` y `total` tenían su propio `case` acá, con la negrita
  // forzada y el valor resuelto a mano (`data.companyName`, `formatMoney(
  // data.total)`). Eran dos mentiras al mismo tiempo: la plantilla decía
  // `bold: "normal"` y salía en negrita igual (el renderer decidiendo qué
  // imprime, justo lo que prohíbe context/20), y al pasar por afuera del
  // resolver compartido se habrían quedado sin el título de la plantilla.
  // Ahora caen en el camino común de abajo, con el estilo del bloque.
  if (block.type === "payment_methods") {
    return resolvePaymentLines(block, data)
      .map((line) => `<div${styleAttr}>${esc(line)}</div>`)
      .join("")
  }

  // Logo del TENANT (TicketData.companyLogoUrl ← PosConfig.companyLogo). Solo
  // llega acá en HOJA — el rollo lo intercepta como gráfico (roll-grid.ts).
  // Sin logo cargado, el bloque queda vacío: un "[Logo]" impreso en una
  // factura sería peor que el hueco.
  if (block.type === "company_logo") {
    const url = block.url || data.companyLogoUrl || ""
    return url
      ? `<div${styleAttr}><img src="${esc(url)}" alt="" style="max-width:100%;max-height:100%"/></div>`
      : ""
  }

  if (ITEM_TABLE_TYPES.has(block.type)) {
    return renderItemTable(block, data)
  }

  const resolver = BLOCK_VALUE_RESOLVERS[block.type]
  if (resolver) {
    // Mismo resolver + título que la vista previa y la térmica (blocks.ts).
    const value = resolveSimpleBlock(block, data)
    return `<div${styleAttr}>${esc(value ?? "")}</div>`
  }

  // Tipo realmente no implementado — no descartarlo en silencio (ese
  // silencio fue lo que hizo que este bug llegara a producción).
  console.error("[html-renderer] BlockType desconocido, no implementado:", block.type)
  return `<div${styleAttr} data-missing-block="${esc(block.type)}">[${esc(block.type)}]</div>`
}

function renderItemFieldHtml(block: PrintBlock, item: TicketData["items"][number], data: TicketData): string {
  const resolver = ITEM_FIELD_RESOLVERS[block.type]
  if (!resolver) return ""
  const value = resolver(item, data, block)
  return `<div>${esc(value ?? "")}</div>`
}

/** Gráfico del rollo (logo / código de barras / QR) — no cabe en la grilla de
 *  caracteres, se emite entre filas. Ver docblock de roll-grid.ts. */
function renderRollGraphicHtml(g: RollGraphic, rowHeightMm: number): string {
  const align = `text-align:${g.align === "center" ? "center" : g.align === "right" ? "right" : "left"}`
  if (g.kind === "logo") {
    // El alto es el del BLOQUE en el canvas (`rows` filas), no el natural de
    // la imagen: lo que se dibuja mide lo que el operador dibujó.
    const h = (g.rows * rowHeightMm).toFixed(3)
    return g.value
      ? `<div style="${align};height:${h}mm"><img src="${esc(g.value)}" alt="" style="max-width:100%;height:100%;object-fit:contain"/></div>`
      : ""
  }
  if (g.kind === "barcode") {
    return `<div style="${align}">${esc(g.value)}</div>`
  }
  // El QR real lo dibuja la térmica; en pantalla se muestra el destino y su
  // rótulo, que es la información que el operador necesita verificar.
  const caption = g.caption ? `<div style="${align}">${esc(g.caption)}</div>` : ""
  return `<div style="${align}">[QR] ${esc(g.value)}</div>${caption}`
}

/**
 * Cuerpo de ROLLO (57/76/80mm) — las MISMAS filas de caracteres que se le
 * mandan a la impresora térmica (`buildRollGrid`, roll-grid.ts), pintadas en
 * un `<pre>` monoespaciado.
 *
 * Antes esto era un flujo lineal que DESCARTABA `top`/`left` del canvas: el
 * editor mostraba un layout posicional y ni la vista previa ni el papel se le
 * parecían. Ahora la posición del canvas manda (decisión owner 2026-08-24) y,
 * más importante, el corte de línea NO se calcula acá: viene ya resuelto en la
 * grilla. Por construcción es imposible que la vista previa wrapee distinto
 * que la impresora — que era el bug de origen replicado en otra superficie.
 *
 * La tipografía solo afecta cuánto LLENA el ancho del papel, nunca dónde
 * corta: los saltos ya vienen decididos por columnas.
 */
/**
 * Una fila del rollo: `columns` CELDAS de ancho fijo, un carácter cada una.
 *
 * No es una decoración — es lo que hace que la vista previa no pueda mentir. El
 * `<pre>` anterior confiaba en que la fuente midiera exactamente el avance que
 * asumía la cuenta del tamaño (`rollFontSizeFor`): con la monoespaciada del
 * sistema pintando un pelo más ancho, una línea llena se pasaba del papel y la
 * última columna quedaba cortada contra el borde — y las columnas de la lista
 * de ítems no coincidían entre el editor y la vista previa (reporte del owner
 * 2026-08-28, con capturas).
 *
 * Con una grilla CSS de `columns` fracciones, cada carácter ocupa exactamente
 * 1/columns del papel por CONSTRUCCIÓN, cualquiera sea la fuente que el
 * dispositivo termine usando. El tamaño de letra pasa a ser solo qué tan
 * llena se ve la celda; ya no puede desbordar el ancho.
 */
function renderRollRowHtml(row: RollGrid["rows"][number], columns: number): string {
  const cells: string[] = []
  for (const run of row.runs) {
    // `Array.from` y no `split("")`: los acentos y cualquier carácter fuera del
    // BMP se cuentan como UNA celda, igual que los cuenta la grilla.
    for (const ch of Array.from(run.text)) {
      const glyph = ch === " " ? "&nbsp;" : esc(ch)
      cells.push(run.bold ? `<b>${glyph}</b>` : glyph)
    }
  }
  // Las celdas que faltan (la fila puede ser más corta que el papel) se
  // completan vacías para que la grilla mantenga el ancho.
  while (cells.length < columns) cells.push("")
  return `<div class="r">${cells.map((c) => `<span>${c}</span>`).join("")}</div>`
}

function renderRollBody(grid: RollGrid, rowHeightMm: number): string {
  const byRow = new Map<number, RollGraphic[]>()
  // Filas reservadas por un gráfico: el gráfico YA ocupa ese alto (la imagen
  // se dibuja de `rows` filas), así que estas filas NO se emiten como
  // renglones en blanco — emitirlas duplicaba el hueco del canvas.
  const reservedRows = new Set<number>()
  for (const g of grid.graphics) {
    const list = byRow.get(g.row)
    if (list) list.push(g)
    else byRow.set(g.row, [g])
    for (let r = g.row; r < g.row + g.rows; r++) reservedRows.add(r)
  }

  const parts: string[] = []
  let buffer: string[] = []
  const flush = () => {
    if (!buffer.length) return
    parts.push(buffer.join(""))
    buffer = []
  }

  for (let r = 0; r < grid.rows.length; r++) {
    const graphics = byRow.get(r)
    if (graphics) {
      flush()
      for (const g of graphics) parts.push(renderRollGraphicHtml(g, rowHeightMm))
      byRow.delete(r)
    }
    // Fila reservada por un gráfico y sin texto propio: el gráfico ya puso el
    // alto — no emitir un renglón en blanco encima.
    if (reservedRows.has(r) && grid.rows[r].text.trim() === "") continue
    // La negrita viaja por tramos (`RollRun`) — el mismo atributo que ESC/POS
    // le pasa al encoder, así que el preview la muestra donde el papel la va
    // a tener.
    buffer.push(renderRollRowHtml(grid.rows[r], grid.columns))
  }
  flush()

  // Gráficos anclados más abajo de la última fila con texto (el operador los
  // puso al final del canvas): van al cierre, en orden de fila.
  for (const row of [...byRow.keys()].sort((a, b) => a - b)) {
    for (const g of byRow.get(row)!) parts.push(renderRollGraphicHtml(g, rowHeightMm))
  }

  return parts.join("\n")
}

/**
 * Cuerpo de HOJA (A4/Legal/Carta) — layout POSICIONAL, respetando
 * `block.top`/`block.left` del canvas (bug: antes se ignoraban y todo salía
 * en una sola columna angosta, un dato por línea). Reusa el MISMO contenido
 * por bloque que el ticket (`renderBlockHtml`/`renderItemFieldHtml`/
 * `renderItemTable`, vía blocks.ts) — la única diferencia es CÓMO se
 * posiciona ese contenido en la página, no cómo se resuelve el valor.
 *
 * Coordenadas convertidas de px (unidad del editor) a mm (unidad física del
 * papel) con `template.mm` — el mismo ratio px→mm que calculó el editor al
 * montar (`lib/types/print-template.ts` `PrintTemplateConfig.mm`). Usar mm
 * en vez de px en el CSS de impresión es deliberado: `@media print` puede
 * escalar `px` distinto según el motor de impresión del browser/SO, pero
 * `mm` se ancla al tamaño físico real del `@page`, que también se define en
 * mm — así el bloque cae en la misma posición relativa que el owner vio en
 * el editor, sin importar el DPI de impresión.
 *
 * Las filas de ítems (`ITEM_LINE_TYPES`) son la única sección que CRECE:
 * comparten un `top` (una "fila plantilla" que el motor repite una vez por
 * producto). El paso por ítem de TODO el grupo usa `rowHeight`, el alto
 * MÁXIMO entre los bloques de la fila — nunca el alto propio de un bloque
 * puntual. Bug corregido acá (antes usaba `itemIdx * rb.height`, el alto
 * de CADA bloque por separado): si "Producto" tenía más alto que "Precio"
 * (fila pensada para nombres largos, columnas de importe angostas de una
 * sola línea), cada columna avanzaba a un ritmo distinto — la fila se
 * desarmaba por ítem, con columnas altas empujando su contenido lejos de
 * sus vecinas y generando el vacío gigante entre ítems que reportó el
 * owner. `rowHeight` uniforme mantiene TODOS los campos de una misma fila
 * pegados entre sí, ítem tras ítem — así se ve una fila, no una fila que se
 * desarma. La vista previa en pantalla ya no tiene su propia copia de este
 * cálculo (ver `PreviewDialog`, que ahora renderiza este mismo HTML en un
 * iframe) — un solo lugar donde este bug puede existir o corregirse.
 *
 * `item_receipt*` (ITEM_TABLE_TYPES) es un único bloque que arma el listado
 * COMPLETO — su alto real depende de cuántos ítems tenga la venta, así que
 * NO participa del empuje: se posiciona en su `top` original con
 * `overflow: visible`, igual que ya hace `PreviewDialog` (kind "table") — si
 * el listado es más largo que el hueco que el owner le dejó en el editor,
 * se desborda visualmente ahí también. Es la misma limitación del modelo de
 * canvas de una sola página, no algo que este fix introduce ni resuelve.
 */
/**
 * Alto de una fila de ítem en HOJA, en px de canvas.
 *
 * NO es `block.height`: en una factura de hoja el operador dibuja la REGIÓN
 * del cuerpo de la tabla (la caja alta que va del encabezado a la banda de
 * totales), no una fila. Tomar ese alto como paso de fila mandaba el segundo
 * ítem ~640px abajo y empujaba el pie fuera de la página — el bug que reportó
 * el owner el 2026-08-29.
 *
 * Sale de la tipografía, que es lo único que sí mide una línea. El valor se
 * emite ADEMÁS como `line-height` en cada celda (ver `positionedRow`), así
 * que el avance y lo que el browser dibuja son el mismo número por
 * construcción, en vez de dos aproximaciones que se separan de a poco.
 */
const SHEET_LINE_HEIGHT_RATIO = 1.2
const PT_TO_MM = 25.4 / 72

function sheetRowHeightPx(fontSize: string, mmRatio: number): number {
  const pt = Number.parseFloat(fontSize)
  // `size: 'inherit'` y cualquier valor corrupto del JSONB caen al 8pt que
  // usa `defaultTemplateConfig` — nunca a NaN, que colapsaría todas las filas
  // sobre la primera.
  const safePt = Number.isFinite(pt) && pt > 0 ? pt : 8
  return safePt * PT_TO_MM * mmRatio * SHEET_LINE_HEIGHT_RATIO
}

function renderSheetBody(
  blocks: PrintBlock[],
  data: TicketData,
  mmRatio: number,
  pageFontSize: string,
): string {
  const px = (n: number) => `${(n / mmRatio).toFixed(2)}mm`
  // `overflowVisible` es `block.textwrap === "wrap"` — mismo campo, mismo
  // significado que en canvas-block.tsx/preview-dialog.tsx: "cut" (default)
  // recorta a una línea (`nowrap` + `overflow:hidden` + `text-overflow:clip`,
  // igual que preview-dialog.tsx:307-309), "wrap" deja fluir multilínea.
  const positioned = (
    top: number,
    left: number,
    width: number,
    height: number,
    overflowVisible: boolean,
    innerHtml: string,
    // Solo las filas de ítem lo pasan: fija el alto de línea al MISMO número
    // con el que avanzan, para que el browser no elija uno propio y las filas
    // se vayan separando del paso calculado.
    lineHeightPx?: number,
  ) => {
    const overflow = overflowVisible ? "visible" : "hidden"
    const whiteSpace = overflowVisible ? "pre-wrap" : "nowrap"
    const textOverflow = overflowVisible ? "" : "text-overflow:clip;"
    const lineHeight = lineHeightPx !== undefined ? `line-height:${px(lineHeightPx)};` : ""
    return `<div style="position:absolute;top:${px(top)};left:${px(left)};width:${px(width)};height:${px(height)};overflow:${overflow};white-space:${whiteSpace};${textOverflow}${lineHeight}">${innerHtml}</div>`
  }

  /**
   * Línea (`hor_line`/`ver_line`) en HOJA — un ÚNICO div absoluto pintado con
   * `background`, que NO pasa por `positioned()`.
   *
   * Ese wrapper genérico recorta con `overflow:hidden` a la altura exacta del
   * bloque, y lo que se metía adentro era un `<hr>` con `margin:4px 0` (o un
   * `<div>` con `margin:0 4px` para la vertical): con un bloque angosto el
   * margen empujaba la línea FUERA del recorte y no se imprimía NADA — ni en
   * la simulación ni en el papel, ni desde panel ni desde caja (bug
   * 2026-08-22). Acá no hay contenido que recortar: la línea ES la geometría
   * del div, así que no puede volver a desaparecer.
   *
   * Geometría desde `lineGeometry` (blocks.ts) — el mismo helper con el que
   * dibuja el editor, que es lo que hace que lo que se ve en el canvas sea lo
   * que sale en la hoja.
   */
  const positionedLine = (block: PrintBlock, geo: LineGeometry, top: number): string => {
    const horizontal = geo.orientation === "horizontal"
    // `crossOffset` centra la línea dentro de la caja del bloque (ver
    // docblock de `lineGeometry`): la caja es el hueco que el operador
    // posiciona, la línea va en su centro.
    const y = top + (horizontal ? geo.crossOffset : 0)
    const x = block.left + (horizontal ? 0 : geo.crossOffset)
    const w = horizontal ? geo.length : geo.thickness
    const h = horizontal ? geo.thickness : geo.length
    return `<div style="position:absolute;top:${px(y)};left:${px(x)};width:${px(w)};height:${px(h)};background:#000"></div>`
  }

  const parts: string[] = []
  let i = 0
  while (i < blocks.length) {
    const block = blocks[i]

    // Las líneas se interceptan ANTES del camino genérico — ver
    // `positionedLine`. `lineGeometry` devuelve null para todo lo demás, así
    // que hace de guarda sin repetir acá el switch de tipos.
    const lineGeo = lineGeometry(block)
    if (lineGeo) {
      parts.push(positionedLine(block, lineGeo, block.top))
      i++
      continue
    }

    if (ITEM_LINE_TYPES.has(block.type)) {
      const groupStart = i
      while (i < blocks.length && ITEM_LINE_TYPES.has(blocks[i].type)) i++
      const rowBlocks = blocks.slice(groupStart, i)

      // El modelo de HOJA es el inverso del rollo, y confundirlos fue el bug.
      //
      // En rollo no hay página: el papel crece, el bloque de ítem mide una
      // línea y todo lo que sigue se corre hacia abajo (`pushDown` vive en
      // roll-grid.ts, donde SÍ corresponde). En hoja la página es fija: el
      // operador dibujó la región del cuerpo de la tabla, los ítems se apilan
      // ADENTRO, y el pie se queda donde lo pusieron — la región ya reservó
      // su espacio. Por eso acá nada empuja a nada: cada bloque se imprime en
      // el `top` que tiene guardado, igual que lo muestra el editor (que
      // nunca tuvo lógica de `pushDown`, de ahí que el canvas se viera bien y
      // la vista previa no).
      const rowHeight = sheetRowHeightPx(rowBlocks[0]?.size ?? pageFontSize, mmRatio)
      data.items.forEach((item, itemIdx) => {
        for (const rb of rowBlocks) {
          parts.push(
            positioned(
              rb.top + itemIdx * rowHeight,
              rb.left,
              rb.width,
              // Alto de UNA línea, no el de la región dibujada: si cada celda
              // midiera `rb.height` se solaparían todas entre sí.
              rowHeight,
              rb.textwrap === "wrap",
              renderItemFieldHtml(rb, item, data),
              rowHeight,
            ),
          )
        }
      })
      continue
    }

    if (ITEM_TABLE_TYPES.has(block.type)) {
      parts.push(
        positioned(block.top, block.left, block.width, block.height, true, renderItemTable(block, data)),
      )
      i++
      continue
    }

    parts.push(
      positioned(
        block.top,
        block.left,
        block.width,
        block.height,
        block.textwrap === "wrap",
        renderBlockHtml(block, data),
      ),
    )
    i++
  }

  return `<div style="position:relative;width:100%;height:100%">${parts.join("\n")}</div>`
}

export interface RenderTemplateToHtmlOptions {
  /** Ancho físico del rollo (58/80mm) — solo aplica a impresión de TICKET vía
   *  navegador (`printTicketInBrowser`). Constriñe el body a ese ancho y setea
   *  `@page` para que el diálogo nativo del browser lo centre en la hoja real
   *  (A4/carta/lo que tenga el usuario) como una columna angosta, igual que
   *  saldría de una impresora térmica. Sin esto el fallback imprimía a lo
   *  ancho de A4 completo — inconsistente con lo que sale por ESC/POS. Sin
   *  efecto en plantillas de HOJA (ver `isReceipt` abajo): esas siempre usan
   *  su propio `page_size` físico, nunca este override de ticket. */
  paperWidthMm?: PaperWidthMm
}

export function renderTemplateToHtml(
  template: PrintTemplateConfig,
  data: TicketData,
  options: RenderTemplateToHtmlOptions = {},
): string {
  // Canvas → orden visual: ver `sortBlocksForRender` en blocks.ts.
  const blocks = sortBlocksForRender(template.data ?? [])
  const fontFamily = template.page_font_family ?? "monospace"
  const fontSize = template.page_font_size ?? "8pt"
  // `page_font_case` se aplicaba en el canvas del editor y en NINGÚN renderer:
  // una plantilla en mayúsculas se veía en mayúsculas y salía impresa normal.
  // Misma clase de mentira que la geometría del rollo (2026-08-24).
  const fontCase =
    template.page_font_case === "uppercase" ? "text-transform: uppercase;" : ""

  // Los DOS caminos respetan la geometría del canvas; cambia la unidad en la
  // que se proyecta. Hoja (A4/Legal/Carta): milímetros absolutos, ver
  // `renderSheetBody`. Rollo (57/76/80mm): grilla de caracteres, ver
  // `renderRollBody` + roll-grid.ts. `isReceipt`/`PAPER_DIMENSIONS`:
  // lib/types/print-template.ts, mismo criterio que ya usa el editor
  // (canvas-block.tsx) y la Vista Previa (preview-dialog.tsx) para distinguir
  // ambos mundos.
  if (!isReceipt(template.page_size)) {
    // `page_size` viaja en `config` (JSONB abierto, ver context/modules/18):
    // un valor corrupto/legacy que no matchee `PaperSize` no debería tirar la
    // impresión entera — cae a A4 (el default de `defaultTemplateConfig`).
    const dim = PAPER_DIMENSIONS[template.page_size] ?? PAPER_DIMENSIONS.a4page
    const mmRatio = template.mm && template.mm > 0 ? template.mm : 3.78
    const body = renderSheetBody(blocks, data, mmRatio, fontSize)
    return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
  @page { size: ${dim.widthMm}mm ${dim.heightMm}mm; margin: 0; }
  html, body { margin: 0; padding: 0; }
  body { font-family: '${fontFamily}', monospace; font-size: ${fontSize}; ${fontCase} width: ${dim.widthMm}mm; height: ${dim.heightMm}mm; position: relative; }
  @media print { body { margin: 0; } }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 1px 2px; }
</style>
</head>
<body>
${body}
</body>
</html>`
  }

  // ── Rollo ────────────────────────────────────────────────────────────────
  // La grilla se construye con las columnas del DISPOSITIVO cuando el binding
  // las conoce (`paperWidthMm`), no con las del papel de diseño: una plantilla
  // de 57mm mandada a una térmica de 80mm se reparte sobre 48 columnas.
  const rollMm = template.mm && template.mm > 0 ? template.mm : 3.78
  const geo = rollGeometry(template.page_size, rollMm, options.paperWidthMm)
  const grid = buildRollGrid(template, data, geo)
  const rollRowHeightMmForBody = ((options.paperWidthMm ?? PAPER_DIMENSIONS[template.page_size].widthMm) / geo.columns) * 2
  const body = renderRollBody(grid, rollRowHeightMmForBody)

  const widthMm = options.paperWidthMm ?? PAPER_DIMENSIONS[template.page_size].widthMm
  // Tamaño de fuente para que `columns` caracteres llenen el ancho del papel.
  // La fórmula vive en roll-grid.ts: la comparte con el canvas del editor, para
  // que las dos superficies muestren la MISMA densidad de caracteres.
  const rollFontSize = `${rollFontSizeFor(widthMm, geo.columns).toFixed(3)}mm`
  // Alto de fila en mm — la MISMA relación 2:1 con la celda que usa la grilla
  // (`lineHeightPx = charWidthPx * ESC_POS_CELL_ASPECT`), así una fila del
  // canvas mide en papel lo que mide en pantalla.
  const rollRowHeightMm = (widthMm / geo.columns) * 2

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
  @page { size: ${widthMm}mm auto; margin: 0; }
  html, body { margin: 0; padding: 0; }
  /* Monoespaciada SIEMPRE, ignorando \`page_font_family\`: ver ROLL_FONT_STACK
     (roll-grid.ts). La térmica imprime celdas de ancho fijo; con la fuente de
     la plantilla adelante en el stack, las columnas que la grilla centró y
     cortó se pintaban de anchos distintos y el texto se desbordaba del papel. */
  body { font-family: ${ROLL_FONT_STACK}; font-size: ${rollFontSize}; width: ${widthMm}mm; margin: 0 auto; }
  /* Una fila = \`columns\` celdas de 1fr. El ancho de cada carácter sale de
     DIVIDIR el papel, no de la métrica de la fuente: por construcción una
     línea llena mide exactamente el ancho del papel y no puede desbordar.
     \`overflow: hidden\` en la celda es el último seguro — si el glifo de una
     fuente rara midiera más que su celda, se recorta ahí y no corre la fila
     entera. */
  .r {
    display: grid;
    grid-template-columns: repeat(${geo.columns}, 1fr);
    width: 100%;
    /* Alto EXPLÍCITO de fila = 2 celdas (Font A de ESC/POS es 2:1). Sin esto,
       una fila VACÍA —celdas sin contenido— colapsaba a alto 0 y las líneas en
       blanco que el operador dejó en el canvas desaparecían del papel: todo
       salía pegado (reporte del owner 2026-08-28). Cinco filas vacías en el
       canvas TIENEN que ser cinco renglones de papel en blanco. */
    height: ${rollRowHeightMm.toFixed(4)}mm;
    line-height: ${rollRowHeightMm.toFixed(4)}mm;
  }
  .r > span { overflow: hidden; white-space: pre; }
  @media print { body { margin: 0; } }
</style>
</head>
<body>
${body}
</body>
</html>`
}
