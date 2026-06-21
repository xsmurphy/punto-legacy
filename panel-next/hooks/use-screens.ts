"use client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface Screen {
  id: string
  name: string
  registerId: string
  registerName: string | null
  ipLast: string | null
  lastSeenAt: string | null
  status: 0 | 1
  createdAt: string
}

export function useScreens() {
  return useQuery<{ screens: Screen[] }>({
    queryKey: ["screens"],
    queryFn: () => api.get("/v1/screens"),
    staleTime: 30 * 1000,
  })
}

export function usePairScreen() {
  const qc = useQueryClient()
  return useMutation<{ ok: true; id: string }, Error, { pin: string; name: string }>({
    mutationFn: (body) => api.post("/v1/screens?resource=pair", body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["screens"] }),
  })
}

export function useRevokeScreen() {
  const qc = useQueryClient()
  return useMutation<{ ok: true }, Error, string>({
    mutationFn: (id) => api.del(`/v1/screens?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["screens"] }),
  })
}
