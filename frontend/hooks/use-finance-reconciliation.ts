"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export type ReconciliationStatus = "open" | "closed"

export interface FinanceReconciliation {
  id: string
  accountId: string
  accountName: string | null
  statementDate: string
  statementBalance: number
  status: ReconciliationStatus
  closedAt: string | null
  userId: string | null
}

export interface FinanceReconciliationListResponse {
  rows: FinanceReconciliation[]
  total: number
}

export interface ReconciliationMovement {
  id: string
  categoryId: string | null
  categoryName: string | null
  kind: "income" | "expense"
  amount: number
  date: string
  description: string | null
  source: string
  reconciled: boolean
}

export interface FinanceReconciliationDetail {
  session: FinanceReconciliation
  movements: ReconciliationMovement[]
  reconciledBalance: number
  difference: number
}

export interface FinanceReconciliationFilters {
  accountId?: string
  status?: ReconciliationStatus
  limit?: number
  offset?: number
}

export interface CreateReconciliationValues {
  accountId: string
  statementDate: string
  statementBalance: number
}

function buildQuery(filters: FinanceReconciliationFilters): string {
  const qs = new URLSearchParams()
  if (filters.accountId) qs.set("accountId", filters.accountId)
  if (filters.status) qs.set("status", filters.status)
  if (filters.limit) qs.set("limit", String(filters.limit))
  if (filters.offset) qs.set("offset", String(filters.offset))
  return qs.toString()
}

export function useFinanceReconciliations(filters: FinanceReconciliationFilters = {}) {
  const query = buildQuery(filters)
  return useQuery<FinanceReconciliationListResponse>({
    queryKey: ["finance", "reconciliations", filters],
    queryFn: () =>
      api.get<FinanceReconciliationListResponse>(`/v1/finance/reconciliations${query ? "?" + query : ""}`),
    staleTime: 15_000,
  })
}

export function useFinanceReconciliationDetail(id: string | null) {
  return useQuery<FinanceReconciliationDetail>({
    queryKey: ["finance", "reconciliations", "detail", id],
    queryFn: () => api.get<FinanceReconciliationDetail>(`/v1/finance/reconciliations?id=${id}`),
    enabled: !!id,
    staleTime: 5_000,
  })
}

export function useCreateFinanceReconciliation() {
  const qc = useQueryClient()
  return useMutation<FinanceReconciliation, Error, CreateReconciliationValues>({
    mutationFn: (payload) =>
      api.post<FinanceReconciliation>("/v1/finance/reconciliations", payload as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "reconciliations"] })
    },
  })
}

/** Tilda/destilda un movimiento en una sesión abierta. No afecta currentbalance. */
export function useToggleReconciliationMovement() {
  const qc = useQueryClient()
  return useMutation<
    FinanceReconciliationDetail,
    Error,
    { sessionId: string; movementId: string; reconciled: boolean }
  >({
    mutationFn: ({ sessionId, movementId, reconciled }) =>
      api.put<FinanceReconciliationDetail>(`/v1/finance/reconciliations?id=${sessionId}&resource=toggle`, {
        movementId,
        reconciled,
      }),
    onSuccess: (_, { sessionId }) => {
      qc.invalidateQueries({ queryKey: ["finance", "reconciliations", "detail", sessionId] })
    },
  })
}

/** Cierra la sesión. Si createAdjustment=true, genera un movimiento de ajuste y actualiza el saldo de cuenta. */
export function useCloseFinanceReconciliation() {
  const qc = useQueryClient()
  return useMutation<
    FinanceReconciliation,
    Error,
    { id: string; createAdjustment?: boolean; adjustmentCategoryId?: string }
  >({
    mutationFn: ({ id, createAdjustment, adjustmentCategoryId }) =>
      api.post<FinanceReconciliation>(`/v1/finance/reconciliations?id=${id}&resource=close`, {
        createAdjustment: createAdjustment ?? false,
        adjustmentCategoryId,
      }),
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: ["finance", "reconciliations"] })
      qc.invalidateQueries({ queryKey: ["finance", "reconciliations", "detail", id] })
      qc.invalidateQueries({ queryKey: ["finance", "accounts"] })
      qc.invalidateQueries({ queryKey: ["finance", "movements"] })
    },
  })
}

/** Cancela una sesión abierta: destilda todos sus movimientos. */
export function useCancelFinanceReconciliation() {
  const qc = useQueryClient()
  return useMutation<{ id: string; status: string; cancelled: boolean }, Error, string>({
    mutationFn: (id) => api.del<{ id: string; status: string; cancelled: boolean }>(`/v1/finance/reconciliations?id=${id}`),
    onSuccess: (_, id) => {
      qc.invalidateQueries({ queryKey: ["finance", "reconciliations"] })
      qc.invalidateQueries({ queryKey: ["finance", "reconciliations", "detail", id] })
    },
  })
}
