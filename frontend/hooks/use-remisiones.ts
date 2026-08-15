"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/** Espejo de `Punto\Api\Documents\RemisionMotivo` (api/lib/Documents/RemisionMotivo.php).
 *  'traslado_interno' NO está acá — ese motivo vive en /stock-transfer. */
export type RemisionMotivo = "venta" | "devolucion_proveedor" | "consignacion" | "exposicion" | "compra"

export const REMISION_MOTIVO_LABELS: Record<RemisionMotivo, string> = {
  venta: "Venta",
  devolucion_proveedor: "Devolución a proveedor",
  consignacion: "Consignación",
  exposicion: "Exposición / demostración",
  compra: "Traslado por compra",
}

export const REMISION_MOTIVO_OPTIONS: Array<{ value: RemisionMotivo; label: string }> = (
  Object.keys(REMISION_MOTIVO_LABELS) as RemisionMotivo[]
).map((value) => ({ value, label: REMISION_MOTIVO_LABELS[value] }))

export interface Remision {
  remisionId: string
  docNumber: number | null
  motivo: RemisionMotivo
  status: 0 | 1
  transferDate: string
  note: string | null
  outletId: string
  outletName: string
  locationId: string | null
  locationName: string | null
  destinationContactId: string | null
  destinationContactName: string | null
  destinationNote: string | null
  transactionId: string | null
  itemsCount: number
}

export interface RemisionItem {
  remisionItemId: string
  itemId: string
  name: string
  sku: string | null
  qty: number
  note: string | null
}

export interface RemisionDetail {
  remision: Remision & { companyId: string; createdBy: string; createdByName: string | null; createdAt: string }
  items: RemisionItem[]
}

export interface RemisionFilters {
  outletId?: string
  motivo?: RemisionMotivo
  status?: number
  dateFrom?: string
  dateTo?: string
  limit?: number
  offset?: number
}

export function useRemisiones(filters?: RemisionFilters) {
  return useQuery<{ rows: Remision[]; total: number }>({
    queryKey: ["remisiones", filters ?? {}],
    queryFn: () => {
      const params = new URLSearchParams({ action: "list" })
      if (filters?.outletId) params.set("outletId", filters.outletId)
      if (filters?.motivo)   params.set("motivo",   filters.motivo)
      if (filters?.status !== undefined) params.set("status", String(filters.status))
      if (filters?.dateFrom) params.set("dateFrom", filters.dateFrom)
      if (filters?.dateTo)   params.set("dateTo",   filters.dateTo)
      if (filters?.limit)    params.set("limit",    String(filters.limit))
      if (filters?.offset)   params.set("offset",   String(filters.offset))
      return api.get(`/v1/remisiones?${params.toString()}`)
    },
    staleTime: 30 * 1000,
  })
}

export function useRemision(id: string | null) {
  return useQuery<RemisionDetail>({
    queryKey: ["remisiones", id],
    queryFn: () => api.get(`/v1/remisiones?action=get&id=${id}`),
    enabled: !!id,
    staleTime: 10 * 1000,
  })
}

export function useCreateRemision() {
  const qc = useQueryClient()
  return useMutation<
    { id: string; docNumber: number },
    Error,
    {
      motivo: RemisionMotivo
      outletId: string
      locationId?: string | null
      destinationContactId?: string | null
      destinationNote?: string | null
      transactionId?: string | null
      transferDate?: string | null
      note?: string
      items: Array<{ itemId: string; qty: number; note?: string }>
    }
  >({
    mutationFn: (data) => api.post("/v1/remisiones", { action: "create", ...data }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["remisiones"] })
    },
  })
}

export function useCancelRemision() {
  const qc = useQueryClient()
  return useMutation<{ ok: boolean }, Error, { id: string }>({
    mutationFn: ({ id }) => api.post("/v1/remisiones", { action: "cancel", id }),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ["remisiones"] })
      qc.invalidateQueries({ queryKey: ["remisiones", vars.id] })
    },
  })
}
