"use client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface AuthSession {
  sessionId: string
  realm: string
  userId: string | null
  deviceId: string | null
  outletId: string | null
  module: string | null
  status: number
  createdAt: string | null
  lastSeenAt: string | null
  expiresAt: string | null
  ipLast: string | null
  userAgent: string | null
  userName: string | null
  outletName: string | null
  deviceName: string | null
}

export function useSessions(opts: { showRevoked?: boolean } = {}) {
  const qs = opts.showRevoked ? "?showRevoked=1" : ""
  return useQuery<AuthSession[]>({
    queryKey: ["auth-sessions", { showRevoked: !!opts.showRevoked }],
    queryFn: async () => {
      const res = await api.get<{ sessions: AuthSession[] }>(`/v1/sessions${qs}`)
      return res.sessions ?? []
    },
    staleTime: 30_000,
  })
}

export function useRevokeSession() {
  const qc = useQueryClient()
  return useMutation<{ ok: boolean }, Error, string>({
    mutationFn: (sessionId) => api.del<{ ok: boolean }>(`/v1/sessions?id=${sessionId}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["auth-sessions"] }),
  })
}
