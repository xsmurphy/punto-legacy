"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface FinanceMovement {
  id: string
  accountId: string
  accountName: string | null
  categoryId: string | null
  categoryName: string | null
  kind: "income" | "expense"
  amount: number
  date: string
  description: string | null
  paymentMethod: string | null
  source: string
  sourceId: string | null
  transferGroupId: string | null
  reconciled: boolean
  status: number
}

export interface FinanceMovementListResponse {
  rows: FinanceMovement[]
  total: number
}

export interface FinanceMovementFilters {
  accountId?: string
  categoryId?: string
  kind?: "income" | "expense"
  from?: string
  to?: string
  q?: string
  limit?: number
  offset?: number
}

export interface FinanceMovementFormValues {
  accountId: string
  categoryId?: string | null
  kind: "income" | "expense"
  amount: number
  date?: string
  description?: string
  paymentMethod?: string
}

export interface FinanceTransferFormValues {
  fromAccountId: string
  toAccountId: string
  amount: number
  date?: string
  description?: string
}

function buildQuery(filters: FinanceMovementFilters): string {
  const qs = new URLSearchParams()
  if (filters.accountId) qs.set("accountId", filters.accountId)
  if (filters.categoryId) qs.set("categoryId", filters.categoryId)
  if (filters.kind) qs.set("kind", filters.kind)
  if (filters.from) qs.set("from", filters.from)
  if (filters.to) qs.set("to", filters.to)
  if (filters.q) qs.set("q", filters.q)
  if (filters.limit) qs.set("limit", String(filters.limit))
  if (filters.offset) qs.set("offset", String(filters.offset))
  return qs.toString()
}

export function useFinanceMovements(filters: FinanceMovementFilters = {}) {
  const query = buildQuery(filters)
  return useQuery<FinanceMovementListResponse>({
    queryKey: ["finance", "movements", filters],
    queryFn: () => api.get<FinanceMovementListResponse>(`/v1/finance/movements${query ? "?" + query : ""}`),
    staleTime: 15_000,
  })
}

/** Crea un movimiento manual (entrada o salida). */
export function useCreateFinanceMovement() {
  const qc = useQueryClient()
  return useMutation<FinanceMovement, Error, FinanceMovementFormValues>({
    mutationFn: (payload) => api.post<FinanceMovement>("/v1/finance/movements", payload as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance"] })
    },
  })
}

/** Transferencia entre cuentas propias — 2 movimientos con el mismo transferGroupId. */
export function useCreateFinanceTransfer() {
  const qc = useQueryClient()
  return useMutation<
    { transferGroupId: string; movements: FinanceMovement[] },
    Error,
    FinanceTransferFormValues
  >({
    mutationFn: (payload) =>
      api.post("/v1/finance/movements?resource=transfer", payload as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance"] })
    },
  })
}

/** Anula (soft-void) un movimiento manual o de transferencia; revierte el saldo. */
export function useVoidFinanceMovement() {
  const qc = useQueryClient()
  return useMutation<{ id: string; status: number }, Error, string>({
    mutationFn: (id) => api.del<{ id: string; status: number }>(`/v1/finance/movements?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance"] })
    },
  })
}
