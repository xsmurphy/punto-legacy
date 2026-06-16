"use client"

/**
 * Mutation para elegir la caja activa en el POS (Slice A7).
 *
 * Hace POST /v1/active-register con el registerId elegido. El backend
 * re-emite la cookie `_jwt_panel` con el claim `rid` actualizado.
 *
 * onSuccess invalida la query "pos-bootstrap" para que el bootstrap se
 * refetchee con el nuevo rid — el guard de caja (PosWorkspaceLayout) cierra
 * el selector cuando detecta que activeRegisterId ya no está vacío.
 *
 * Patrón idéntico a `useSetActiveOutlet` en `hooks/use-bootstrap.ts`.
 */

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

interface ActiveRegisterResponse {
  registerId: string
  registerName: string
  expiresIn: number
}

export function useSetActiveRegister() {
  const qc = useQueryClient()
  return useMutation<ActiveRegisterResponse, Error, string>({
    mutationFn: (registerId) =>
      api.post<ActiveRegisterResponse>("/v1/active-register", { registerId }),
    onSuccess: () => {
      // Invalidar el bootstrap del POS para que se refetchee con el nuevo rid.
      // El guard detecta activeRegisterId != '' y cierra el selector.
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
    },
  })
}
