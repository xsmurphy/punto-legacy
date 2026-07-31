"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export type LoanStatus = "active" | "settled" | "cancelled"
export type LoanInstallmentStatus = "pending" | "paid"

export interface FinanceLoan {
  id: string
  name: string
  principal: number
  installmentCount: number
  firstDueDate: string
  frequency: "monthly"
  status: LoanStatus
  createdAt: string
  /** Solo presente en el listado (agregado) — null en el detalle. */
  paidCount: number | null
  /** Próximo vencimiento pendiente — null si no hay o si es detalle. */
  nextDueDate: string | null
}

export interface FinanceLoanInstallment {
  id: string
  loanId: string
  seq: number
  dueDate: string
  amount: number
  status: LoanInstallmentStatus
  paidDate: string | null
  movementId: string | null
}

export interface FinanceLoanDetail extends FinanceLoan {
  installments: FinanceLoanInstallment[]
}

export interface FinanceLoanListResponse {
  rows: FinanceLoan[]
  total: number
}

export interface FinanceLoanFilters {
  status?: LoanStatus
  limit?: number
  offset?: number
}

export interface FinanceLoanFormValues {
  name: string
  principal: number
  installmentCount: number
  firstDueDate: string
}

function buildQuery(filters: FinanceLoanFilters): string {
  const qs = new URLSearchParams()
  if (filters.status) qs.set("status", filters.status)
  if (filters.limit) qs.set("limit", String(filters.limit))
  if (filters.offset) qs.set("offset", String(filters.offset))
  return qs.toString()
}

export function useFinanceLoans(filters: FinanceLoanFilters = {}) {
  const query = buildQuery(filters)
  return useQuery<FinanceLoanListResponse>({
    queryKey: ["finance", "loans", filters],
    queryFn: () => api.get<FinanceLoanListResponse>(`/v1/finance/loans${query ? "?" + query : ""}`),
    staleTime: 15_000,
  })
}

export function useFinanceLoan(id: string | null) {
  return useQuery<FinanceLoanDetail>({
    queryKey: ["finance", "loans", "detail", id],
    queryFn: () => api.get<FinanceLoanDetail>(`/v1/finance/loans?id=${id}`),
    enabled: !!id,
    staleTime: 15_000,
  })
}

function invalidateAfterLoanMutation(qc: ReturnType<typeof useQueryClient>) {
  qc.invalidateQueries({ queryKey: ["finance", "loans"] })
  qc.invalidateQueries({ queryKey: ["finance", "movements"] })
  qc.invalidateQueries({ queryKey: ["finance", "accounts"] })
  qc.invalidateQueries({ queryKey: ["finance", "forecast"] })
}

export function useCreateFinanceLoan() {
  const qc = useQueryClient()
  return useMutation<FinanceLoanDetail, Error, FinanceLoanFormValues>({
    mutationFn: (payload) =>
      api.post<FinanceLoanDetail>("/v1/finance/loans", payload as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "loans"] })
      qc.invalidateQueries({ queryKey: ["finance", "forecast"] })
    },
  })
}

/** Anula el crédito (status=cancelled). No revierte cuotas ya pagadas. */
export function useCancelFinanceLoan() {
  const qc = useQueryClient()
  return useMutation<FinanceLoanDetail, Error, string>({
    mutationFn: (id) => api.put<FinanceLoanDetail>(`/v1/finance/loans?id=${id}&resource=cancel`, {}),
    onSuccess: () => {
      invalidateAfterLoanMutation(qc)
    },
  })
}

/** Marca una cuota como pagada — genera el movimiento (expense) desde la cuenta indicada. */
export function usePayLoanInstallment() {
  const qc = useQueryClient()
  return useMutation<FinanceLoanInstallment, Error, { installmentId: string; accountId: string }>({
    mutationFn: ({ installmentId, accountId }) =>
      api.put<FinanceLoanInstallment>(`/v1/finance/loans?installmentId=${installmentId}&resource=pay`, {
        accountId,
      }),
    onSuccess: () => {
      invalidateAfterLoanMutation(qc)
    },
  })
}

/** Revierte el pago de una cuota (se desmarca) — anula el movimiento asociado. */
export function useUnpayLoanInstallment() {
  const qc = useQueryClient()
  return useMutation<FinanceLoanInstallment, Error, string>({
    mutationFn: (installmentId) =>
      api.put<FinanceLoanInstallment>(`/v1/finance/loans?installmentId=${installmentId}&resource=unpay`, {}),
    onSuccess: () => {
      invalidateAfterLoanMutation(qc)
    },
  })
}
