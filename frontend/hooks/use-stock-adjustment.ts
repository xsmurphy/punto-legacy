"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface StockAdjustmentItem {
  itemId: string
  qty: number
  type: "+" | "-"
  unitCost?: number | null
  note?: string
}

export interface StockAdjustmentPayload {
  outletId: string
  locationId?: string | null
  reason: string
  items: StockAdjustmentItem[]
}

export interface StockAdjustmentResult {
  adjustmentsCount: number
  skippedItems: string[]
  totalCostDelta: number
}

export function useCreateStockAdjustment() {
  const qc = useQueryClient()
  return useMutation<StockAdjustmentResult, Error, StockAdjustmentPayload>({
    mutationFn: (payload) =>
      api.post<StockAdjustmentResult>("/v1/stock_adjustment", {
        action: "create",
        ...payload,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["stock"] })
      qc.invalidateQueries({ queryKey: ["inventory"] })
    },
  })
}
