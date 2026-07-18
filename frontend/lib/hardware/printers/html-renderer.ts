import type { PrintTemplateConfig, PrintBlock } from "@/lib/types/print-template"
import type { TicketData } from "./build-ticket-data"

function esc(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
}

function formatMoney(n: number): string {
  return new Intl.NumberFormat("es-PY", { style: "currency", currency: "PYG" }).format(n)
}

const ITEM_BLOCK_TYPES = new Set([
  "item",
  "item_units",
  "item_uni_price",
  "item_discount",
  "item_total",
])

function interpolate(text: string, data: TicketData): string {
  return text.replace(/\{\{(\w+)\}\}/g, (_, key) => {
    const val = (data as unknown as Record<string, unknown>)[key]
    if (val === undefined || val === null) return ""
    return String(val)
  })
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

function renderBlockHtml(block: PrintBlock, data: TicketData): string {
  const align = blockAlign(block)
  const font = blockFont(block)
  const style = [align, font].filter(Boolean).join(";")
  const styleAttr = style ? ` style="${style}"` : ""

  switch (block.type) {
    case "hor_line":
      return `<hr style="border:none;border-top:1px dashed #000;margin:4px 0"/>`

    case "custom":
      return `<div${styleAttr}>${esc(interpolate(block.text ?? "", data))}</div>`

    case "company_name":
      return `<div style="${align};font-weight:bold">${esc(data.companyName)}</div>`

    case "company_address":
      return `<div${styleAttr}>${esc(data.companyAddress ?? "")}</div>`

    case "company_phone":
      return `<div${styleAttr}>${esc(data.companyPhone ?? "")}</div>`

    case "outlet_name":
      return `<div${styleAttr}>${esc(data.outletName ?? "")}</div>`

    case "outlet_address":
      return `<div${styleAttr}>${esc(data.outletAddress ?? "")}</div>`

    case "customer_name":
    case "customer_full_name":
      return `<div${styleAttr}>${esc(data.customerName ?? "")}</div>`

    case "customer_address":
      return `<div${styleAttr}>${esc(data.customerAddress ?? "")}</div>`

    case "customer_phone":
      return `<div${styleAttr}>${esc(data.customerPhone ?? "")}</div>`

    case "customer_tin":
    case "customer_ci":
      return `<div${styleAttr}>${esc(data.customerTin ?? "")}</div>`

    case "date":
      return `<div${styleAttr}>${esc(data.date)}</div>`

    case "user_name":
      return `<div${styleAttr}>${esc(data.userName ?? "")}</div>`

    case "document_number":
      return `<div${styleAttr}>${esc(data.documentNumber ?? "")}</div>`

    case "document_prefix":
      return `<div${styleAttr}>${esc(data.documentPrefix ?? "")}</div>`

    case "document_sufix":
      return `<div${styleAttr}>${esc(data.documentSufix ?? "")}</div>`

    case "subtotal":
      return `<div${styleAttr}>${esc(formatMoney(data.subtotal))}</div>`

    case "discount":
      return `<div${styleAttr}>${esc(formatMoney(data.discount))}</div>`

    case "tax_total":
      return `<div${styleAttr}>${esc(formatMoney(data.taxTotal))}</div>`

    case "total":
      return `<div style="${align};font-weight:bold">${esc(formatMoney(data.total))}</div>`

    case "note":
      return `<div${styleAttr}>${esc(data.note ?? "")}</div>`

    case "transaction_id":
      return `<div${styleAttr}>${esc(data.transactionId)}</div>`

    case "payment_methods": {
      const lines = data.payments
        .map((p) => `<div${styleAttr}>${esc(p.method)}: ${esc(formatMoney(p.amount))}</div>`)
        .join("")
      return lines
    }

    case "item_receipt":
    case "item_receipt_2":
    case "item_receipt_3":
    case "item_receipt_4": {
      const rows = data.items
        .map(
          (item) =>
            `<tr><td>${esc(item.name)}</td><td style="text-align:right">${item.qty}</td>` +
            `<td style="text-align:right">${esc(formatMoney(item.unitPrice))}</td>` +
            `<td style="text-align:right">${esc(formatMoney(item.total))}</td></tr>`,
        )
        .join("")
      return (
        `<table style="width:100%;border-collapse:collapse;font-size:inherit">` +
        `<thead><tr><th style="text-align:left">Ítem</th><th style="text-align:right">Cant.</th>` +
        `<th style="text-align:right">P.Unit</th><th style="text-align:right">Total</th></tr></thead>` +
        `<tbody>${rows}</tbody></table>`
      )
    }

    default:
      return ""
  }
}

function renderItemsBlock(block: PrintBlock, data: TicketData): string {
  const rows = data.items
    .map((item) => {
      switch (block.type) {
        case "item":
          return `<div>${esc(item.name)}</div>`
        case "item_units":
          return `<div>${item.qty}</div>`
        case "item_uni_price":
          return `<div>${esc(formatMoney(item.unitPrice))}</div>`
        case "item_discount":
          return `<div>${esc(formatMoney(item.discount))}</div>`
        case "item_total":
          return `<div>${esc(formatMoney(item.total))}</div>`
        default:
          return ""
      }
    })
    .join("")
  return rows
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
  const blocks = template.data ?? []
  const parts: string[] = []

  let i = 0
  while (i < blocks.length) {
    const block = blocks[i]
    if (ITEM_BLOCK_TYPES.has(block.type)) {
      const groupStart = i
      while (i < blocks.length && ITEM_BLOCK_TYPES.has(blocks[i].type)) {
        i++
      }
      const itemBlocks = blocks.slice(groupStart, i)
      for (const item of data.items) {
        for (const ib of itemBlocks) {
          parts.push(renderItemsBlock({ ...ib }, { ...data, items: [item] }))
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
