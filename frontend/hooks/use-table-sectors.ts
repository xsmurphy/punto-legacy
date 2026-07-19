"use client"

/**
 * Sectores del salón (table_sector, mig 80, context/15-mesas-module-plan.md
 * F0+F1). Config del panel — usa `api-client` (cookie `_jwt_panel`), no
 * `posFetch` (eso es para el POS operativo, F2).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface TableSector {
  id: string
  companyId: string
  outletId: string
  name: string
  sort: number
  createdAt: string | null
}

export interface TableSectorPayload {
  outletId?: string
  name?: string
  sort?: number
}

export function useTableSectors(outletId: string | undefined) {
  return useQuery<{ sectors: TableSector[] }>({
    queryKey: ["table-sectors", outletId],
    queryFn: () => api.get(`/v1/table-sectors?outletId=${outletId}`),
    enabled: !!outletId,
    staleTime: 30 * 1000,
  })
}

export function useCreateTableSector() {
  const qc = useQueryClient()
  return useMutation<TableSector, Error, TableSectorPayload>({
    mutationFn: (body) => api.post<TableSector>("/v1/table-sectors", body as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["table-sectors"] }),
  })
}

export function useUpdateTableSector() {
  const qc = useQueryClient()
  return useMutation<TableSector, Error, { id: string; values: TableSectorPayload }>({
    mutationFn: ({ id, values }) =>
      api.put<TableSector>(`/v1/table-sectors?id=${id}`, values as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["table-sectors"] }),
  })
}

export function useDeleteTableSector() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/table-sectors?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["table-sectors"] }),
  })
}
