"use client"

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface OutletLocation {
  id: string
  name: string
  outletId: string | null
  /** Depósito por defecto de la sucursal: el que viene preseleccionado.
   *  Toda sucursal tiene exactamente uno (invariante garantizada por el
   *  índice único `uq_taxonomy_location_default`, mig 165). */
  isDefault: boolean
}

/** Depósitos de una sucursal. El filtro por sucursal lo hace la BD. */
export function useOutletLocations(outletId: string | null) {
  return useQuery<OutletLocation[]>({
    queryKey: ["outlet-locations", outletId],
    queryFn: async () => {
      const data = await api.get<{ taxonomies: OutletLocation[] }>(
        `/v1/taxonomies?type=location&outletId=${encodeURIComponent(outletId ?? "")}`
      )
      return data.taxonomies ?? []
    },
    enabled: !!outletId,
  })
}

/** El depósito por defecto de la sucursal, o el primero si todavía no hay
 *  ninguno marcado (sucursal anterior a la mig 165 que el backfill no
 *  alcanzó). Nunca devuelve undefined si la lista tiene algo. */
export function defaultLocationOf(
  locations: OutletLocation[] | undefined,
): OutletLocation | undefined {
  if (!locations?.length) return undefined
  return locations.find((l) => l.isDefault) ?? locations[0]
}

export function useCreateLocation(outletId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (name: string) =>
      api.post("/v1/taxonomies", { action: "create", type: "location", outletId, name }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["outlet-locations", outletId] }),
  })
}

export function useUpdateLocation(outletId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) =>
      api.post("/v1/taxonomies", { action: "update", type: "location", id, name }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["outlet-locations", outletId] }),
  })
}

/** Mueve la marca de "por defecto" a otro depósito de la misma sucursal.
 *  Sin esto el default sería indeleteable para siempre: `delete` lo bloquea. */
export function useSetDefaultLocation(outletId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) =>
      api.post("/v1/taxonomies", { action: "setDefault", type: "location", id }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["outlet-locations", outletId] }),
  })
}

export function useDeleteLocation(outletId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) =>
      api.post("/v1/taxonomies", { action: "delete", type: "location", id }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["outlet-locations", outletId] }),
  })
}
