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
  /**
   * Resumen de devoluciones VIGENTES — lo que el menú de acciones necesita
   * para decidir qué ofrecer. DISTINTO de `creditNotes`, que lista TODAS las
   * devoluciones (anuladas incluidas) porque es el bloque de auditoría.
   *
   * `count` es el mismo conjunto que el backend mira para rechazar con
   * `HAS_RETURNS` (`SaleVoidService`), así que ocultar "Anular" con
   * `count > 0` tapa exactamente los casos que el servidor rechazaría.
   * `fullyReturned` = no queda ninguna unidad por devolver.
   *
   * Ausente en transacciones que no son venta contado/crédito.
   */
  returns?: { count: number; fullyReturned: boolean }
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
  /**
   * Documentos fiscales del outbox para esta venta
   * (`TransactionService::getSingle` → `EInvoiceService::documentsForTransaction`,
   * la MISMA fuente que sirve el detalle del panel). Ordenados por fecha desc:
   * `[0]` es el vigente.
   *
   * `status` NO habla de validez fiscal: hay un caso registrado de un documento
   * `issued` con CDC válido que SIFEN rechazó después y cuyo KuDE se descargaba
   * igual. Para eso están `sifenVerdict` y `deliveryBlocker`, más abajo.
   *
   * Lista vacía = nunca se encoló (tenant sin FE, emisión automática apagada,
   * o cliente sin RUC con el filtro activo). No es un error.
   */
  einvoiceDocuments?: Array<{
    id: string
    doctype: string
    status: "pending" | "sending" | "issued" | "error" | "cancelled"
    cdc: string | null
    documentNumber: string | null
    errorMessage: string | null
    issuedAt: string | null
    attempts: number
    /**
     * Veredicto FISCAL. `status` es el outbox de Punto (¿se mandó?);
     * ESTO es si SIFEN lo aceptó. Son preguntas distintas: hay un caso
     * registrado de un documento `issued` con CDC válido que SIFEN rechazó
     * después. Ninguna pantalla puede decir "emitida" mirando solo `status`.
     */
    sifenVerdict: "approved" | "rejected" | "pending"
    /** No null = hay una reemisión que reemplazó a este documento. */
    supersededBy: string | null
    /**
     * Por qué NO se le puede entregar el KuDE al comprador, o null si sí.
     * Lo calcula el MISMO predicado del backend que aplican el endpoint de la
     * caja, el email y el portal (`EInvoiceService::deliveryBlockerForRow`).
     * La pantalla lo MUESTRA, no lo reimplementa — reimplementarlo es como se
     * llega a ofrecer una descarga que el endpoint después rechaza con 409.
     */
    deliveryBlocker:
      | "not_found"
      | "numbering_mismatch"
      | "superseded"
      | "cancelled"
      | "not_issued"
      | "sifen_rejected"
      | "sifen_pending"
      | null
  }>
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
    /**
     * Refresco mientras la factura electrónica espera el veredicto de SIFEN.
     *
     * El KuDE recién se puede entregar cuando `sifen_status` deja de estar en
     * blanco, y eso lo escribe el cron de reconciliación (cada 10 min). Una
     * venta recién cobrada nace SIEMPRE en ese estado: sin refresco, el cajero
     * abría el detalle, veía el botón deshabilitado y no volvía a pasar nada
     * en pantalla — el cliente se iba del mostrador antes de que el PDF se
     * habilitara, que es justo el momento para el que existe esta feature.
     *
     * Solo mientras hay algo que esperar (`sifen_pending`), y con `staleTime`
     * intacto para el resto: es una caja, no una pantalla de monitoreo, y un
     * poll permanente sobre cada detalle abierto es exactamente el auto-DDoS
     * que el módulo se cuida de no generar.
     */
    refetchInterval: (query) => {
      const docs = query.state.data?.einvoiceDocuments ?? []
      const vigente = docs.find((d) => d.supersededBy === null) ?? docs[0] ?? null
      return vigente?.deliveryBlocker === "sifen_pending" ? 30_000 : false
    },
  })
}
