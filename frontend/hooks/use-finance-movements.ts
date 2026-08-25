"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface FinanceMovement {
  id: string
  accountId: string
  accountName: string | null
  categoryId: string | null
  categoryName: string | null
  /** Código contable externo de la categoría — viaja para que el export lo lleve. */
  categoryCode: string | null
  /** Centro de costo al que se imputa. null = sin clasificar (es opcional). */
  costCenterId: string | null
  costCenterName: string | null
  costCenterCode: string | null
  kind: "income" | "expense"
  amount: number
  date: string
  description: string | null
  paymentMethod: string | null
  /** Nombre para mostrar del medio de pago — el backend ya resuelve UUID/slug legacy. */
  paymentMethodName: string | null
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
  /** UUID, o el literal "none" para traer solo los que no tienen categoría. */
  categoryId?: string
  /** UUID, o el literal "none" para traer solo los que no tienen centro de costo. */
  costCenterId?: string
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
  costCenterId?: string | null
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
  if (filters.costCenterId) qs.set("costCenterId", filters.costCenterId)
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

/**
 * RECLASIFICA un movimiento: le cambia la categoría y/o el centro de costo sin
 * tocar monto, cuenta ni tipo (el saldo no se mueve).
 *
 * Es el camino por el que se clasifica el HISTÓRICO, que es la contraparte de
 * que el centro de costo sea opcional al cargar el gasto. A diferencia de
 * anular, funciona también sobre movimientos derivados (compras, gastos de
 * caja del POS): la clasificación es una decisión administrativa posterior al
 * hecho, no una corrección del hecho.
 *
 * Solo se mandan las claves presentes — omitir `categoryId` deja la categoría
 * como está en vez de borrarla.
 */
export function useReclassifyFinanceMovement() {
  const qc = useQueryClient()
  return useMutation<
    FinanceMovement,
    Error,
    { id: string; categoryId?: string | null; costCenterId?: string | null }
  >({
    mutationFn: ({ id, ...values }) =>
      api.put<FinanceMovement>(
        `/v1/finance/movements?id=${id}`,
        values as unknown as Record<string, unknown>,
      ),
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
