"use client"

/**
 * Hooks de anulación de ventas del POS (F6, context/40-anulacion-y-nota-credito.md).
 *
 * Fuente de datos: BFF /api/pos/sales-void → api/v1/sales-void.php.
 *
 * `useVoidOptions` — GET: estado de anulabilidad (D4, ventana 48h) + por
 *   cada línea vendida qué es POSIBLE reponer al stock (D2 — el sistema
 *   decide qué es posible, el cajero decide dentro de eso).
 * `useVoidSale`    — POST: anula la venta. `errorCode` en `VoidSaleError`
 *   distingue `VOID_WINDOW_EXPIRED` | `HAS_RETURNS` | `HAS_PAYMENTS` |
 *   `ALREADY_VOIDED` para que la UI ofrezca "Hacer devolución" cuando aplica.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"

// ── Tipos ────────────────────────────────────────────────────────────────────

export interface VoidLine {
  itemSoldId: string
  itemId: string
  name: string
  qty: number
  unitPrice: number
  unitCogs: number
  /** 'ownStock' (repone el ítem) | 'ingredientReversal' (repone insumos, solo si nunca se preparó) | 'service' (nada que reponer). */
  kind: "ownStock" | "ingredientReversal" | "service"
  canRestock: boolean
  defaultRestock: boolean
  hadStockImpact: boolean
}

export interface CanVoid {
  allowed: boolean
  reason: string | null
  expiresAt: string | null
}

export interface VoidOptions {
  canVoid: CanVoid
  lines: VoidLine[]
}

export interface VoidSaleInput {
  id: string
  reason: string
  lines?: Array<{ itemSoldId: string; restock: boolean }>
}

export interface VoidSaleResult {
  id: string
  voidedAt: string
  restocked: number
  wasted: number
  einvoiceCancelled: boolean
}

/** `errorCode` viene en `error.details.errorCode` del envelope (api/lib/response.php). */
export class VoidSaleError extends Error {
  errorCode?: string
  constructor(message: string, errorCode?: string) {
    super(message)
    this.name = "VoidSaleError"
    this.errorCode = errorCode
  }
}

interface ApiEnvelopeError {
  ok?: false
  error?: { message?: string; code?: number | string; details?: { errorCode?: string } }
}

function errorFrom(payload: unknown, fallback: string): VoidSaleError {
  const env = payload as ApiEnvelopeError | null
  return new VoidSaleError(env?.error?.message ?? fallback, env?.error?.details?.errorCode)
}

// ── Hooks ────────────────────────────────────────────────────────────────────

export function useVoidOptions(transactionId: string | null, enabled = true) {
  return useQuery<VoidOptions, VoidSaleError>({
    queryKey: ["sale-void-options", transactionId],
    queryFn: async (): Promise<VoidOptions> => {
      const res = await posFetch(`/api/pos/sales-void?id=${encodeURIComponent(transactionId!)}`)
      const payload = await res.json().catch(() => ({}))
      if (!res.ok) throw errorFrom(payload, "No se pudo consultar la anulación")
      return ((payload as { data?: VoidOptions }).data ?? payload) as VoidOptions
    },
    enabled: enabled && Boolean(transactionId),
    // La ventana de 48h y el estado de devoluciones/recibos vigentes pueden
    // cambiar entre aperturas del dialog — siempre fresh, sin cache.
    staleTime: 0,
  })
}

export function useVoidSale() {
  const queryClient = useQueryClient()
  return useMutation<VoidSaleResult, VoidSaleError, VoidSaleInput>({
    mutationFn: async (input): Promise<VoidSaleResult> => {
      const res = await posFetch("/api/pos/sales-void", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(input),
      })
      const payload = await res.json().catch(() => ({}))
      if (!res.ok) throw errorFrom(payload, "No se pudo anular la venta")
      return ((payload as { data?: VoidSaleResult }).data ?? payload) as VoidSaleResult
    },
    onSuccess: () => {
      // Mismo set que useCreateReturn (hooks/use-returns.ts) — la anulación
      // reversa stock y movimiento financiero igual que una devolución.
      //
      // `["pos-transaction"]` SIN el id: `vars.id` es el UUID crudo que pide
      // sales-void.php, pero la query del detalle abierto está cacheada con
      // `encId` (`enc($transactionId)`, TransactionService::mainList) — son
      // strings distintos. invalidateQueries matchea por prefijo, así que
      // el key parcial invalida CUALQUIER detalle abierto sin necesitar el
      // enc — mismo criterio que el fix en use-realtime-sync.ts (entity
      // `transaction`).
      queryClient.invalidateQueries({ queryKey: ["pos-transactions"] })
      queryClient.invalidateQueries({ queryKey: ["pos-transaction"] })
      queryClient.invalidateQueries({ queryKey: ["transactions"] })
      queryClient.invalidateQueries({ queryKey: ["stock"] })
      queryClient.invalidateQueries({ queryKey: ["reports"] })
      queryClient.invalidateQueries({ queryKey: ["dashboard"] })
    },
  })
}
