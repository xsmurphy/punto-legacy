import ReceiptPrinterEncoder from "@point-of-sale/receipt-printer-encoder"
import type { PrintTemplateConfig } from "@/lib/types/print-template"
import type { TicketData } from "./build-ticket-data"
import { buildRollGrid, rollGeometry, type PaperWidthMm, type RollGraphic } from "./roll-grid"
import { BRAND_FOOTER_TEXT } from "./blocks"

/**
 * Líneas en blanco que se avanzan ANTES del corte. La cuchilla de una térmica
 * está 10-20mm por ENCIMA del cabezal: sin este avance, las últimas líneas
 * impresas quedan del lado equivocado del corte y el ticket sale trunco
 * (reporte del owner 2026-09-04). 4 líneas Font A ≈ 12mm. El encoder se
 * construye sin `printerModel`, así que su `feedBeforeCut` interno es 0 y el
 * avance tiene que ser explícito.
 */
const FEED_LINES_BEFORE_CUT = 4

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Encoder = any

/**
 * ESC/POS de una plantilla — emisor de la MISMA grilla de caracteres que usa
 * la vista previa y la impresión por navegador (`roll-grid.ts`).
 *
 * Antes este archivo tenía su propia interpretación de la plantilla: recorría
 * los bloques en orden de lectura y emitía una línea por bloque, DESCARTANDO
 * `top`/`left` del canvas. Era la tercera implementación de "dónde va cada
 * cosa" (canvas, HTML, ESC/POS), y las tres daban resultados distintos. Ahora
 * la geometría se resuelve UNA vez en `buildRollGrid` y acá solo se traduce a
 * bytes: cada fila de la grilla es una línea de la impresora.
 *
 * Consecuencia buscada: lo que el operador ve en el editor es lo que sale por
 * la térmica, incluido el corte de línea por ancho de papel (decisión del
 * owner 2026-08-24).
 *
 * Lo que NO se traduce a caracteres son el logo, el código de barras y el QR:
 * son comandos gráficos, y la grilla los devuelve como `RollGraphic` anclados
 * a una fila (ver docblock de roll-grid.ts). Se emiten intercalados en esa
 * posición, respetando la alineación del bloque pero no su columna — una
 * térmica no sabe poner un QR en la columna 17.
 */

/**
 * Emite un texto de la grilla separando ESPACIOS de CONTENIDO.
 *
 * Los espacios van por `raw()` (bytes 0x20 crudos) y NUNCA por `text()`.
 * `text()` pasa por `TextWrap.wrap` de la librería, que descarta un chunk de
 * espacios mientras la línea todavía está vacía:
 *
 *     if (chunk.match(/\s+/) && length == 0) continue
 *
 * Con `text("      Gs 23.000")` la térmica imprimía "Gs 23.000" pegado a la
 * izquierda mientras la vista previa lo mostraba a la derecha — la MISMA
 * divergencia editor/papel que este trabajo vino a eliminar, reaparecida un
 * nivel más abajo, en el encoder. Toda fila centrada o alineada a la derecha
 * salía mal.
 *
 * El contenido sí necesita `text()`: es lo que traduce a la codepage de la
 * impresora ("é" → 0x82 en CP437). Como ningún chunk que llega a `text()`
 * empieza con espacio, TextWrap no tiene nada que descartar; y como la grilla
 * ya cortó cada fila al ancho del papel, tampoco tiene nada que wrapear — el
 * doble-wrap (grilla + librería) deja de ser posible.
 */
function emitSegments(encoder: Encoder, text: string): Encoder {
  let e = encoder
  for (const segment of text.split(/(\s+)/)) {
    if (segment === "") continue
    if (/^\s+$/.test(segment)) e = e.raw(new Array(segment.length).fill(0x20))
    else e = e.text(segment)
  }
  return e
}

/** Alineación de un gráfico, aplicada y revertida alrededor del comando. */
function withAlign(encoder: Encoder, align: RollGraphic["align"], fn: (e: Encoder) => Encoder): Encoder {
  const needsAlign = align === "center" || align === "right"
  let e = encoder
  if (needsAlign) e = e.align(align)
  e = fn(e)
  if (needsAlign) e = e.align("left")
  return e
}

/**
 * Carga los logos de la plantilla ANTES de encodear — el encoder necesita el
 * `HTMLImageElement` decodificado, y decodificar es asíncrono. Un logo que no
 * carga (S3 caído, URL rota, sin red) devuelve null y el ticket sale SIN él:
 * la emisión es offline-first y una imagen nunca puede frenar un comprobante.
 */
async function loadLogoImages(graphics: RollGraphic[]): Promise<Map<string, HTMLImageElement>> {
  const urls = [...new Set(graphics.filter((g) => g.kind === "logo" && g.value).map((g) => g.value))]
  const out = new Map<string, HTMLImageElement>()
  await Promise.all(
    urls.map(async (url) => {
      try {
        const img = new Image()
        // El logo vive en S3 público en otro origen: sin `crossOrigin`, el
        // canvas con el que el encoder rasteriza queda "tainted" y getImageData
        // tira SecurityError — el logo cargaría y aun así no se imprimiría.
        img.crossOrigin = "anonymous"
        img.src = url
        await img.decode()
        out.set(url, img)
      } catch (err) {
        console.error("[render-template] logo no cargó, el ticket sale sin él:", url, err)
      }
    }),
  )
  return out
}

function renderGraphic(
  encoder: Encoder,
  g: RollGraphic,
  columns: number,
  logos: Map<string, HTMLImageElement>,
): Encoder {
  if (g.kind === "logo") {
    const img = g.value ? logos.get(g.value) : undefined
    if (!img) return encoder
    // El ALTO manda y es el del bloque en el canvas: `rows` filas de 24 puntos
    // (celda Font A, 12x24) — así el papel mide lo que el operador dibujó, no
    // lo que mida el archivo. El ancho sale del aspecto, capado al papel (12
    // puntos por columna). El encoder exige múltiplos de 8 en ambos ejes.
    // `atkinson` es el dithering que convierte el logo a BLANCO Y NEGRO puro —
    // la térmica no tiene grises, y un threshold pelado convierte cualquier
    // gris medio en una mancha.
    const targetH = Math.max(8, (g.rows * 24) & ~7)
    const maxDots = columns * 12
    let width = Math.round((img.naturalWidth / img.naturalHeight) * targetH)
    let height = targetH
    if (width > maxDots) {
      height = Math.max(8, Math.round((img.naturalHeight / img.naturalWidth) * maxDots) & ~7)
      width = maxDots
    }
    width = Math.max(8, width & ~7)
    return withAlign(encoder, g.align, (e) => e.image(img, width, height, "atkinson"))
  }
  if (g.kind === "barcode") {
    return withAlign(encoder, g.align, (e) => e.barcode(g.value, "code128", { height: 60 }))
  }
  // QR del portal de consulta del comprador (`fe_py`). errorlevel 'm' (~15%):
  // el ticket térmico se arruga y se borronea, 'l' (7%) falla al escanear en
  // papel maltratado y 'q'/'h' agrandan el QR más de lo que entra en 58mm.
  return withAlign(encoder, g.align, (e) => {
    let out = e.qrcode(g.value, { model: 2, size: 6, errorlevel: "m" })
    if (g.caption) out = out.line(g.caption)
    return out
  })
}

export async function renderTemplateToEscPos(opts: {
  template: PrintTemplateConfig
  data: TicketData
  paperWidthMm: PaperWidthMm
  openDrawer: boolean
  copies: number
}): Promise<Uint8Array> {
  const { template, data, paperWidthMm, openDrawer, copies } = opts

  // La grilla se arma con las columnas del DISPOSITIVO (`paperWidthMm`), no
  // con las del papel de diseño — ver `rollGeometry`.
  const mmRatio = template.mm && template.mm > 0 ? template.mm : 3.78
  const geo = rollGeometry(template.page_size, mmRatio, paperWidthMm)
  const grid = buildRollGrid(template, data, geo)

  const logos = await loadLogoImages(grid.graphics)

  let encoder: Encoder = new ReceiptPrinterEncoder({ columns: geo.columns })
  encoder = encoder.initialize()

  const byRow = new Map<number, RollGraphic[]>()
  // Filas reservadas por un gráfico: el gráfico YA imprime ese alto — no se
  // emiten como renglones en blanco (duplicaba el hueco; mismo criterio que
  // el HTML, ver renderRollBody).
  const reservedRows = new Set<number>()
  for (const g of grid.graphics) {
    const list = byRow.get(g.row)
    if (list) list.push(g)
    else byRow.set(g.row, [g])
    for (let r = g.row; r < g.row + g.rows; r++) reservedRows.add(r)
  }

  for (let r = 0; r < grid.rows.length; r++) {
    const graphics = byRow.get(r)
    if (graphics) {
      for (const g of graphics) encoder = renderGraphic(encoder, g, geo.columns, logos)
      byRow.delete(r)
    }
    if (reservedRows.has(r) && grid.rows[r].text.trim() === "") continue
    // Una fila de la grilla = una línea impresa. La negrita viaja por tramos
    // (`RollRun`): es el único atributo que ESC/POS ya aplicaba antes de la
    // grilla y se conserva. Doble alto/ancho entrarían por el mismo lugar el
    // día que se implementen (pedido del owner, marcado como no urgente).
    const row = grid.rows[r]
    if (row.runs.length === 0) {
      encoder = encoder.newline()
      continue
    }
    for (const run of row.runs) {
      if (run.bold) encoder = encoder.bold(true)
      encoder = emitSegments(encoder, run.text)
      if (run.bold) encoder = encoder.bold(false)
    }
    encoder = encoder.newline()
  }

  // Gráficos anclados más abajo de la última fila con texto.
  for (const row of [...byRow.keys()].sort((a, b) => a - b)) {
    for (const g of byRow.get(row)!) encoder = renderGraphic(encoder, g, geo.columns, logos)
  }

  // Pie de marca obligatorio: fuera de la plantilla, el tenant no lo puede
  // quitar ni editar (BRAND_FOOTER_TEXT, ver blocks.ts). Va en TODO ticket.
  encoder = encoder.newline()
  encoder = withAlign(encoder, "center", (e) => e.line(BRAND_FOOTER_TEXT))

  if (openDrawer) {
    // ESC/POS open drawer pulse — device 0, on 25ms, off 250ms
    encoder = encoder.pulse(0, 25, 250)
  }

  for (let n = 0; n < FEED_LINES_BEFORE_CUT; n++) encoder = encoder.newline()
  encoder = encoder.cut()

  const singleBuffer: Uint8Array = encoder.encode()

  if (copies <= 1) return singleBuffer

  const parts = Array(copies).fill(singleBuffer) as Uint8Array[]
  const totalBytes = parts.reduce((s, b) => s + b.byteLength, 0)
  const out = new Uint8Array(totalBytes)
  let offset = 0
  for (const p of parts) {
    out.set(p, offset)
    offset += p.byteLength
  }
  return out
}
