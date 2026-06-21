"use client"

import { useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export function useAiBalance() {
  return useQuery<{ balance: number }>({
    queryKey: ["ai-balance"],
    queryFn: () => api.get<{ balance: number }>("/v1/ai/balance"),
    staleTime: 10 * 1000,
  })
}

export function useInvalidateAiBalance() {
  const qc = useQueryClient()
  return () => {
    qc.invalidateQueries({ queryKey: ["ai-balance"] })
    qc.invalidateQueries({ queryKey: ["billing"] })
  }
}
