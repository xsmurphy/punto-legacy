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
 * Crea una sucursal en blanco. Backend la inserta como "Nueva Sucursal" status=1
 * + sus filas de inventario en cero. Devuelve el nuevo id.
 *
 * Estrategia: invalidate ["outlets"] al éxito + el caller hace `router.push` al
 * detalle para editarla. Optimistic update no aporta (el row no aparece en la
 * lista hasta que el backend devuelve el id de todas formas).
 */
export function useCreateOutlet() {
  const qc = useQueryClient()
  return useMutation<{ id: string }, Error, void>({
    mutationFn: () => api.post<{ id: string }>("/v1/outlets", { action: "create" }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["outlets"] })
    },
  })
}

/** Update full de una sucursal. */
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
    latLng: values.latLng,
    taxId: values.taxId,
    purchaseOrderNo: values.purchaseOrderNo ?? "",
    status: values.status ? 1 : "",
    ecom: values.ecom ? 1 : "",
    taxIncluded: values.taxIncluded ? 1 : "",
  }
}
