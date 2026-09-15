"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api, type HttpClient } from "@/lib/api-client"
import type { Tag, TagPayload } from "@/lib/types/tag"

/**
 * CRUD de etiquetas de producto (Slice 4 del refactor taxonomy).
 *
 * Endpoint /v1/tags — tabla `tag` dedicada (mig 39), que los triggers PG
 * mantienen en sync con `taxonomy WHERE type='tag'`.
 *
 * Consumida por el panel (settings/catálogo, ficha de ítem) y por el POS
 * (`sale-options-drawer.tsx`, sugerencias del diálogo de etiquetas de la
 * venta). El POS inyecta `client: posApi` (Bearer del device); el panel usa
 * el default `api`. Ver invariante de realm en `lib/api-client.ts` — un
 * cliente HTTP habla UN realm. Con el default, en la caja esta query
 * respondía 401 y el cajero se quedaba sin sugerencias, que es lo que
 * multiplica variantes de la misma etiqueta.
 */
export function useTags(opts: { client?: HttpClient } = {}) {
  const client = opts.client ?? api
  return useQuery<{ tags: Tag[] }>({
    queryKey: ["tags", client === api ? "panel" : "pos"],
    queryFn: () => client.get<{ tags: Tag[] }>("/v1/tags"),
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
