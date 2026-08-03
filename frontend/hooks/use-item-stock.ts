"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface StockSummary {
  qty: number
  avgCost: number
  totalValue: number
}

export interface StockMovement {
  id: string
  date: string | null
  source: string
  delta: number
  deltaCogs: number
  stockOnHand: number
  stockOnHandCogs: number
  note: string | null
  outletName: string
  userName: string | null
  locationName: string | null
}

export interface StockMovementsPage {
  summary: StockSummary
  items: StockMovement[]
  total: number
  limit: number
  offset: number
}

const STOCK_MOVEMENTS_KEY = "item-stock-movements"

/**
 * Resumen de valorización + historial paginado de movimientos de UN ítem,
 * across todas las sucursales — tab "Stock" del detalle de ítem.
 */
export function useItemStockMovements(
  itemId: string | undefined,
  opts: { limit?: number; offset?: number } = {},
) {
  const limit = opts.limit ?? 20
  const offset = opts.offset ?? 0
  return useQuery<StockMovementsPage>({
    queryKey: [STOCK_MOVEMENTS_KEY, itemId, limit, offset],
    queryFn: () =>
      api.get<StockMovementsPage>(
        `/v1/items?id=${itemId}&resource=inventory-movements&limit=${limit}&offset=${offset}`,
      ),
    enabled: !!itemId,
    staleTime: 15 * 1000,
  })
}

/**
 * Último precio de compra real del ítem (de compras registradas, no del
 * costo declarado) — `0` si nunca se compró. Ya usado en el form de
 * /purchase; se reusa acá para el KPI "Precio de compra" del tab Stock.
 */
export function useLastPurchasePrice(itemId: string | undefined) {
  return useQuery<{ price: number }>({
    queryKey: ["item-last-purchase-price", itemId],
    queryFn: () => api.get<{ price: number }>(`/v1/items?id=${itemId}&resource=last-purchase-price`),
    enabled: !!itemId,
    staleTime: 60 * 1000,
  })
}

export interface AdjustStockPayload {
  outletId: string
  locationId?: string | null
  type: "+" | "-"
  qty: number
  unitCost?: number | null
  reason: string
}

/** Ajuste manual (+/-) de stock para UN ítem en UNA sucursal, desde su detalle. */
export function useAdjustItemStock(itemId: string) {
  const qc = useQueryClient()
  return useMutation<{ summary: StockSummary }, Error, AdjustStockPayload>({
    mutationFn: (payload) =>
      api.post<{ summary: StockSummary }>(`/v1/items?id=${itemId}&resource=inventory-movements`, {
        action: "adjust",
        ...payload,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: [STOCK_MOVEMENTS_KEY, itemId] })
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["items", itemId] })
    },
  })
}
