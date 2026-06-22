"use client"

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface OutletLocation {
  id: string
  name: string
  outletId: string | null
}

export function useOutletLocations(outletId: string | null) {
  return useQuery<OutletLocation[]>({
    queryKey: ["outlet-locations", outletId],
    queryFn: async () => {
      const data = await api.get<{ taxonomies: OutletLocation[] }>(
        "/v1/taxonomies?type=location"
      )
      return (data.taxonomies ?? []).filter((t) => t.outletId === outletId)
    },
    enabled: !!outletId,
  })
}

export function useCreateLocation(outletId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (name: string) =>
      api.post("/v1/taxonomies", { action: "create", type: "location", outletId, name }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["outlet-locations", outletId] }),
  })
}

export function useUpdateLocation(outletId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) =>
      api.post("/v1/taxonomies", { action: "update", type: "location", id, name }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["outlet-locations", outletId] }),
  })
}

export function useDeleteLocation(outletId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) =>
      api.post("/v1/taxonomies", { action: "delete", type: "location", id }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["outlet-locations", outletId] }),
  })
}
