"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export type CheckDirection = "issued" | "received"
export type CheckStatus = "pending" | "deposited" | "cleared" | "bounced" | "cancelled"

export interface FinanceCheck {
  id: string
  direction: CheckDirection
  accountId: string | null
  accountName: string | null
  bankName: string | null
  checkNumber: string | null
  amount: number
  issueDate: string
  dueDate: string | null
  contactId: string | null
  partyName: string | null
  categoryId: string | null
  categoryName: string | null
  status: CheckStatus
  clearedDate: string | null
  description: string | null
}

export interface FinanceCheckListResponse {
  rows: FinanceCheck[]
  total: number
}

export interface FinanceCheckFilters {
  direction?: CheckDirection
  status?: CheckStatus
  from?: string
  to?: string
  limit?: number
  offset?: number
}

export interface FinanceCheckFormValues {
  direction: CheckDirection
  accountId?: string | null
  bankName?: string
  checkNumber?: string
  amount: number
  issueDate?: string
  dueDate?: string
  contactId?: string | null
  partyName?: string
  categoryId?: string | null
  description?: string
}

function buildQuery(filters: FinanceCheckFilters): string {
  const qs = new URLSearchParams()
  if (filters.direction) qs.set("direction", filters.direction)
  if (filters.status) qs.set("status", filters.status)
  if (filters.from) qs.set("from", filters.from)
  if (filters.to) qs.set("to", filters.to)
  if (filters.limit) qs.set("limit", String(filters.limit))
  if (filters.offset) qs.set("offset", String(filters.offset))
  return qs.toString()
}

export function useFinanceChecks(filters: FinanceCheckFilters = {}) {
  const query = buildQuery(filters)
  return useQuery<FinanceCheckListResponse>({
    queryKey: ["finance", "checks", filters],
    queryFn: () => api.get<FinanceCheckListResponse>(`/v1/finance/checks${query ? "?" + query : ""}`),
    staleTime: 15_000,
  })
}

function invalidateAfterCheckMutation(qc: ReturnType<typeof useQueryClient>) {
  qc.invalidateQueries({ queryKey: ["finance", "checks"] })
  qc.invalidateQueries({ queryKey: ["finance", "movements"] })
  qc.invalidateQueries({ queryKey: ["finance", "accounts"] })
}

export function useCreateFinanceCheck() {
  const qc = useQueryClient()
  return useMutation<FinanceCheck, Error, FinanceCheckFormValues>({
    mutationFn: (payload) => api.post<FinanceCheck>("/v1/finance/checks", payload as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "checks"] })
    },
  })
}

export function useUpdateFinanceCheck() {
  const qc = useQueryClient()
  return useMutation<FinanceCheck, Error, { id: string; values: Partial<FinanceCheckFormValues> }>({
    mutationFn: ({ id, values }) =>
      api.put<FinanceCheck>(`/v1/finance/checks?id=${id}`, values as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "checks"] })
    },
  })
}

/** Cambia el estado del cheque. Puede generar (cleared) o revertir un movimiento asociado. */
export function useChangeFinanceCheckStatus() {
  const qc = useQueryClient()
  return useMutation<FinanceCheck, Error, { id: string; status: CheckStatus }>({
    mutationFn: ({ id, status }) =>
      api.put<FinanceCheck>(`/v1/finance/checks?id=${id}&resource=status`, { status }),
    onSuccess: () => {
      invalidateAfterCheckMutation(qc)
    },
  })
}

/** Anula el cheque (status=cancelled). Si tenía movimiento generado, se revierte. */
export function useDeleteFinanceCheck() {
  const qc = useQueryClient()
  return useMutation<FinanceCheck, Error, string>({
    mutationFn: (id) => api.del<FinanceCheck>(`/v1/finance/checks?id=${id}`),
    onSuccess: () => {
      invalidateAfterCheckMutation(qc)
    },
  })
}
