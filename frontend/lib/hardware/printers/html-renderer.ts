import { isReceipt, PAPER_DIMENSIONS, type PrintTemplateConfig, type PrintBlock } from "@/lib/types/print-template"
import type { TicketData } from "./build-ticket-data"
import {
  BLOCK_VALUE_RESOLVERS,
  ITEM_FIELD_RESOLVERS,
  ITEM_LINE_TYPES,
  ITEM_TABLE_TYPES,
  formatMoney,
  itemTableColumns,
  sortBlocksForRender,
  ticketItemName,
} from "./blocks"

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
        (cols.qty ? `<td style="text-align:right">${item.qty}</td>` : "") +
        (cols.unitPrice ? `<td style="text-align:right">${esc(formatMoney(item.unitPrice, data))}</td>` : "") +
        (cols.total ? `<td style="text-align:right">${esc(formatMoney(item.total, data))}</td>` : "")
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
  const align = blockAlign(block)

  switch (block.type) {
    case "hor_line":
      return `<hr style="border:none;border-top:1px dashed #000;margin:4px 0"/>`

    case "ver_line":
      // A diferencia de ESC/POS (no-op), en HTML sí tiene sentido dibujar
      // una línea vertical real (el fallback de navegador no está limitado
      // a un rollo monocolumna).
      return `<div style="display:inline-block;border-left:1px solid #000;height:1em;margin:0 4px"></div>`

    case "company_name":
      return `<div style="${align};font-weight:bold">${esc(data.companyName)}</div>`

    case "total":
      return `<div style="${align};font-weight:bold">${esc(formatMoney(data.total, data))}</div>`

    case "payment_methods": {
      return data.payments
        .map((p) => `<div${styleAttr}>${esc(p.method)}: ${esc(formatMoney(p.amount, data))}</div>`)
        .join("")
    }

    default:
      break
  }

  if (ITEM_TABLE_TYPES.has(block.type)) {
    return renderItemTable(block, data)
  }

  const resolver = BLOCK_VALUE_RESOLVERS[block.type]
  if (resolver) {
    const value = resolver(data, block)
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
  const value = resolver(item, data)
  return `<div>${esc(value ?? "")}</div>`
}

/** Banner de comanda ("COMANDA #12 · MESA 3") — mismo forzado que
 *  render-template.ts (ESC/POS): el destino de la comanda no depende de que
 *  la plantilla del binding tenga un bloque para eso. Es contenido sintético
 *  sin coordenadas de canvas, así que en AMBOS layouts (rollo y hoja) va en
 *  flujo normal, antes del cuerpo de la plantilla — nunca como bloque
 *  posicionado. */
function renderOrderBanner(data: TicketData): string {
  if (!(data.docType === "order" && data.orderDestination)) return ""
  return `<div style="text-align:center;font-weight:bold">COMANDA #${esc(data.ticketNo ?? "—")} · ${esc(data.orderDestination.toUpperCase())}</div>`
}

/**
 * Cuerpo de TICKET (rollo 57/76/80mm) — flujo lineal, un `<div>` por bloque
 * en orden de lectura (`sortBlocksForRender`). Un rollo no tiene columnas
 * reales: `ver_line` es no-op explícito del lado ESC/POS
 * (`render-template.ts:63-67`) y acá se sigue el mismo criterio de "una
 * línea, un dato" — SIN cambios respecto de antes de F(hoja posicional).
 */
function renderTicketBody(blocks: PrintBlock[], data: TicketData): string {
  const banner = renderOrderBanner(data)
  const parts: string[] = banner ? [banner] : []

  let i = 0
  while (i < blocks.length) {
    const block = blocks[i]
    if (ITEM_LINE_TYPES.has(block.type)) {
      const groupStart = i
      while (i < blocks.length && ITEM_LINE_TYPES.has(blocks[i].type)) {
        i++
      }
      const itemBlocks = blocks.slice(groupStart, i)
      for (const item of data.items) {
        for (const ib of itemBlocks) {
          parts.push(renderItemFieldHtml(ib, item, data))
        }
      }
    } else {
      parts.push(renderBlockHtml(block, data))
      i++
    }
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
 * producto). El paso por ítem de CADA bloque usa SU PROPIO `height` (no el
 * máximo del grupo) — mismo criterio que ya usa `PreviewDialog`
 * (preview-dialog.tsx, `rb.top + itemIdx * rb.height`), así que una columna
 * con alto distinto a sus vecinas crece igual acá que en la Vista Previa que
 * el owner ya validó. Lo que SÍ es nuevo acá (`PreviewDialog` no lo hace: es
 * una limitación conocida y aceptada de esa vista, no algo que este fix deba
 * resolver) es empujar hacia abajo lo que viene DESPUÉS del grupo — ahí se
 * usa el `Math.max` de alturas del grupo (no el alto de un bloque puntual)
 * para no subestimar cuánto espacio ocupó la fila más alta y solapar
 * subtotales/liquidación de impuestos que vienen debajo en el canvas.
 *
 * `item_receipt*` (ITEM_TABLE_TYPES) es un único bloque que arma el listado
 * COMPLETO — su alto real depende de cuántos ítems tenga la venta, así que
 * NO participa del empuje: se posiciona en su `top` original con
 * `overflow: visible`, igual que ya hace `PreviewDialog` (kind "table") — si
 * el listado es más largo que el hueco que el owner le dejó en el editor,
 * se desborda visualmente ahí también. Es la misma limitación del modelo de
 * canvas de una sola página, no algo que este fix introduce ni resuelve.
 */
function renderSheetBody(blocks: PrintBlock[], data: TicketData, mmRatio: number): string {
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
  ) => {
    const overflow = overflowVisible ? "visible" : "hidden"
    const whiteSpace = overflowVisible ? "pre-wrap" : "nowrap"
    const textOverflow = overflowVisible ? "" : "text-overflow:clip;"
    return `<div style="position:absolute;top:${px(top)};left:${px(left)};width:${px(width)};height:${px(height)};overflow:${overflow};white-space:${whiteSpace};${textOverflow}">${innerHtml}</div>`
  }

  const parts: string[] = []
  let pushDown = 0
  let i = 0
  while (i < blocks.length) {
    const block = blocks[i]

    if (ITEM_LINE_TYPES.has(block.type)) {
      const groupStart = i
      while (i < blocks.length && ITEM_LINE_TYPES.has(blocks[i].type)) i++
      const rowBlocks = blocks.slice(groupStart, i)
      const rowHeight = Math.max(...rowBlocks.map((b) => b.height), 1)
      data.items.forEach((item, itemIdx) => {
        for (const rb of rowBlocks) {
          parts.push(
            positioned(
              // Paso por SU PROPIO alto (no el máximo del grupo) — ver
              // docblock de la función, mismo criterio que PreviewDialog.
              rb.top + pushDown + itemIdx * rb.height,
              rb.left,
              rb.width,
              rb.height,
              rb.textwrap === "wrap",
              renderItemFieldHtml(rb, item, data),
            ),
          )
        }
      })
      // El canvas ya reservaba el alto de UNA fila para este grupo — con N
      // ítems el bloque ocupa N filas, así que lo que sigue se empuja
      // (N-1) filas (0 ítems colapsa el hueco reservado).
      pushDown += (data.items.length - 1) * rowHeight
      continue
    }

    if (ITEM_TABLE_TYPES.has(block.type)) {
      parts.push(
        positioned(block.top + pushDown, block.left, block.width, block.height, true, renderItemTable(block, data)),
      )
      i++
      continue
    }

    parts.push(
      positioned(
        block.top + pushDown,
        block.left,
        block.width,
        block.height,
        block.textwrap === "wrap",
        renderBlockHtml(block, data),
      ),
    )
    i++
  }

  // El banner (cuando aplica) va EN FLUJO, arriba del canvas absoluto — su
  // propia altura corre el canvas hacia abajo esa misma cantidad respecto de
  // lo que el owner vio en el editor (que no conoce el banner, es contenido
  // sintético). Edge case aceptado: una comanda normalmente imprime a
  // rollo/impresora de cocina, no a una plantilla de hoja — no se resuelve
  // acá para no inventar un offset "mágico" sin un caso real que lo pida.
  const banner = renderOrderBanner(data)
  const canvas = `<div style="position:relative;width:100%;height:100%">${parts.join("\n")}</div>`
  return banner ? `${banner}\n${canvas}` : canvas
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
  paperWidthMm?: 58 | 80
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

  // Rollo (57/76/80mm): flujo lineal SIN cambios — ver docblock de
  // `renderTicketBody`. Hoja (A4/Legal/Carta): layout posicional — ver
  // docblock de `renderSheetBody`. `isReceipt`/`PAPER_DIMENSIONS`:
  // lib/types/print-template.ts, mismo criterio que ya usa el editor
  // (canvas-block.tsx) y la Vista Previa (preview-dialog.tsx) para distinguir
  // ambos mundos.
  if (!isReceipt(template.page_size)) {
    // `page_size` viaja en `config` (JSONB abierto, ver context/modules/18):
    // un valor corrupto/legacy que no matchee `PaperSize` no debería tirar la
    // impresión entera — cae a A4 (el default de `defaultTemplateConfig`).
    const dim = PAPER_DIMENSIONS[template.page_size] ?? PAPER_DIMENSIONS.a4page
    const mmRatio = template.mm && template.mm > 0 ? template.mm : 3.78
    const body = renderSheetBody(blocks, data, mmRatio)
    return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
  @page { size: ${dim.widthMm}mm ${dim.heightMm}mm; margin: 0; }
  html, body { margin: 0; padding: 0; }
  body { font-family: '${fontFamily}', monospace; font-size: ${fontSize}; width: ${dim.widthMm}mm; height: ${dim.heightMm}mm; position: relative; }
  @media print { body { margin: 0; } }
  hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 1px 2px; }
</style>
</head>
<body>
${body}
</body>
</html>`
  }

  const body = renderTicketBody(blocks, data)
  const widthMm = options.paperWidthMm
  const widthCss = widthMm ? `width: ${widthMm}mm; margin: 0 auto;` : "margin: 20px;"
  const pageCss = widthMm ? `@page { size: ${widthMm}mm auto; margin: 0; }` : ""

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
  ${pageCss}
  body { font-family: '${fontFamily}', monospace; font-size: ${fontSize}; ${widthCss} }
  @media print { body { margin: 0; } }
  hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 1px 2px; }
</style>
</head>
<body>
${body}
</body>
</html>`
}
