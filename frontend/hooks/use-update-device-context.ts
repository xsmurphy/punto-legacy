"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { posApi as api } from "@/lib/api/pos-client"
import { resetContextScopedState } from "@/lib/pos/context-reset"

/**
 * Cambia sucursal/caja del device actual sin re-emitir JWT ni revocar el pairing.
 *
 * El cajero usa esto desde "Ajustes del POS" para moverse entre cajas del tenant
 * sin pedirle al admin que genere un link nuevo. UPDATE en device row + invalidate
 * de los queries del bootstrap → el catalog store re-hidrata con el nuevo contexto.
 *
 * Auth: realm pos-app (el endpoint resuelve el deviceId del JWT del propio device).
 *
 * Reset del contexto (2026-08-24)
 * ───────────────────────────────
 * Invalidar el bootstrap re-siembra el catalog store, pero NO tocaba el resto
 * del estado del cliente: la venta en curso, los diálogos con un ítem del
 * catálogo viejo y la grilla de hotkeys de la caja anterior sobrevivían al
 * cambio. El carrito abierto pertenece a una sucursal+caja concretas —sus
 * precios, su stock y su numeración son de ahí—, así que arrastrarlo a otra
 * caja es emitir con las dimensiones equivocadas.
 *
 * El vaciado va acá, en el motor del cambio, y no en el `<Select>` de Ajustes:
 * así cualquier call-site que mueva el contexto lo hereda. Lo único que queda
 * del lado de la UI es PREGUNTAR antes de descartar — para eso está
 * `hasContextScopedWork()`.
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
      // Primero se descarta lo del contexto viejo y recién después se pide el
      // nuevo: al revés habría un instante con el carrito de la sucursal
      // anterior sobre el catálogo ya re-hidratado de la nueva.
      resetContextScopedState(qc)
      // Una sola query de bootstrap desde 2026-08-23: `["pos-bootstrap-auth"]`
      // (la del guard) se fusionó con esta. Ver `hooks/use-pos-bootstrap.ts`.
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
    },
  })
}
