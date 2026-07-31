"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api, type HttpClient } from "@/lib/api-client"
import type {
  PriceList,
  PriceListDetail,
  PriceListItem,
  CreatePriceListPayload,
  UpdatePriceListPayload,
  PriceListItemInput,
  ResolvedPrice,
} from "@/lib/types/price-list"

/**
 * Lista todas las listas de precios del tenant.
 *
 * Consumida por panel (settings) y por el POS (`sale-options-drawer.tsx`,
 * para elegir lista de precios en la venta) — `/v1/price_list` es multi-realm
 * en backend. El POS inyecta `client: posApi` (Bearer); el panel usa el
 * default `api` (cookie). Ver invariante de realm en `lib/api-client.ts`.
 */
export function usePriceLists(opts: { client?: HttpClient } = {}) {
  const client = opts.client ?? api
  return useQuery<PriceList[]>({
    queryKey: ["price-lists", client === api ? "panel" : "pos"],
    queryFn: () => client.get<PriceList[]>("/v1/price_list"),
    staleTime: 30 * 1000,
  })
}

/** Detalle de una lista con sus ítems configurados. */
export function usePriceList(id: string | undefined) {
  return useQuery<PriceListDetail>({
    queryKey: ["price-lists", id],
    queryFn: () => api.get<PriceListDetail>(`/v1/price_list?id=${id}`),
    enabled: !!id,
    staleTime: 30 * 1000,
  })
}

/** Crea una lista de precios. */
export function useCreatePriceList() {
  const qc = useQueryClient()
  return useMutation<PriceList, Error, CreatePriceListPayload>({
    mutationFn: (payload) => api.post<PriceList>("/v1/price_list", payload as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["price-lists"] })
    },
  })
}

/** Actualiza el encabezado de una lista. */
export function useUpdatePriceList() {
  const qc = useQueryClient()
  return useMutation<PriceListDetail, Error, { id: string; payload: UpdatePriceListPayload }>({
    mutationFn: ({ id, payload }) =>
      api.put<PriceListDetail>(`/v1/price_list?id=${id}`, payload as unknown as Record<string, unknown>),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["price-lists"] })
      qc.invalidateQueries({ queryKey: ["price-lists", vars.id] })
    },
  })
}

/** Elimina una lista y sus ítems (CASCADE en BD). */
export function useDeletePriceList() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean; id: string }, Error, string>({
    mutationFn: (id) => api.del(`/v1/price_list?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["price-lists"] })
    },
  })
}

/** Items de una lista (con nombres y precio base del catálogo). */
export function usePriceListItems(priceListId: string | undefined) {
  return useQuery<PriceListItem[]>({
    queryKey: ["price-list-items", priceListId],
    queryFn: () =>
      api.get<PriceListItem[]>(`/v1/price_list_item?priceListId=${priceListId}`),
    enabled: !!priceListId,
    staleTime: 30 * 1000,
  })
}

/** Bulk-replace de todos los ítems de una lista. */
export function useSetPriceListItems() {
  const qc = useQueryClient()
  return useMutation<
    PriceListItem[],
    Error,
    { priceListId: string; items: PriceListItemInput[] }
  >({
    mutationFn: ({ priceListId, items }) =>
      api.put<PriceListItem[]>(`/v1/price_list_item?priceListId=${priceListId}`, { items }),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["price-list-items", vars.priceListId] })
      qc.invalidateQueries({ queryKey: ["price-lists", vars.priceListId] })
      qc.invalidateQueries({ queryKey: ["price-lists"] })
    },
  })
}

/**
 * Resuelve precios en batch para un contexto de venta. Multi-realm en backend
 * (`/v1/price_resolve` acepta panel + pos-app) — mismo patrón de `client`
 * inyectable que `usePriceLists`: el POS (`use-price-context.ts`) pasa
 * `client: posApi` (Bearer), el panel usa el default `api` (cookie). Nunca
 * mezclar credenciales entre realms.
 */
export function useResolvePrices(opts: { client?: HttpClient } = {}) {
  const client = opts.client ?? api
  return useMutation<
    ResolvedPrice[],
    Error,
    {
      items: { itemId: string; basePrice: number }[]
      contactId?: string
      outletId?: string
      priceListId?: string
    }
  >({
    mutationFn: (payload) =>
      client.post<ResolvedPrice[]>("/v1/price_resolve", payload as unknown as Record<string, unknown>),
  })
}
