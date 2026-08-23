"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { posApi as api } from "@/lib/api/pos-client"

/**
 * Cambia sucursal/caja del device actual sin re-emitir JWT ni revocar el pairing.
 *
 * El cajero usa esto desde "Ajustes del POS" para moverse entre cajas del tenant
 * sin pedirle al admin que genere un link nuevo. UPDATE en device row + invalidate
 * de los queries del bootstrap → el catalog store re-hidrata con el nuevo contexto.
 *
 * Auth: realm pos-app (el endpoint resuelve el deviceId del JWT del propio device).
 */
export function useUpdateDeviceContext() {
  const qc = useQueryClient()
  return useMutation<
    { registerId: string; registerName: string },
    Error,
    { registerId: string; outletId?: string }
  >({
    mutationFn: (body) =>
      api.post<{ registerId: string; registerName: string }>(
        "/v1/active-register",
        body,
      ),
    onSuccess: () => {
      // Una sola query de bootstrap desde 2026-08-23: `["pos-bootstrap-auth"]`
      // (la del guard) se fusionó con esta. Ver `hooks/use-pos-bootstrap.ts`.
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
    },
  })
}
