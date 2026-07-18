"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { RegisterWastePayload, WasteEvent, WasteListFilters } from "@/lib/types/production"

function filtersToParams(filters?: WasteListFilters): string {
  const params = new URLSearchParams()
  if (filters?.from) params.set("from", filters.from)
  if (filters?.to) params.set("to", filters.to)
  if (filters?.reasonId) params.set("reasonId", filters.reasonId)
  if (filters?.outletId) params.set("outletId", filters.outletId)
  const qs = params.toString()
  return qs ? `?${qs}` : ""
}

export function useWasteEvents(filters?: WasteListFilters) {
  return useQuery<{ wasteEvents: WasteEvent[] }>({
    queryKey: ["waste-events", filters ?? {}],
    queryFn: () => api.get(`/v1/waste${filtersToParams(filters)}`),
    staleTime: 15 * 1000,
  })
}

export function useRegisterWaste() {
  const qc = useQueryClient()
  return useMutation<{ id: string }, Error, RegisterWastePayload>({
    mutationFn: (body) =>
      api.post<{ id: string }>("/v1/waste", body as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["waste-events"] })
    },
  })
}
