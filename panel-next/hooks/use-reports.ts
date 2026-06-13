"use client"

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * Hook genérico para reports: fetcher GET con date range + extra params.
 * Cada report tiene su propio endpoint `/v1/reports/<name>.php` con shape
 * crudo (la mayoría devuelve `{ rows: [...] }`). El componente del report
 * tipa el resultado con su propio type.
 */
export function useReport<T>(
  name: string,
  opts: { from?: string; to?: string; params?: Record<string, string>; enabled?: boolean },
) {
  const params = new URLSearchParams()
  if (opts.from) params.set("from", opts.from)
  if (opts.to) params.set("to", opts.to)
  if (opts.params) {
    Object.entries(opts.params).forEach(([k, v]) => {
      if (v !== "" && v !== undefined) params.set(k, v)
    })
  }
  const qs = params.toString()
  return useQuery<T>({
    queryKey: ["reports", name, opts.from ?? "", opts.to ?? "", JSON.stringify(opts.params ?? {})],
    queryFn: () => api.get<T>(`/v1/reports/${name}${qs ? `?${qs}` : ""}`),
    staleTime: 60 * 1000, // 1 min — reports cambian con transacciones nuevas
    enabled: opts.enabled ?? true,
    retry: false,
  })
}

// ── Shapes de los reports que consumimos en panel-next ──────────────────────

/**
 * Fila del endpoint /v1/reports/drawers. Espejo del shape que devuelve
 * `DrawersService::listMovements` (los IDs ya vienen resueltos a
 * outletName / registerName / openUserName / closeUserName).
 */
export interface DrawerRow {
  drawerId: string
  outletName: string
  registerName: string
  openDate: string
  closeDate: string | null
  openAmount: number | string | null
  closeAmount: number | string | null
  openUserName: string
  closeUserName: string
  isClosed: boolean
  /** Componentes de venta del período de la caja (calculados server-side). */
  sold: number | string
  expense: number | string
  income: number | string
  return: number | string
}

/** Respuesta del endpoint /v1/reports/drawers (lista). */
export interface DrawersReportResponse {
  rows: DrawerRow[]
}

/**
 * Fila del endpoint /v1/reports/transactions?view=detail. El backend resuelve
 * los IDs a nombres (customerName/userName/outletName/registerName) y agrega
 * los componentes financieros calculados (subtotal/tax/discount/total).
 */
export interface TransactionRow {
  transactionId: string
  date: string
  dueDate: string
  customerName: string
  customerTIN: string
  userName: string
  outletName: string
  registerName: string
  docNo: string
  invoiceNo: number | string | null
  authNo: number | string | null
  payments: string[]
  note: string
  tags: string[]
  transactionType: number
  transactionComplete: 0 | 1
  topay: number
  netTotal: number
  discount: number
  subtotal: number
  tax: number
  totalGravado: number
  total: number
}

export interface TransactionsReportResponse {
  rows: TransactionRow[]
}
