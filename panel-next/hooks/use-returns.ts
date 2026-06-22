"use client"

/**
 * Hooks de devoluciones del POS.
 *
 * Fuente de datos: BFF /api/pos/returns → api/v1/returns.php.
 *
 * `useCreateReturn`       — mutación para crear una devolución.
 * `useReturnsForParent`   — lista de devoluciones de una transacción.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

// ── Tipos ──────────────────────────────────────────────────────────────────────

export interface ReturnItem {
  itemId: string
  qty: number
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

// ── Hooks ──────────────────────────────────────────────────────────────────────

export function useCreateReturn() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: CreateReturnInput): Promise<ReturnResult> => {
      const res = await fetch("/api/pos/returns", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "create", ...input }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok) {
        throw new Error(
          (data as { error?: string }).error ?? "Error al crear devolución",
        )
      }
      // El backend envuelve en { ok, data }
      return ((data as { data?: ReturnResult }).data ?? data) as ReturnResult
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ["transactions"] })
      queryClient.invalidateQueries({ queryKey: ["pos-transactions"] })
      queryClient.invalidateQueries({ queryKey: ["stock"] })
      queryClient.invalidateQueries({ queryKey: ["reports"] })
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
      const res = await fetch(`/api/pos/returns?${qs.toString()}`)
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
