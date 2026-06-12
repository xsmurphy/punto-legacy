"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { Brand, BrandPayload } from "@/lib/types/brand"

/**
 * CRUD de marcas de producto (Slice 2 del refactor taxonomy).
 *
 * Endpoint /v1/brands — tabla `brand` dedicada. El POS sigue leyendo de
 * `taxonomy` con sync automático vía triggers PG.
 */
export function useBrands() {
  return useQuery<{ brands: Brand[] }>({
    queryKey: ["brands"],
    queryFn: () => api.get("/v1/brands"),
    staleTime: 5 * 60 * 1000,
  })
}

export function useBrand(id: string | undefined) {
  return useQuery<Brand>({
    queryKey: ["brands", id],
    queryFn: () => api.get<Brand>(`/v1/brands?id=${id}`),
    enabled: !!id,
    staleTime: 60 * 1000,
  })
}

export function useCreateBrand() {
  const qc = useQueryClient()
  return useMutation<Brand, Error, BrandPayload>({
    mutationFn: (body) =>
      api.post<Brand>("/v1/brands", body as unknown as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["brands"] }),
  })
}

export function useUpdateBrand() {
  const qc = useQueryClient()
  return useMutation<Brand, Error, { id: string; values: Partial<BrandPayload> }>({
    mutationFn: ({ id, values }) =>
      api.put<Brand>(`/v1/brands?id=${id}`, values as unknown as Record<string, unknown>),
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: ["brands"] })
      qc.invalidateQueries({ queryKey: ["brands", id] })
    },
  })
}

export function useDeleteBrand() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/brands?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["brands"] }),
  })
}
