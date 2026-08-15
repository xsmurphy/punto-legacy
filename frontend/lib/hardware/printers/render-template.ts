import ReceiptPrinterEncoder from "@point-of-sale/receipt-printer-encoder"
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
  ticketItemName,
} from "./blocks"

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Encoder = any

function applyBlockText(encoder: Encoder, block: PrintBlock, text: string): Encoder {
  if (block.align && block.align !== "left") {
    encoder = encoder.align(block.align)
  }
  if (block.bold === "bold") {
    encoder = encoder.bold(true)
  }
  encoder = encoder.line(text)
  if (block.bold === "bold") {
    encoder = encoder.bold(false)
  }
  if (block.align && block.align !== "left") {
    encoder = encoder.align("left")
  }
  return encoder
}

/** Listado completo de ítems para `item_receipt*` — antes solo existía en
 *  html-renderer.ts; faltaba por completo en ESC/POS (bug reportado). Cada
 *  ítem imprime 1-2 líneas monoespaciadas según qué columnas trae la
 *  variante (ver `itemTableColumns`). */
function renderItemTable(encoder: Encoder, block: PrintBlock, data: TicketData): Encoder {
  const cols = itemTableColumns(block.type)
  for (const item of data.items) {
    encoder = applyBlockText(encoder, { ...block, bold: "normal" }, ticketItemName(item))
    if (cols.qty || cols.unitPrice || cols.total) {
      const parts: string[] = []
      if (cols.qty) parts.push(`${item.qty}x`)
      if (cols.unitPrice) parts.push(formatMoney(item.unitPrice, data))
      if (cols.total) parts.push(formatMoney(item.total, data))
      encoder = applyBlockText(encoder, { ...block, bold: "normal", align: "right" }, parts.join("  "))
    }
  }
  return encoder
}

function renderSingleBlock(encoder: Encoder, block: PrintBlock, data: TicketData): Encoder {
  switch (block.type) {
    case "company_logo":
      console.warn("[render-template] BlockType no implementado: company_logo — requiere HTMLImageElement")
      return encoder

    case "hor_line":
      return encoder.rule({ style: "single" })

    case "ver_line":
      // No-op explícito: una línea vertical no tiene sentido en una
      // impresora térmica de líneas (rollo, sin columnas reales). El
      // fallback HTML sí la dibuja (ver html-renderer.ts).
      return encoder

    case "transaction_id_barcode":
      return encoder.barcode(data.transactionId, "code128", { height: 60 })

    case "fe_py": {
      // Portal de consulta del comprador (F6): QR con el link firmado. Se
      // intercepta acá en vez de dejarlo caer al resolver de texto porque el
      // token no es para tipear a mano — se escanea. Sin link (venta sin
      // documento electrónico) no se imprime nada, ni el rótulo.
      if (!data.einvoiceUrl) return encoder
      encoder = encoder.align("center")
      // errorlevel 'm': ~15% de tolerancia. El ticket térmico se arruga y se
      // borronea; 'l' (7%) falla al escanear en papel maltratado y 'q'/'h'
      // agrandan el QR más de lo que entra cómodo en 58 mm.
      encoder = encoder.qrcode(data.einvoiceUrl, { model: 2, size: 6, errorlevel: "m" })
      encoder = encoder.line(block.text?.trim() || "Consultá tu factura electrónica")
      return encoder.align("left")
    }

    default:
      break
  }

  if (ITEM_TABLE_TYPES.has(block.type)) {
    return renderItemTable(encoder, block, data)
  }

  const resolver = BLOCK_VALUE_RESOLVERS[block.type]
  if (resolver) {
    const value = resolver(data, block)
    return applyBlockText(encoder, block, value ?? "")
  }

  // Tipo realmente no implementado — no descartarlo en silencio (ese
  // silencio fue lo que hizo que este bug llegara a producción).
  console.error("[render-template] BlockType desconocido, no implementado:", block.type)
  return applyBlockText(encoder, block, `[${block.type}]`)
}

function renderItemBlock(
  encoder: Encoder,
  block: PrintBlock,
  item: TicketData["items"][number],
  data: TicketData,
): Encoder {
  const resolver = ITEM_FIELD_RESOLVERS[block.type]
  if (!resolver) return encoder
  const value = resolver(item, data)
  return applyBlockText(encoder, block, value ?? "")
}

export function renderTemplateToEscPos(opts: {
  template: PrintTemplateConfig
  data: TicketData
  paperWidthMm: 58 | 80
  openDrawer: boolean
  copies: number
}): Uint8Array {
  const { template, data, paperWidthMm, openDrawer, copies } = opts
  const columns = paperWidthMm === 58 ? 32 : 48

  let encoder: Encoder = new ReceiptPrinterEncoder({ columns })
  encoder = encoder.initialize()

  // El destino de la comanda va SIEMPRE, en su propia línea, junto al número
  // — sin depender de que la plantilla del binding tenga un bloque para eso
  // (el operador la configura para factura/recibo; una comanda mal armada
  // por un template incompleto es el bug que estamos evitando).
  if (data.docType === "order" && data.orderDestination) {
    encoder = encoder
      .align("center")
      .bold(true)
      .line(`COMANDA #${data.ticketNo ?? "—"} · ${data.orderDestination.toUpperCase()}`)
      .bold(false)
      .align("left")
  }

  // Canvas → orden visual: ver `sortBlocksForRender` en blocks.ts.
  const blocks = sortBlocksForRender(template.data ?? [])
  let i = 0

  while (i < blocks.length) {
    const block = blocks[i]

    if (ITEM_LINE_TYPES.has(block.type)) {
      // Collect consecutive item blocks
      const itemGroupStart = i
      while (i < blocks.length && ITEM_LINE_TYPES.has(blocks[i].type)) {
        i++
      }
      const itemBlocks = blocks.slice(itemGroupStart, i)

      // Render item blocks once per item
      for (const item of data.items) {
        for (const ib of itemBlocks) {
          encoder = renderItemBlock(encoder, ib, item, data)
        }
      }
    } else {
      encoder = renderSingleBlock(encoder, block, data)
      i++
    }
  }

  if (openDrawer) {
    // ESC/POS open drawer pulse — device 0, on 25ms, off 250ms
    encoder = encoder.pulse(0, 25, 250)
  }

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
