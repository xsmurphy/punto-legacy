"use client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * API keys del MCP (M0 de `context/58`).
 *
 * Separadas de `use-sessions` a propósito, aunque por debajo compartan tabla:
 * una sesión de panel o POS se REVOCA y nada más —nadie la crea desde una
 * pantalla—, mientras que una key se EMITE, y su token existe en texto plano
 * una sola vez. Son verbos distintos y la UI lo refleja.
 */
export interface ApiKey {
  id: string
  name: string
  createdAt: string
  lastSeenAt: string
  expiresAt: string
  userId: string
  revoked: boolean
  /** Derivado en el backend: vencida sigue con status=1, pero ya no autentica. */
  expired: boolean
}

export function useApiKeys(opts: { showRevoked?: boolean } = {}) {
  const qs = opts.showRevoked ? "?showRevoked=1" : ""
  return useQuery<ApiKey[]>({
    queryKey: ["api-keys", { showRevoked: !!opts.showRevoked }],
    queryFn: async () => {
      const res = await api.get<{ keys: ApiKey[] }>(`/v1/api-keys${qs}`)
      return res.keys ?? []
    },
    staleTime: 30_000,
  })
}

/** El `token` del resultado es la ÚNICA vez que existe: no hay endpoint que lo relea. */
export interface IssuedApiKey {
  token: string
  name: string
  expiresAt: string
}

export function useIssueApiKey() {
  const qc = useQueryClient()
  return useMutation<IssuedApiKey, Error, { name: string; ttlDays?: number }>({
    mutationFn: (body) => api.post<IssuedApiKey>("/v1/api-keys", body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["api-keys"] }),
  })
}

export function useRevokeApiKey() {
  const qc = useQueryClient()
  return useMutation<{ revoked: boolean }, Error, string>({
    mutationFn: (id) => api.del<{ revoked: boolean }>(`/v1/api-keys?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["api-keys"] }),
  })
}
