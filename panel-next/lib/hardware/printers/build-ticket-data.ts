import type { CreateSalePayload, CreateSaleResult } from "@/lib/commands/create-sale"
import type { PosConfig } from "@/lib/types/pos-bootstrap"
import { useCatalogStore } from "@/lib/catalog/store"

export interface TicketData {
  // empresa / tenant
  companyName: string
  companyAddress?: string
  companyPhone?: string
  companyLogoUrl?: string
  // sucursal
  outletName?: string
  outletAddress?: string
  // cliente
  customerName?: string
  customerAddress?: string
  customerPhone?: string
  customerTin?: string
  // documento
  docType: string
  documentNumber?: string
  documentPrefix?: string
  documentSufix?: string
  ticketNo?: string
  transactionId: string
  saleId?: string
  // usuario
  userName?: string
  // fecha
  date: string
  // items
  items: TicketItem[]
  // totales
  subtotal: number
  discount: number
  taxTotal: number
  total: number
  // pagos
  payments: TicketPayment[]
  // nota
  note?: string
}

export interface TicketItem {
  name: string
  qty: number
  unitPrice: number
  discount: number
  total: number
  categoryId: string | null
}

export interface TicketPayment {
  method: string
  amount: number
}

interface BuildTicketDataInput {
  payload: CreateSalePayload
  result: CreateSaleResult
  config: PosConfig | null
}

export function buildTicketData({ payload, result, config }: BuildTicketDataInput): TicketData {
  const state = useCatalogStore.getState()
  const outletName = state.outlet?.name
  const customer = state.customers.find((c) => c.id === payload.client) ?? null

  const items: TicketItem[] = payload.sale.map((s) => {
    const catalogItem = state.items.find((i) => i.id === s.itemId)
    return {
      name: s.name,
      qty: s.count,
      unitPrice: s.price,
      discount: s.discount,
      total: s.total,
      categoryId: catalogItem?.categoryId ?? null,
    }
  })

  const payments: TicketPayment[] = payload.payment.map((p) => ({
    method: p.name,
    amount: p.total,
  }))

  return {
    companyName: config?.companyName ?? "",
    outletName,
    customerName: customer?.name,
    customerPhone: customer?.phone ?? undefined,
    customerTin: customer?.tin ?? undefined,
    docType: payload.type === 3 ? "credit" : "receipt",
    transactionId: result.transactionId,
    date: payload.date,
    items,
    subtotal: payload.subtotal,
    discount: payload.discount,
    taxTotal: payload.tax,
    total: result.total,
    payments,
    note: payload.note ?? undefined,
  }
}
