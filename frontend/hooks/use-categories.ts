"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { Category, CategoryPayload } from "@/lib/types/category"

/**
 * CRUD de categorías de producto (Slice 1 del refactor taxonomy).
 *
 * Endpoint /v1/categories — tabla `category` dedicada. El POS legacy sigue
 * leyendo de `taxonomy` con sync automático vía triggers PG.
 */
export function useCategories() {
  return useQuery<{ categories: Category[] }>({
    queryKey: ["categories"],
    queryFn: () => api.get("/v1/categories"),
    staleTime: 5 * 60 * 1000,
  })
}

export function useCategory(id: string | undefined) {
  return useQuery<Category>({
    queryKey: ["categories", id],
    queryFn: () => api.get<Category>(`/v1/categories?id=${id}`),
    enabled: !!id,
    staleTime: 60 * 1000,
  })
}

export function useCreateCategory() {
  const qc = useQueryClient()
  return useMutation<Category, Error, CategoryPayload>({
    mutationFn: (body) =>
      api.post<Category>("/v1/categories", body as unknown as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["categories"] }),
  })
}

export function useUpdateCategory() {
  const qc = useQueryClient()
  return useMutation<Category, Error, { id: string; values: Partial<CategoryPayload> }>({
    mutationFn: ({ id, values }) =>
      api.put<Category>(`/v1/categories?id=${id}`, values as unknown as Record<string, unknown>),
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: ["categories"] })
      qc.invalidateQueries({ queryKey: ["categories", id] })
    },
  })
}

export function useDeleteCategory() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/categories?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["categories"] }),
  })
}
