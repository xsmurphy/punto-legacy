"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { WasteReason, WasteReasonPayload } from "@/lib/types/production"

/**
 * CRUD de motivos de merma del tenant. Endpoint /v1/waste-reasons (tabla
 * taxonomy, taxonomyType='wasteReason'). El backend auto-seedea 5 defaults
 * (Vencimiento/Rotura-Daño/Error de producción/Pérdida-Robo/Otro) en el
 * primer GET si el tenant no tiene ninguno.
 */
export function useWasteReasons() {
  return useQuery<{ wasteReasons: WasteReason[] }>({
    queryKey: ["waste-reasons"],
    queryFn: () => api.get("/v1/waste-reasons"),
    staleTime: 5 * 60 * 1000,
  })
}

export function useCreateWasteReason() {
  const qc = useQueryClient()
  return useMutation<WasteReason, Error, WasteReasonPayload>({
    mutationFn: (body) =>
      api.post<WasteReason>("/v1/waste-reasons", body as unknown as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["waste-reasons"] }),
  })
}

export function useUpdateWasteReason() {
  const qc = useQueryClient()
  return useMutation<WasteReason, Error, { id: string; values: Partial<WasteReasonPayload> }>({
    mutationFn: ({ id, values }) =>
      api.put<WasteReason>(
        `/v1/waste-reasons?id=${id}`,
        values as unknown as Record<string, unknown>,
      ),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["waste-reasons"] }),
  })
}

export function useDeleteWasteReason() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/waste-reasons?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["waste-reasons"] }),
  })
}
