"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * Necesidades de reposición (context/70 §B.5, mig 228).
 *
 * Nacen solas —stock mínimo o conteo— y se cubren desde el panel produciendo
 * o transfiriendo. Lo cubierto lo calcula el backend a partir del documento
 * vinculado; acá no se deriva nada.
 */

export type ReplenishmentStatus = "open" | "covered" | "closed"
export type ReplenishmentOrigin = "min_stock" | "count_panel" | "count_register"

export interface ReplenishmentCoverage {
  coverageId: string
  sourceType: "production_order" | "stock_transfer"
  sourceId: string
  docNumber: number | null
  /** production_order: draft | in_progress | completed | cancelled.
   *  stock_transfer: done | cancelled. */
  sourceStatus: string
  /** Cantidad que se planeó cubrir con este documento. */
  quantity: number
  /** Cantidad que el documento efectivamente cubrió. */
  effective: number
  createdAt: string
}

export interface ReplenishmentNeed {
  needId: string
  outletId: string
  outletName: string
  itemId: string
  itemName: string
  quantity: number
  origin: ReplenishmentOrigin
  sourceId: string | null
  onHandAt: number | null
  status: ReplenishmentStatus
  closeReason: string | null
  createdBy: string | null
  createdByName: string | null
  closedByName: string | null
  createdAt: string
  coveredAt: string | null
  closedAt: string | null
  /** Tiene receta: se puede cubrir produciendo. */
  producible: boolean
  covered: number
  /** Planeado en órdenes de producción todavía no completadas. */
  inFlight: number
  pending: number
  coverages: ReplenishmentCoverage[]
}

export interface ReplenishmentFilters {
  status?: ReplenishmentStatus
  outletId?: string
}

const KEY = ["replenishment-needs"] as const

export function useReplenishmentNeeds(filters: ReplenishmentFilters = {}) {
  return useQuery<ReplenishmentNeed[]>({
    queryKey: [...KEY, filters],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (filters.status) params.set("status", filters.status)
      if (filters.outletId) params.set("outletId", filters.outletId)
      const qs = params.toString()
      const data = await api.get<{ needs: ReplenishmentNeed[] }>(
        `/v1/replenishment-needs${qs ? `?${qs}` : ""}`,
      )
      return data.needs ?? []
    },
    staleTime: 15 * 1000,
  })
}

function useInvalidate() {
  const qc = useQueryClient()
  return () => {
    qc.invalidateQueries({ queryKey: KEY })
    qc.invalidateQueries({ queryKey: ["notifications", "feed"] })
  }
}

export function useProduceReplenishment() {
  const invalidate = useInvalidate()
  const qc = useQueryClient()
  return useMutation<{ needId: string; orderId: string }, Error, { id: string; qty: number | null }>({
    mutationFn: ({ id, qty }) =>
      api.post("/v1/replenishment-needs", { action: "produce", id, qty }),
    onSuccess: () => {
      invalidate()
      qc.invalidateQueries({ queryKey: ["production-orders"] })
    },
  })
}

export function useTransferReplenishment() {
  const invalidate = useInvalidate()
  const qc = useQueryClient()
  return useMutation<
    { needId: string; transferId: string; status: ReplenishmentStatus },
    Error,
    {
      id: string
      fromOutletId: string
      fromLocationId: string | null
      toLocationId: string | null
      qty: number | null
    }
  >({
    mutationFn: (body) => api.post("/v1/replenishment-needs", { action: "transfer", ...body }),
    onSuccess: () => {
      invalidate()
      qc.invalidateQueries({ queryKey: ["stock-transfers"] })
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["stock"] })
    },
  })
}

/** Lo que devolvió generar necesidades desde el faltante de un lote. */
export interface NeedsFromBatchResult {
  created: { needId: string; itemId: string; itemName: string | null; quantity: number }[]
  /** Ya tenían una necesidad ABIERTA: no se pisaron, y la pantalla lo informa. */
  existing: { itemId: string; itemName: string | null; quantity: number }[]
}

/**
 * Genera una necesidad por cada insumo que FALTA para un lote de producción
 * (context/70 §B.5, mig 229).
 *
 * Se manda la COMPOSICIÓN del lote —los mismos `{itemId, qty}` del estimador—,
 * nunca los kilos faltantes: la cantidad la recalcula el servidor explotando
 * las recetas. Mandar el faltante ya masticado dejaría que el cliente eligiera
 * cuánto pedir y que el número guardado dejara de salir de la receta.
 */
export function useCreateNeedsFromBatch() {
  const invalidate = useInvalidate()
  return useMutation<
    NeedsFromBatchResult,
    Error,
    { outletId: string; locationId: string | null; lines: { itemId: string; qty: number }[] }
  >({
    mutationFn: (body) => api.post("/v1/replenishment-needs", { action: "from-batch", ...body }),
    onSuccess: invalidate,
  })
}

export function useCloseReplenishment() {
  const invalidate = useInvalidate()
  return useMutation<ReplenishmentNeed, Error, { id: string; reason: string }>({
    mutationFn: ({ id, reason }) =>
      api.post("/v1/replenishment-needs", { action: "close", id, reason }),
    onSuccess: invalidate,
  })
}
