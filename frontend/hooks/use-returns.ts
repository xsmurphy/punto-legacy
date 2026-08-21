"use client"

/**
 * Hooks de devoluciones del POS.
 *
 * Fuente de datos: BFF /api/pos/returns → api/v1/returns.php.
 *
 * `useReturnOptions`      — GET returnOptions: por línea vendida de la venta
 *   original, qué es POSIBLE reponer al stock (D2, context/40 — el sistema
 *   decide qué es posible, el cajero decide dentro de eso) + cupo disponible
 *   (`availableQty` YA descuenta devoluciones previas, no recalcular acá).
 *   Reemplaza el listado ad-hoc que el sheet armaba antes con
 *   `transactionDatas` de la venta original.
 * `useCreateReturn`       — mutación para crear una devolución. SIEMPRE manda
 *   `itemSoldId` por línea (viene de `useReturnOptions`) — sin él, un ítem
 *   repetido en dos líneas hace que el backend rechace 422 o pierda la
 *   decisión de reposición (ver `ReturnService::resolveLineDecisions()` /
 *   `StockReversalPolicy`, mismo guard que `AmbiguousVoidLineException` del
 *   lado de anulación).
 * `useReturnsForParent`   — lista de devoluciones de una transacción.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"

// ── Tipos ──────────────────────────────────────────────────────────────────────

/** Línea de `returnOptions` — ver `ReturnService::returnOptions()` (api/lib/services/ReturnService.php). */
export interface ReturnLine {
  itemSoldId: string
  itemId: string
  name: string
  soldQty: number
  /** Ya devuelto en devoluciones previas de esta misma venta (suma de todas, no solo la última). */
  alreadyReturned: number
  /** `soldQty - alreadyReturned`, YA calculado por el backend — no recalcular en el front. */
  availableQty: number
  unitPrice: number
  /**
   * Qué es POSIBLE reponer (tabla D2 de context/40) — no opinable, lo
   * determina el sistema según cómo el ítem descontó stock al venderse.
   */
  canRestock: boolean
  /** Default del toggle "vuelve al stock" para esta línea. */
  defaultRestock: boolean
  /**
   * 'ownStock' (repone el ítem/producción previa) | 'ingredientReversal'
   * (repone insumos de producción directa/combo, solo si el tenant activó
   * `settingReturnAllowIngredientReversal` Y nunca se preparó) | 'service'
   * (nada que reponer).
   */
  kind: "ownStock" | "ingredientReversal" | "service"
}

export interface ReturnItem {
  itemId: string
  qty: number
  /** Decisión del cajero por línea (D2). Ausente = default del backend — mandar explícito, no dejar que el backend adivine. */
  restock?: boolean
  /** SIEMPRE mandar — viene de `ReturnLine.itemSoldId`. Sin esto el backend puede rechazar 422 o mezclar la decisión entre líneas del mismo itemId. */
  itemSoldId?: string
}

export interface CreateReturnInput {
  parentTransactionId: string
  items: ReturnItem[]
  refundMode: "cash" | "credit"
  note?: string
}

export interface ReturnResult {
  id: string
  total: number
  refundMode: "cash" | "credit"
  stockMovements: number
  customerCreditApplied: number | null
}

export interface ReturnRecord {
  transactionId: string
  transactionTotal: string
  transactionDate: string
  transactionNote: string | null
  transactionPaymentType: string
}

/** `errorCode` viaja en `error.details.errorCode` del envelope (api/lib/response.php) cuando el backend lo manda — hoy `ReturnService::create()` solo manda `message` (422 plano vía `apiError()`), así que casi siempre queda `undefined`; el campo existe para cuando el backend empiece a estructurar códigos, mismo contrato que `VoidSaleError` (hooks/use-sale-void.ts). */
export class ReturnError extends Error {
  errorCode?: string
  constructor(message: string, errorCode?: string) {
    super(message)
    this.name = "ReturnError"
    this.errorCode = errorCode
  }
}

interface ApiEnvelopeError {
  ok?: false
  error?: { message?: string; code?: number | string; details?: { errorCode?: string } }
}

function errorFrom(payload: unknown, fallback: string): ReturnError {
  const env = payload as ApiEnvelopeError | null
  return new ReturnError(env?.error?.message ?? fallback, env?.error?.details?.errorCode)
}

// ── Hooks ──────────────────────────────────────────────────────────────────────

export function useReturnOptions(parentTransactionId: string | null) {
  return useQuery<ReturnLine[], ReturnError>({
    queryKey: ["return-options", parentTransactionId],
    queryFn: async (): Promise<ReturnLine[]> => {
      const qs = new URLSearchParams({
        action: "returnOptions",
        parentId: parentTransactionId!,
      })
      const res = await posFetch(`/api/pos/returns?${qs.toString()}`)
      const payload = await res.json().catch(() => ({}))
      if (!res.ok) throw errorFrom(payload, "No se pudo consultar la devolución")
      return ((payload as { data?: { lines?: ReturnLine[] } }).data?.lines ?? []) as ReturnLine[]
    },
    enabled: Boolean(parentTransactionId),
    // El cupo disponible cambia con cada devolución previa — siempre fresh.
    staleTime: 0,
  })
}

export function useCreateReturn() {
  const queryClient = useQueryClient()
  return useMutation<ReturnResult, ReturnError, CreateReturnInput>({
    mutationFn: async (input: CreateReturnInput): Promise<ReturnResult> => {
      const res = await posFetch("/api/pos/returns", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "create", ...input }),
      })
      const payload = await res.json().catch(() => ({}))
      if (!res.ok) throw errorFrom(payload, "Error al crear devolución")
      // El backend envuelve en { ok, data }
      return ((payload as { data?: ReturnResult }).data ?? payload) as ReturnResult
    },
    onSuccess: (data, vars) => {
      queryClient.invalidateQueries({ queryKey: ["transactions"] })
      queryClient.invalidateQueries({ queryKey: ["pos-transactions"] })
      queryClient.invalidateQueries({ queryKey: ["pos-transaction"] })
      queryClient.invalidateQueries({ queryKey: ["return-options", vars.parentTransactionId] })
      queryClient.invalidateQueries({ queryKey: ["returns-for-parent", vars.parentTransactionId] })
      queryClient.invalidateQueries({ queryKey: ["stock"] })
      queryClient.invalidateQueries({ queryKey: ["reports"] })
      queryClient.invalidateQueries({ queryKey: ["dashboard"] })
      if (data.customerCreditApplied !== null) {
        queryClient.invalidateQueries({ queryKey: ["customers"] })
        queryClient.invalidateQueries({ queryKey: ["contacts"] })
      }
    },
  })
}

export function useReturnsForParent(parentTransactionId: string | null) {
  return useQuery({
    queryKey: ["returns-for-parent", parentTransactionId],
    queryFn: async (): Promise<ReturnRecord[]> => {
      const qs = new URLSearchParams({
        action: "listForParent",
        parentId: parentTransactionId!,
      })
      const res = await posFetch(`/api/pos/returns?${qs.toString()}`)
      if (!res.ok) throw new Error("Error fetching returns")
      const data = await res.json()
      return (
        ((data as { data?: { returns?: ReturnRecord[] } }).data?.returns) ?? []
      )
    },
    enabled: Boolean(parentTransactionId),
    staleTime: 30_000,
  })
}
