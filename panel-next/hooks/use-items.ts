"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  ItemFormValues,
  ItemFull,
  ItemListItem,
  Taxonomy,
} from "@/lib/types/item"

export function useItems(opts?: { q?: string; archived?: boolean }) {
  return useQuery<{
    items: ItemListItem[]
    total: number
    limit: number
    offset: number
  }>({
    queryKey: ["items", opts?.q ?? "", opts?.archived ?? false],
    queryFn: () => {
      const params = new URLSearchParams({ limit: "200" })
      if (opts?.q) params.set("q", opts.q)
      if (opts?.archived) params.set("archived", "1")
      return api.get(`/v1/items?${params.toString()}`)
    },
    staleTime: 30 * 1000,
  })
}

export function useItem(id: string | undefined) {
  return useQuery<ItemFull>({
    queryKey: ["items", id],
    queryFn: () => api.get<ItemFull>(`/v1/items?id=${id}`),
    enabled: !!id,
    staleTime: 30 * 1000,
  })
}

export function useCreateItem() {
  const qc = useQueryClient()
  return useMutation<ItemFull, Error, ItemFormValues>({
    mutationFn: (values) =>
      api.post<ItemFull>("/v1/items", { type: values.type, ...serialize(values) }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

export function useUpdateItem() {
  const qc = useQueryClient()
  return useMutation<ItemFull, Error, { id: string; values: ItemFormValues }>({
    mutationFn: ({ id, values }) =>
      api.put<ItemFull>(`/v1/items?id=${id}`, serialize(values)),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["items"] })
      qc.invalidateQueries({ queryKey: ["items", vars.id] })
    },
  })
}

export function useArchiveItem() {
  const qc = useQueryClient()
  return useMutation<{ archived: boolean; itemId: string }, Error, string>({
    mutationFn: (id) => api.del(`/v1/items?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

/**
 * Taxonomies del tenant (categorías, marcas, impuestos, etc.). Una sola query
 * trae todas; filtramos client-side por type donde haga falta. Cache larga
 * porque cambian raras veces.
 */
export function useTaxonomies() {
  return useQuery<{ taxonomies: Taxonomy[] }>({
    queryKey: ["taxonomies"],
    queryFn: () => api.get("/v1/taxonomies"),
    staleTime: 5 * 60 * 1000,
  })
}

export function useTaxonomiesByType(type: string) {
  const { data, ...rest } = useTaxonomies()
  return {
    ...rest,
    data: (data?.taxonomies ?? []).filter((t) => t.type === type),
  }
}

// ── helpers ─────────────────────────────────────────────────────────────────

function serialize(values: ItemFormValues): Record<string, unknown> {
  // Backend acepta los nombres con prefix `item*` del schema (más `taxId`,
  // `brandId`, `categoryId`). Mapeamos del form a esos nombres.
  return {
    itemName: values.name,
    itemSKU: values.sku,
    itemType: values.type,
    itemDescription: values.description,
    itemPrice: values.price ?? "",
    itemCost: values.cost ?? "",
    itemDiscount: values.discount ?? "",
    itemUOM: values.uom,
    itemTaxIncluded: values.taxIncluded ? 1 : 0,
    itemTrackInventory: values.trackInventory ? 1 : 0,
    itemCanSale: values.canSale ? 1 : 0,
    itemProduction: values.production ? 1 : 0,
    itemStatus: values.status ? 1 : 0,
    taxId: values.taxId || null,
    categoryId: values.categoryId || null,
    brandId: values.brandId || null,
  }
}
