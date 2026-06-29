"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export function useVoidTransaction() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, motive }: { id: string; motive?: string }) =>
      api.put(`/v1/transactions?resource=void&id=${encodeURIComponent(id)}`, {
        motive: motive ?? "",
      }),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ["pos-transactions"] })
      qc.invalidateQueries({ queryKey: ["pos-transaction", vars.id] })
    },
  })
}
