/**
 * Comando: createQuote — guarda el carrito como cotización (type=9).
 *
 * POST a `/api/v1/sales` con type=9. El backend rutea a SaleService::saveQuote()
 * que NO requiere caja abierta, NO mueve stock, NO genera movimiento de caja.
 *
 * offlineEligible: false.
 */

// `/v1/sales` autentica con `apiAuthPosContext()` (SOLO Bearer del device), así
// que la cotización DEBE viajar por el cliente del POS. Con el cliente de panel
// (cookie) el POST era un 401 garantizado — la cotización nunca se guardaba.
import { posApi as api } from "@/lib/api/pos-client"
import type { CartLine } from "@/lib/cart/store"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"

export interface CreateQuoteInput {
  lines: CartLine[]
  customer: PosCustomer | null
  userId: string | null
  note: string | null
  tags: string[]
}

export interface CreateQuoteResult {
  transactionId: string
  transactionNo: number
  transactionDoc: string
  duplicated: boolean
}

interface RawQuoteResponse {
  transactionId: string
  transactionNo: number
  transactionDoc: string
  duplicated: boolean
}

export async function createQuote(input: CreateQuoteInput): Promise<CreateQuoteResult> {
  const { lines, customer, userId, note, tags } = input

  if (lines.length === 0) {
    throw new Error("El carrito está vacío")
  }

  const saleItems = lines.map((line) => ({
    itemId: line.itemId,
    name: line.name,
    count: line.qty,
    price: line.unitPrice,
    total: line.qty * line.unitPrice,
    discount: 0,
    totalDiscount: 0,
    tax: 0,
    note: line.note ?? null,
  }))

  const subtotal = saleItems.reduce((s, i) => s + i.total, 0)

  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, "0")
  const tzMinutes = -now.getTimezoneOffset()
  const tzSign = tzMinutes >= 0 ? "+" : "-"
  const tzHH = pad(Math.floor(Math.abs(tzMinutes) / 60))
  const tzMM = pad(Math.abs(tzMinutes) % 60)
  const dateStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}${tzSign}${tzHH}:${tzMM}`
  const timestamp = Math.floor(now.getTime() / 1000)

  const payload = {
    uid: crypto.randomUUID(),
    type: 9,
    sale: saleItems,
    subtotal,
    tax: 0,
    discount: 0,
    date: dateStr,
    timestamp,
    client: customer?.id ?? null,
    user: userId,
    note: note ?? null,
    tags,
  }

  const apiPayload = {
    uid: payload.uid,
    transaction: payload,
  }

  const response = await api.postLegacy<RawQuoteResponse>(
    "/v1/sales",
    apiPayload,
  )

  return {
    transactionId: response.transactionId,
    transactionNo: response.transactionNo,
    transactionDoc: response.transactionDoc,
    duplicated: response.duplicated === true,
  }
}
