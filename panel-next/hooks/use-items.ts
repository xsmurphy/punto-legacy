"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import {
  kindToBackendFields,
  defaultAvailability,
  type ItemAvailability,
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
        kind: values.kind,
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
      return api.put<ItemFull>(`/v1/items?id=${id}`, {
        kind: values.kind,
        ...serialize(values, flags),
      })
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

/** Lista de monedas extranjeras del tenant con sus tasas. */
export interface CurrencyEntry {
  country?: string
  code: string
  rate?: number
  symbol?: string
  name?: string
}
export function useCurrencies() {
  return useQuery<CurrencyEntry[]>({
    queryKey: ["currencies"],
    queryFn: () => api.get("/v1/currencies"),
    staleTime: 5 * 60 * 1000,
  })
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
    itemPrice: values.price,
    itemCost: values.cost,
    itemDiscount: values.discount,
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
    // Configuraciones extendidas — algunas son columnas (outletId, itemSort,
    // supplierId), el resto rutea al JSONB `data` automáticamente porque no
    // están en el _getTableSchema['item'] whitelist tras migration 07.
    outletId: values.outletId || null,
    supplierId: values.supplierId || null,
    itemSort: values.sort ?? 99999,
    itemWaste: values.waste ?? 0,
    itemComissionPercent: values.commission ?? 0,
    itemComissionType: values.commissionType === "percent" ? "0" : "1",
    itemPricePercent: values.pricePercent ?? 0,
    itemPriceType: values.priceType === "percent" ? 1 : 0,
    itemEcom: values.ecom ? 1 : 0,
    itemFeatured: values.featured ? 1 : 0,
    itemProcedure: values.procedure,
    // itemDateHour: serializa la availability como objeto JSONB. Si availability
    // está deshabilitada, mandamos null para limpiar.
    itemDateHour: values.availability.enabled
      ? JSON.stringify(values.availability)
      : null,
    // itemCurrencies: mapa { codigoMoneda: precio }. Solo guardamos entradas
    // con precio > 0 para no inflar el JSONB con ceros.
    itemCurrencies: JSON.stringify(
      Object.fromEntries(
        Object.entries(values.currencies).filter(([, v]) => v && Number(v) > 0),
      ),
    ),
    // Campos nuevos Slice A — van a JSONB automáticamente.
    itemValidFrom: values.validFrom ?? null,
    itemValidUntil: values.validUntil ?? null,
    itemMinDaysBetweenSessions: values.minDaysBetweenSessions ?? null,
  }
}

export function parseAvailability(raw: unknown): ItemAvailability {
  if (typeof raw === "string") {
    try {
      const parsed = JSON.parse(raw)
      if (parsed && typeof parsed === "object" && "days" in parsed) {
        return parsed as ItemAvailability
      }
    } catch {
      /* fallthrough */
    }
  }
  if (raw && typeof raw === "object" && "days" in (raw as Record<string, unknown>)) {
    return raw as ItemAvailability
  }
  return defaultAvailability()
}

export function parseCurrencies(raw: unknown): Record<string, number> {
  if (typeof raw === "string") {
    try {
      const parsed = JSON.parse(raw)
      if (parsed && typeof parsed === "object") {
        return Object.fromEntries(
          Object.entries(parsed as Record<string, unknown>).map(([k, v]) => [
            k,
            typeof v === "number" ? v : Number(v) || 0,
          ]),
        )
      }
    } catch {
      /* fallthrough */
    }
  }
  if (raw && typeof raw === "object") {
    return Object.fromEntries(
      Object.entries(raw as Record<string, unknown>).map(([k, v]) => [
        k,
        typeof v === "number" ? v : Number(v) || 0,
      ]),
    )
  }
  return {}
}
