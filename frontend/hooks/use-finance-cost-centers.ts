"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * Centro de costo — a qué centro se imputa un gasto (una sucursal, un área,
 * una obra). Lista PLANA: a diferencia de `FinanceCategory` no tiene `parentId`
 * ni `kind`. Ver mig 167.
 */
export interface FinanceCostCenter {
  id: string
  name: string
  /** Código contable externo — matchea contra el plan de cuentas del contador. */
  code: string | null
  sortOrder: number
  status: number
}

export interface FinanceCostCenterFormValues {
  name: string
  code?: string | null
  sortOrder?: number
}

/**
 * Lista de centros de costo ACTIVOS del comercio. Sin auto-seed: arranca
 * vacía hasta que el comercio cargue los suyos desde /finanzas/configuracion.
 */
export function useFinanceCostCenters() {
  return useQuery<FinanceCostCenter[]>({
    queryKey: ["finance", "cost-centers"],
    queryFn: () => api.get<FinanceCostCenter[]>("/v1/finance/cost-centers"),
    staleTime: 30_000,
  })
}

export function useCreateFinanceCostCenter() {
  const qc = useQueryClient()
  return useMutation<FinanceCostCenter, Error, FinanceCostCenterFormValues>({
    mutationFn: (values) =>
      api.post<FinanceCostCenter>(
        "/v1/finance/cost-centers",
        values as unknown as Record<string, unknown>,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "cost-centers"] })
    },
  })
}

export function useUpdateFinanceCostCenter() {
  const qc = useQueryClient()
  return useMutation<FinanceCostCenter, Error, FinanceCostCenterFormValues & { id: string }>({
    mutationFn: ({ id, ...values }) =>
      api.put<FinanceCostCenter>(
        `/v1/finance/cost-centers?id=${id}`,
        values as unknown as Record<string, unknown>,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "cost-centers"] })
    },
  })
}

/**
 * Archiva (soft-delete) un centro de costo. Los movimientos ya imputados lo
 * conservan — deja de ofrecerse para imputaciones nuevas, nada más.
 */
export function useArchiveFinanceCostCenter() {
  const qc = useQueryClient()
  return useMutation<{ id: string; status: number }, Error, string>({
    mutationFn: (id) => api.del<{ id: string; status: number }>(`/v1/finance/cost-centers?id=${id}`),
    onSuccess: () => {
      // Invalida "finance" entero, no solo la lista: los movimientos traen el
      // nombre del centro resuelto por JOIN y quedan desactualizados.
      qc.invalidateQueries({ queryKey: ["finance"] })
    },
  })
}
