"use client"

/**
 * Sectores del local (space_sector, mig 80, context/15-espacios-module-plan.md
 * F0+F1). Config del panel — usa `api-client` (cookie `_jwt_panel`), no
 * `posFetch` (eso es para el POS operativo, F2).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface SpaceSector {
  id: string
  companyId: string
  outletId: string
  name: string
  sort: number
  createdAt: string | null
}

export interface SpaceSectorPayload {
  outletId?: string
  name?: string
  sort?: number
}

export function useSpaceSectors(outletId: string | undefined) {
  return useQuery<{ sectors: SpaceSector[] }>({
    queryKey: ["space-sectors", outletId],
    queryFn: () => api.get(`/v1/space-sectors?outletId=${outletId}`),
    enabled: !!outletId,
    staleTime: 30 * 1000,
  })
}

export function useCreateSpaceSector() {
  const qc = useQueryClient()
  return useMutation<SpaceSector, Error, SpaceSectorPayload>({
    mutationFn: (body) => api.post<SpaceSector>("/v1/space-sectors", body as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["space-sectors"] }),
  })
}

export function useUpdateSpaceSector() {
  const qc = useQueryClient()
  return useMutation<SpaceSector, Error, { id: string; values: SpaceSectorPayload }>({
    mutationFn: ({ id, values }) =>
      api.put<SpaceSector>(`/v1/space-sectors?id=${id}`, values as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["space-sectors"] }),
  })
}

export function useDeleteSpaceSector() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/space-sectors?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["space-sectors"] }),
  })
}
