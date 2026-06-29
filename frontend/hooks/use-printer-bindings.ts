"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { PrinterBinding } from "@/lib/hardware/printers/binding"

export function usePrinterBindings(registerId: string | undefined) {
  return useQuery<{ bindings: PrinterBinding[] }>({
    queryKey: ["printer-bindings", registerId],
    queryFn: () => api.get(`/v1/printer_binding?registerId=${registerId}`),
    staleTime: 30 * 1000,
    enabled: !!registerId,
  })
}

type CreatePayload = Omit<PrinterBinding, "id" | "createdAt" | "updatedAt"> & { registerId: string }
type UpdatePayload = Partial<Omit<PrinterBinding, "id" | "createdAt" | "updatedAt">> & { id: string }

export function useCreatePrinterBinding(registerId: string | undefined) {
  const qc = useQueryClient()
  return useMutation<{ binding: PrinterBinding }, Error, CreatePayload>({
    mutationFn: (data) =>
      api.post("/v1/printer_binding", { action: "create", ...data } as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["printer-bindings", registerId] }),
  })
}

export function useUpdatePrinterBinding(registerId: string | undefined) {
  const qc = useQueryClient()
  return useMutation<{ binding: PrinterBinding }, Error, UpdatePayload>({
    mutationFn: (data) =>
      api.post("/v1/printer_binding", { action: "update", ...data } as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["printer-bindings", registerId] }),
  })
}

export function useDeletePrinterBinding(registerId: string | undefined) {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.post("/v1/printer_binding", { action: "delete", id }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["printer-bindings", registerId] }),
  })
}
