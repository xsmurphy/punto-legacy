"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
// `/v1/transactions` autentica con `apiAuthPosContext()` (SOLO Bearer del
// device, y además exige module='pos'): la anulación viaja por el cliente del
// POS. Con el cliente de panel era 401 seguro — la anulación fallaba y, peor,
// el interceptor desemparejaba la caja (ver api-client.ts, incidente 2026-07-29).
// Corolario conocido: anular desde el PANEL sigue sin ser posible; el backend
// no acepta ese realm todavía.
import { posApi as api } from "@/lib/api/pos-client"

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
