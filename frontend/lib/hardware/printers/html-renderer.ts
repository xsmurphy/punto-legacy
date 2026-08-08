import type { PrintTemplateConfig, PrintBlock } from "@/lib/types/print-template"
import type { TicketData } from "./build-ticket-data"
import {
  BLOCK_VALUE_RESOLVERS,
  ITEM_FIELD_RESOLVERS,
  ITEM_LINE_TYPES,
  ITEM_TABLE_TYPES,
  formatMoney,
  itemTableColumns,
  sortBlocksForRender,
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
        `<td>${esc(item.name)}</td>` +
        (cols.qty ? `<td style="text-align:right">${item.qty}</td>` : "") +
        (cols.unitPrice ? `<td style="text-align:right">${esc(formatMoney(item.unitPrice, data.money))}</td>` : "") +
        (cols.total ? `<td style="text-align:right">${esc(formatMoney(item.total, data.money))}</td>` : "")
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
      return `<div style="${align};font-weight:bold">${esc(formatMoney(data.total, data.money))}</div>`

    case "payment_methods": {
      return data.payments
        .map((p) => `<div${styleAttr}>${esc(p.method)}: ${esc(formatMoney(p.amount, data.money))}</div>`)
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

export interface RenderTemplateToHtmlOptions {
  /** Ancho físico del rollo (58/80mm) — solo aplica a impresión de TICKET vía
   *  navegador (`printTicketInBrowser`). Constriñe el body a ese ancho y setea
   *  `@page` para que el diálogo nativo del browser lo centre en la hoja real
   *  (A4/carta/lo que tenga el usuario) como una columna angosta, igual que
   *  saldría de una impresora térmica. Sin esto el fallback imprimía a lo
   *  ancho de A4 completo — inconsistente con lo que sale por ESC/POS. */
  paperWidthMm?: 58 | 80
}

export function renderTemplateToHtml(
  template: PrintTemplateConfig,
  data: TicketData,
  options: RenderTemplateToHtmlOptions = {},
): string {
  // Canvas → orden visual: ver `sortBlocksForRender` en blocks.ts.
  const blocks = sortBlocksForRender(template.data ?? [])
  const parts: string[] = []

  // Mismo forzado que render-template.ts (ESC/POS): el destino de la comanda
  // no depende de que la plantilla del binding tenga un bloque para eso.
  if (data.docType === "order" && data.orderDestination) {
    parts.push(
      `<div style="text-align:center;font-weight:bold">COMANDA #${esc(data.ticketNo ?? "—")} · ${esc(data.orderDestination.toUpperCase())}</div>`,
    )
  }

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

  const body = parts.join("\n")
  const fontFamily = template.page_font_family ?? "monospace"
  const fontSize = template.page_font_size ?? "8pt"
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
