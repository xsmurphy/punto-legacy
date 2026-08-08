"use client"

/**
 * Hooks de transacciones del POS.
 *
 * Fuente de datos: BFF /api/pos/transactions (lista) y /api/pos/transactions/[id] (detalle).
 *
 * Tipos derivados de la respuesta de TransactionService::getSingle y mainList
 * (api/lib/services/TransactionService.php).
 *
 * `useTransactionsList` — lista paginada con filtros de fecha.
 * `useTransaction` — detalle de una transacción por ID (enc).
 */

import { useQuery } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"

// ── Tipos ─────────────────────────────────────────────────────────────────────

export interface TransactionListItem {
  transactionId: string
  /** Nombre del cliente (o vacío). */
  name: string
  total: string
  date: string
  /** Tipo de transacción: 0=contado, 3=crédito, 9=cotización, etc. */
  type: string
  status: string
  documentNo: string
  invoicePrefix: string
  customerId: string
}

export interface TransactionDetail {
  transactionId: string
  customerId: string
  customerName?: string
  name: string
  type: string
  status: string
  date: string
  documentNo: string
  invoicePrefix: string
  total: string
  discount: string
  note: string
  tags: string
  /** Ítems de la venta. */
  transactionDatas: TransactionDataItem[] | null
  /** Métodos de pago aplicados. */
  pMethods: PaymentMethod[]
  /** Solo type=3 (crédito): resumen de deuda. */
  creditPayments?: { total: number; paid: number; debt: number }
  /** Notas de crédito (type=6) hijas de esta transacción. */
  creditNotes?: Array<{ transactionId: string; transactionDate: string; transactionTotal: number; invoiceNo?: string | null }>
  /** Agendamientos (type=13) hijos de esta transacción. */
  appointments?: Array<{ transactionId: string; transactionDate: string; transactionTotal: number }>
  /** Recibos de pago (type=5) hijos — solo type=3. */
  paymentsReceived?: Array<{ transactionId: string; date: string; amount: number; invoiceNo?: string; paymentMethod?: string }>
}

export interface TransactionDataItem {
  itemId: string
  name: string
  count: number
  price: number
  total: number
  discount: number
  totalDiscount: number
  note: string
  sku: string
  status: number
  /**
   * IVA congelado por línea (F2a/F2b, context/38) — `transactionDatas` es el
   * decode directo de `meta->transactionDetails` (TransactionService::getSingle,
   * `$rawDetails`/`$transactionDatas`), que ya trae estos 6 campos escritos por
   * `SaleService::enrichWithTaxes` al confirmar la venta. Opcionales porque
   * ventas anteriores al corte de F2 no los tienen (quedan undefined, no 0 —
   * ver D3 del plan). F3b (ticket) los consume tal cual, sin recalcular.
   */
  taxId?: string | null
  taxRate?: number
  taxKind?: "rate" | "exempt"
  taxIncluded?: boolean
  taxAmount?: number
  taxNet?: number
}

export interface PaymentMethod {
  amount: number
  name: string
  type: string
  extra: string
  UID: string
}

// ── Fetchers ──────────────────────────────────────────────────────────────────

async function fetchTransactionsList(filters: {
  date?: string
  limit?: number
}): Promise<TransactionListItem[]> {
  const qs = new URLSearchParams()
  if (filters.date) qs.set("date", filters.date)
  if (filters.limit) qs.set("limit", String(filters.limit))

  const res = await posFetch(`/api/pos/transactions?${qs.toString()}`)
  if (!res.ok) throw new Error(`Error ${res.status}`)
  const data = await res.json()
  // El backend devuelve { transactionsList: [...] }
  return (data.transactionsList as TransactionListItem[]) ?? []
}

async function fetchTransactionDetail(id: string): Promise<TransactionDetail> {
  const res = await posFetch(`/api/pos/transactions/${encodeURIComponent(id)}`)
  if (!res.ok) throw new Error(`Error ${res.status}`)
  const data = await res.json()
  return data as TransactionDetail
}

// ── Hooks ─────────────────────────────────────────────────────────────────────

export interface TransactionsFilters {
  date?: string
  limit?: number
}

export function useTransactionsList(filters: TransactionsFilters = {}) {
  return useQuery({
    queryKey: ["pos-transactions", filters],
    queryFn: () => fetchTransactionsList(filters),
    staleTime: 30_000,
  })
}

export function useTransaction(id: string | null) {
  return useQuery({
    queryKey: ["pos-transaction", id],
    queryFn: () => fetchTransactionDetail(id!),
    enabled: Boolean(id),
    staleTime: 60_000,
  })
}
