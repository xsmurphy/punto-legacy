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
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["pos-transactions"] })
      // SIN el id: el detalle se cachea bajo `enc(transactionId)` y acá
      // `vars.id` es el UUID crudo, así que `["pos-transaction", vars.id]`
      // NUNCA matcheaba la entrada abierta — el menú seguía decidiendo sobre
      // el detalle anterior a la anulación que acababa de hacer el operador.
      // El key parcial matchea por prefijo cualquier id abierto (mismo
      // criterio que `useVoidSale` y el mapa de realtime).
      qc.invalidateQueries({ queryKey: ["pos-transaction"] })
      // Para ventas contado/crédito este endpoint delega en SaleVoidService:
      // mueve stock, caja y reportes igual que `useVoidSale`, y hasta ahora
      // no invalidaba nada de eso.
      qc.invalidateQueries({ queryKey: ["transactions"] })
      qc.invalidateQueries({ queryKey: ["transaction-detail"] })
      qc.invalidateQueries({ queryKey: ["sale-void-options"] })
      qc.invalidateQueries({ queryKey: ["stock"] })
      qc.invalidateQueries({ queryKey: ["reports"] })
      qc.invalidateQueries({ queryKey: ["dashboard"] })
    },
  })
}
