"use client"

/**
 * Mutation para elegir la caja activa en el POS.
 *
 * Hace POST /v1/active-register con el registerId elegido. El backend
 * actualiza la fila `device` — ya NO re-emite cookie _jwt_panel.
 * El contexto operativo (outletId/registerId) se resuelve desde la fila
 * device en cada request subsiguiente.
 *
 * onSuccess invalida "pos-bootstrap" Y "pos-config" para que el bootstrap
 * se refetchee con la nueva caja — el guard de caja (PosWorkspaceLayout)
 * cierra el selector cuando detecta que activeRegisterId ya no está vacío.
 */

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

interface ActiveRegisterResponse {
  registerId: string
  registerName: string
}

export function useSetActiveRegister() {
  const qc = useQueryClient()
  return useMutation<ActiveRegisterResponse, Error, string>({
    mutationFn: (registerId) =>
      api.post<ActiveRegisterResponse>("/v1/active-register", { registerId }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
      qc.invalidateQueries({ queryKey: ["pos-config"] })
    },
  })
}
