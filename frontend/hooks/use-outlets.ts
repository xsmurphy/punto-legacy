"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  OutletFormValues,
  OutletFull,
  OutletListItem,
} from "@/lib/types/outlet"

/** Lista todas las sucursales del tenant. */
export function useOutlets() {
  return useQuery<{ rows: OutletListItem[] }>({
    queryKey: ["outlets"],
    queryFn: () => api.get<{ rows: OutletListItem[] }>("/v1/outlets"),
    staleTime: 30 * 1000,
  })
}

/** Sucursal individual + lista de impuestos disponibles para el dropdown. */
export function useOutlet(id: string | undefined) {
  return useQuery<OutletFull>({
    queryKey: ["outlets", id],
    queryFn: () => api.get<OutletFull>(`/v1/outlets?id=${id}`),
    enabled: !!id,
    staleTime: 30 * 1000,
  })
}

/**
 * El alta directa de sucursales MURIÓ con el paywall (2026-09-11).
 *
 * Cada sucursal se factura al precio del plan del tenant, así que el comercio
 * no la crea: la PIDE (`useCreateOutletRequest`, `hooks/use-outlet-request.ts`)
 * y la habilita Punto desde /admin. El backend lo hace cumplir en el único
 * creador (`OutletsService::create()`), y `POST /v1/outlets?action=create`
 * responde 403 — un hook que solo puede fallar no se deja "por si acaso".
 */

export function useUpdateOutlet() {
  const qc = useQueryClient()
  return useMutation<
    { id: string; action: "update" },
    Error,
    { id: string; values: OutletFormValues }
  >({
    mutationFn: ({ id, values }) =>
      api.post<{ id: string; action: "update" }>("/v1/outlets", {
        action: "update",
        id,
        ...serialize(values),
      }),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["outlets"] })
      qc.invalidateQueries({ queryKey: ["outlets", vars.id] })
    },
  })
}

/** Elimina una sucursal. Backend rechaza si es la sucursal activa de la sesión. */
export function useDeleteOutlet() {
  const qc = useQueryClient()
  return useMutation<{ id: string }, Error, string>({
    mutationFn: (id) => api.post<{ id: string }>("/v1/outlets", { action: "delete", id }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["outlets"] })
    },
  })
}

/**
 * Convierte booleans a 0/1 string (el endpoint usa `validateHttp` que trata "0"
 * como falsy salvo el explicit cast `(int)`). Para checkboxes el legacy convention
 * es `name=1` cuando está prendido, key omitida (o ausente) cuando no.
 */
function serialize(values: OutletFormValues): Record<string, unknown> {
  return {
    name: values.name,
    address: values.address,
    phone: values.phone,
    email: values.email,
    description: values.description,
    billingName: values.billingName,
    ruc: values.ruc,
    whatsApp: values.whatsApp,
    // lat/lng — columnas numéricas. Manda "" cuando el user las dejó vacías
    // para que el backend las persista como null (no como 0).
    lat: values.lat ?? "",
    lng: values.lng ?? "",
    taxId: values.taxId,
    purchaseOrderNo: values.purchaseOrderNo ?? "",
    status: values.status ? 1 : "",
    ecom: values.ecom ? 1 : "",
    taxIncluded: values.taxIncluded ? 1 : "",
    priceListId: values.priceListId ?? "",
  }
}
