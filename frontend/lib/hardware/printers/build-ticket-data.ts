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

// ── Reconstrucción de TicketData desde una transacción ya guardada ─────────
// Wrapper compartido: reimprimir/cotizar armaba el mismo shape de TicketData
// en 3 lugares (quote-print-view, transactions-list onReprint, pos-
// transactions-dialog handleReprint), cada uno con su propio mapeo de items
// y categoryId=null hardcodeado (rompía el filtrado por categoría en
// printSale/getBindingsForSale). Este wrapper es la única fuente — nuevos
// call-sites deben usarlo, no copiar el mapeo inline.

/** Shape mínimo común entre TransactionDetail (use-transactions) y otros
 *  hooks de detalle de transacción, para no acoplar este módulo a un hook. */
export interface TicketableTransaction {
  transactionId: string
  customerName?: string
  documentNo?: string
  invoicePrefix?: string
  date?: string
  discount?: string | number
  total?: string | number
  note?: string
  transactionDatas?: TicketableTransactionItem[] | null
  pMethods?: TicketablePaymentMethod[] | null
}

export interface TicketableTransactionItem {
  itemId?: string
  name: string
  count: number
  price: number
  total: number
  discount: number
  status?: number
}

export interface TicketablePaymentMethod {
  name?: string
  type?: string
  amount: number
}

/** Mapea items de una transacción a TicketItem, resolviendo categoryId real
 *  contra el catálogo cargado (necesario para el filtrado por categoría de
 *  `printSale`/`getBindingsForSale` — categoryId=null hardcodeado lo rompía). */
export function buildTicketItemsFromTransaction(
  items: TicketableTransactionItem[] | null | undefined,
): TicketItem[] {
  const catalogItems = useCatalogStore.getState().items
  return (items ?? [])
    .filter((i) => i.status !== 0)
    .map((i) => {
      const catalogItem = i.itemId ? catalogItems.find((c) => c.id === i.itemId) : undefined
      return {
        name: i.name,
        qty: i.count,
        unitPrice: i.price,
        discount: i.discount,
        total: i.total,
        categoryId: catalogItem?.categoryId ?? null,
      }
    })
}

export function buildTicketDataFromTransaction(
  tx: TicketableTransaction,
  config: PosConfig | null,
  docType: string,
): TicketData {
  const items = buildTicketItemsFromTransaction(tx.transactionDatas)
  const payments: TicketPayment[] = (tx.pMethods ?? []).map((p) => ({
    method: p.name || p.type || "—",
    amount: p.amount,
  }))
  const discount = Number(tx.discount ?? 0)
  const total = Number(tx.total ?? 0)
  const itemsTotal = items.reduce((s, i) => s + i.total, 0)

  return {
    companyName: config?.companyName ?? "",
    customerName: tx.customerName?.trim() || undefined,
    docType,
    documentNumber: tx.documentNo || undefined,
    documentPrefix: tx.invoicePrefix || undefined,
    transactionId: tx.transactionId,
    date: tx.date ?? new Date().toISOString(),
    items,
    subtotal: itemsTotal > 0 ? itemsTotal : total + discount,
    discount,
    taxTotal: 0,
    total,
    payments,
    note: tx.note || undefined,
  }
}
