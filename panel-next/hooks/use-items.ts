"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import {
  kindToBackendFields,
  type ItemFormValues,
  type ItemFull,
  type ItemListItem,
  type Taxonomy,
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
    mutationFn: (values) => {
      const flags = kindToBackendFields(values.kind)
      return api.post<ItemFull>("/v1/items", {
        type: flags.itemType, // backend usa `type` para createBlank
        ...serialize(values, flags),
      })
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["items"] })
    },
  })
}

export function useUpdateItem() {
  const qc = useQueryClient()
  return useMutation<ItemFull, Error, { id: string; values: ItemFormValues }>({
    mutationFn: ({ id, values }) => {
      const flags = kindToBackendFields(values.kind)
      return api.put<ItemFull>(`/v1/items?id=${id}`, serialize(values, flags))
    },
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

// ── helpers ────────────────────────────────────────────────────────────────

function serialize(
  values: ItemFormValues,
  flags: ReturnType<typeof kindToBackendFields>,
): Record<string, unknown> {
  return {
    itemName: values.name,
    itemSKU: values.sku,
    itemDescription: values.description,
    itemPrice: values.price ?? "",
    itemCost: values.cost ?? "",
    itemDiscount: values.discount ?? "",
    itemUOM: values.uom,
    itemTaxIncluded: values.taxIncluded ? 1 : 0,
    itemStatus: values.status ? 1 : 0,
    taxId: values.taxId || null,
    categoryId: values.categoryId || null,
    brandId: values.brandId || null,
    // Flags derivados del kind — NO los toca el usuario directamente.
    itemType: flags.itemType,
    itemCanSale: flags.itemCanSale,
    itemTrackInventory: flags.itemTrackInventory,
    itemProduction: flags.itemProduction,
  }
}
