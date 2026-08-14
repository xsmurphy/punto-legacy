"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * Add-ons por producto (context/41-addons-y-combos.md, F2).
 *
 * Cliente del PANEL (`api`, cookie) — NUNCA `posFetch`. El endpoint
 * `/v1/item_addons` acepta realm `pos-app` solo para GET (el POS consulta
 * el catálogo al armar el carrito); la administración (replace/copy) es
 * exclusiva del panel, ver `api/v1/item_addons.php`.
 *
 * Shape espejo de `AddonService::listForItem` (api/lib/Items/AddonService.php)
 * — NO inventar campos, el SELECT de esa función es la fuente de verdad.
 */

export interface AddonOption {
  id: string
  itemId: string
  itemName: string
  itemPrice: number
  priceDelta: number
  isDefault: boolean
  isLocked: boolean
  maxQty: number
  sort: number
}

export interface AddonGroup {
  id: string
  name: string
  minSelect: number
  maxSelect: number | null
  sort: number
  status: boolean
  options: AddonOption[]
}

/** Payload de entrada de `replace`/`copy` — espejo de `normalizeGroups()`. */
export interface AddonGroupOptionInput {
  itemId: string
  priceDelta?: number
  isDefault?: boolean
  isLocked?: boolean
  maxQty?: number
  sort?: number
}

export interface AddonGroupInput {
  name: string
  minSelect?: number
  maxSelect?: number | null
  sort?: number
  status?: boolean
  options?: AddonGroupOptionInput[]
}

export function useItemAddons(itemId: string | undefined) {
  return useQuery<{ groups: AddonGroup[] }>({
    queryKey: ["item-addons", itemId],
    queryFn: () => api.get(`/v1/item_addons?itemId=${encodeURIComponent(itemId ?? "")}`),
    enabled: !!itemId,
    staleTime: 30 * 1000,
  })
}

export function useReplaceItemAddons() {
  const qc = useQueryClient()
  return useMutation<
    { groups: AddonGroup[] },
    Error,
    { itemId: string; groups: AddonGroupInput[] }
  >({
    mutationFn: ({ itemId, groups }) =>
      api.post("/v1/item_addons", { action: "replace", itemId, groups }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["item-addons", itemId] })
    },
  })
}

export function useCopyItemAddons() {
  const qc = useQueryClient()
  return useMutation<
    { groups: AddonGroup[] },
    Error,
    { itemId: string; sourceItemId: string }
  >({
    mutationFn: ({ itemId, sourceItemId }) =>
      api.post("/v1/item_addons", { action: "copy", itemId, sourceItemId }),
    onSuccess: (_, { itemId }) => {
      qc.invalidateQueries({ queryKey: ["item-addons", itemId] })
    },
  })
}
