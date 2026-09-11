"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { ItemListItem } from "@/lib/types/item"

export interface VariantRow extends Omit<ItemListItem, "tags"> {
  variantAttributes?: Record<string, string> | null
  tags?: string[]
}
// `itemBarcode` (la clave suelta del JSONB donde vivía el código de barras
// antes de la mig 220) ya NO existe: la migración la promovió a `barcode` —
// campo de `ItemListItem`, heredado acá — y borró la clave. Si vuelve a
// aparecer en un payload es un backend viejo, no una fuente de verdad.

export interface VariantInput {
  itemId?: string
  sku: string
  barcode?: string
  price: number | null
  cost?: number | null
  stock?: number
  variantAttributes?: Record<string, string>
}

export function useItemVariants(parentId: string | undefined) {
  return useQuery<{ variants: VariantRow[] }>({
    queryKey: ["item-variants", parentId],
    queryFn: () =>
      api.get<{ variants: VariantRow[] }>(
        `/v1/items?resource=variants&parentId=${parentId}`,
      ),
    enabled: !!parentId,
    staleTime: 30 * 1000,
  })
}

export function useBulkUpsertVariants(parentId: string) {
  const qc = useQueryClient()
  return useMutation<
    { variants: VariantRow[] },
    Error,
    VariantInput[]
  >({
    mutationFn: (variants) =>
      api.post<{ variants: VariantRow[] }>("/v1/items", {
        action: "bulkUpsertVariants",
        parentId,
        variants,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["item-variants", parentId] })
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["items", parentId] })
    },
  })
}
