import ReceiptPrinterEncoder from "@point-of-sale/receipt-printer-encoder"
import type { PrintTemplateConfig, PrintBlock } from "@/lib/types/print-template"
import type { TicketData } from "./build-ticket-data"

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Encoder = any

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

function renderSingleBlock(encoder: Encoder, block: PrintBlock, data: TicketData): Encoder {
  switch (block.type) {
    case "company_name":
      if (block.align && block.align !== "left") encoder = encoder.align(block.align)
      encoder = encoder.bold(true).line(data.companyName).bold(false)
      if (block.align && block.align !== "left") encoder = encoder.align("left")
      return encoder

    case "company_address":
      return applyBlockText(encoder, block, data.companyAddress ?? "")

    case "company_phone":
      return applyBlockText(encoder, block, data.companyPhone ?? "")

    case "company_logo":
      console.warn("[render-template] BlockType no implementado: company_logo — requiere HTMLImageElement")
      return encoder

    case "outlet_name":
      return applyBlockText(encoder, block, data.outletName ?? "")

    case "outlet_address":
      return applyBlockText(encoder, block, data.outletAddress ?? "")

    case "customer_name":
    case "customer_full_name":
      return applyBlockText(encoder, block, data.customerName ?? "")

    case "customer_address":
      return applyBlockText(encoder, block, data.customerAddress ?? "")

    case "customer_phone":
      return applyBlockText(encoder, block, data.customerPhone ?? "")

    case "customer_tin":
    case "customer_ci":
      return applyBlockText(encoder, block, data.customerTin ?? "")

    case "date":
      return applyBlockText(encoder, block, data.date)

    case "user_name":
      return applyBlockText(encoder, block, data.userName ?? "")

    case "document_number":
      return applyBlockText(encoder, block, data.documentNumber ?? "")

    case "document_prefix":
      return applyBlockText(encoder, block, data.documentPrefix ?? "")

    case "document_sufix":
      return applyBlockText(encoder, block, data.documentSufix ?? "")

    case "subtotal": {
      if (block.align && block.align !== "left") encoder = encoder.align(block.align)
      if (block.bold === "bold") encoder = encoder.bold(true)
      encoder = encoder.line(formatMoney(data.subtotal))
      if (block.bold === "bold") encoder = encoder.bold(false)
      if (block.align && block.align !== "left") encoder = encoder.align("left")
      return encoder
    }

    case "discount":
      return applyBlockText(encoder, block, formatMoney(data.discount))

    case "tax_total":
      return applyBlockText(encoder, block, formatMoney(data.taxTotal))

    case "total": {
      if (block.align && block.align !== "left") encoder = encoder.align(block.align)
      encoder = encoder.bold(true).line(formatMoney(data.total)).bold(false)
      if (block.align && block.align !== "left") encoder = encoder.align("left")
      return encoder
    }

    case "payment_methods": {
      for (const p of data.payments) {
        encoder = applyBlockText(encoder, block, `${p.method}: ${formatMoney(p.amount)}`)
      }
      return encoder
    }

    case "note":
      return applyBlockText(encoder, block, data.note ?? "")

    case "hor_line":
      return encoder.rule({ style: "single" })

    case "custom":
      return applyBlockText(encoder, block, block.text ?? "")

    case "transaction_id":
      return applyBlockText(encoder, block, data.transactionId)

    case "transaction_id_barcode":
      return encoder.barcode(data.transactionId, "code128", { height: 60 })

    default:
      console.warn("[render-template] BlockType no implementado:", block.type)
      return encoder
  }
}

function renderItemBlock(
  encoder: Encoder,
  block: PrintBlock,
  item: TicketData["items"][number],
): Encoder {
  switch (block.type) {
    case "item":
      return applyBlockText(encoder, block, item.name)
    case "item_units":
      return applyBlockText(encoder, block, String(item.qty))
    case "item_uni_price":
      return applyBlockText(encoder, block, formatMoney(item.unitPrice))
    case "item_discount":
      return applyBlockText(encoder, block, formatMoney(item.discount))
    case "item_total":
      return applyBlockText(encoder, block, formatMoney(item.total))
    default:
      return encoder
  }
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

  const blocks = template.data ?? []
  let i = 0

  while (i < blocks.length) {
    const block = blocks[i]

    if (ITEM_BLOCK_TYPES.has(block.type)) {
      // Collect consecutive item blocks
      const itemGroupStart = i
      while (i < blocks.length && ITEM_BLOCK_TYPES.has(blocks[i].type)) {
        i++
      }
      const itemBlocks = blocks.slice(itemGroupStart, i)

      // Render item blocks once per item
      for (const item of data.items) {
        for (const ib of itemBlocks) {
          encoder = renderItemBlock(encoder, ib, item)
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
