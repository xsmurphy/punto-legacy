"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posApi as api } from "@/lib/api/pos-client"
import type { CartLine } from "@/lib/cart/store"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"

// ── Tipos ─────────────────────────────────────────────────────────────────────

export interface ParkedSaleData {
  cart: CartLine[]
  customer: PosCustomer | null
  notes?: string | null
  title?: string | null
}

export interface ParkedSale {
  id: string
  data: ParkedSaleData
  createdAt: string
}

// ── Query key ─────────────────────────────────────────────────────────────────

const PARKED_SALES_KEY = ["parked-sales"] as const

// ── Hooks ─────────────────────────────────────────────────────────────────────

/** Lista las ventas guardadas del usuario en el outlet activo.
 * `enabled` para que los call-sites del panel (que NO tienen Bearer device)
 * no disparen la query — el endpoint requiere `apiAuthPosContext` y solo
 * devolvería 401. Default `true` para back-compat con el POS. */
export function useParkedSales(opts: { enabled?: boolean } = {}) {
  return useQuery<ParkedSale[]>({
    queryKey: PARKED_SALES_KEY,
    queryFn: () => api.get<ParkedSale[]>("/v1/parked-sales"),
    staleTime: 30 * 1000,
    enabled: opts.enabled ?? true,
  })
}

/** Guarda la venta en curso. */
export function useSaveParkedSale() {
  const qc = useQueryClient()
  return useMutation<ParkedSale, Error, { data: ParkedSaleData }>({
    mutationFn: (payload) =>
      api.post<ParkedSale>("/v1/parked-sales", payload as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: PARKED_SALES_KEY })
    },
  })
}

/** Elimina una venta guardada por id. */
export function useDeleteParkedSale() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/parked-sales?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: PARKED_SALES_KEY })
    },
  })
}
