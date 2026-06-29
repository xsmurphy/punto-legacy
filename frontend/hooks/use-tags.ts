"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { Tag, TagPayload } from "@/lib/types/tag"

/**
 * CRUD de etiquetas de producto (Slice 4 del refactor taxonomy).
 *
 * Endpoint /v1/tags — tabla `tag` dedicada (mig 39). El POS sigue leyendo de
 * `taxonomy WHERE type='tag'` con sync automático vía triggers PG.
 */
export function useTags() {
  return useQuery<{ tags: Tag[] }>({
    queryKey: ["tags"],
    queryFn: () => api.get("/v1/tags"),
    staleTime: 5 * 60 * 1000,
  })
}

export function useTag(id: string | undefined) {
  return useQuery<Tag>({
    queryKey: ["tags", id],
    queryFn: () => api.get<Tag>(`/v1/tags?id=${id}`),
    enabled: !!id,
    staleTime: 60 * 1000,
  })
}

export function useCreateTag() {
  const qc = useQueryClient()
  return useMutation<Tag, Error, TagPayload>({
    mutationFn: (body) =>
      api.post<Tag>("/v1/tags", body as unknown as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["tags"] }),
  })
}

export function useUpdateTag() {
  const qc = useQueryClient()
  return useMutation<Tag, Error, { id: string; values: Partial<TagPayload> }>({
    mutationFn: ({ id, values }) =>
      api.put<Tag>(`/v1/tags?id=${id}`, values as unknown as Record<string, unknown>),
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: ["tags"] })
      qc.invalidateQueries({ queryKey: ["tags", id] })
    },
  })
}

export function useDeleteTag() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/tags?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["tags"] }),
  })
}
