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
import { posApi } from "@/lib/api/pos-client"

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
  /**
   * Anulación (F6, context/40-anulacion-y-nota-credito.md). `void` cubre
   * DOS caminos: el legacy (`type === 7`, hooks/use-void-transaction.ts) y
   * el nuevo de SaleVoidService sobre venta contado/crédito (`voidedAt` sin
   * pisar `transactionType`) — ver TransactionDetailService::getSingle.
   * `voidedAt`/`voidReason`/`voidedBy(Name)` solo pueblan con el camino nuevo.
   */
  void?: boolean
  voidedAt?: string | null
  voidReason?: string | null
  voidedBy?: string | null
  voidedByName?: string | null
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
  /** Etiquetas de línea (uso interno) — decode de `meta.transactionDetails`,
   *  ya venía sanitizado por `Money::sanitizeSaleArray`. Ausente en ventas
   *  anteriores a este corte (2026-08-14), igual que los campos de IVA. */
  tags?: string[]
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

/**
 * Los dos fetchers van por `posApi` — el wrapper del realm device — y NO por
 * `posFetch` crudo.
 *
 * `posFetch` devuelve la `Response` tal cual, así que el `.json()` de acá era
 * el ENVELOPE `{ ok, data }` de la API (`apiOk`, api/lib/response.php), no el
 * payload. Los dos fetchers lo trataban como si fuera el payload:
 *
 *   - El detalle casteaba el envelope entero a `TransactionDetail`. Un objeto
 *     sin `type`/`items`/`customerName` pero TRUTHY, así que el panel lo
 *     pintaba en vez de mostrar error: "Sin cliente", "Tipo NaN", "Items (0)",
 *     "Gs 0" (reporte del tester, 2026-08-28).
 *   - La lista leía `data.transactionsList` sobre el envelope — siempre
 *     `undefined`, siempre `[]`. Falla en silencio porque cae al `?? []`.
 *
 * Y el detalle además comparte la queryKey `["pos-transaction", id]` con
 * `usePosTransactionDetail` (use-pos-transactions.ts), que sí usa `posApi` y
 * guarda la forma correcta. Dos escritores con formas distintas sobre la MISMA
 * clave: ganaba el último en resolver, de ahí que recargar la página "lo
 * arreglara". Con los dos por `posApi` la forma es una sola.
 *
 * `posApi` ya desenvuelve el envelope, tira `ApiError` en las respuestas no-ok
 * y avisa cuando `ok=true` viene sin `data` — nada de eso hay que repetirlo
 * acá. Es la misma regla del proyecto que ya cerró `/api/api` y el Bearer
 * faltante: se usa el wrapper compartido, no se lo esquiva (CLAUDE.md §5).
 */
export async function fetchTransactionsList(filters: {
  date?: string
  limit?: number
}): Promise<TransactionListItem[]> {
  const qs = new URLSearchParams()
  if (filters.date) qs.set("date", filters.date)
  if (filters.limit) qs.set("limit", String(filters.limit))

  const data = await posApi.get<{ transactionsList?: TransactionListItem[] }>(
    `/pos/transactions?${qs.toString()}`,
  )
  return data.transactionsList ?? []
}

export async function fetchTransactionDetail(id: string): Promise<TransactionDetail> {
  return posApi.get<TransactionDetail>(`/pos/transactions/${encodeURIComponent(id)}`)
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
