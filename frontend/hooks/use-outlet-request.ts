"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  CreateOutletRequestInput,
  OutletRequestStatus,
} from "@/lib/types/outlet-request"

/**
 * Estado de la solicitud de sucursal + el precio que gobierna el paywall.
 *
 * `enabled` existe porque el endpoint exige `settings.outlet.manage`: sin el
 * permiso la query sería un 403 garantizado en cada carga del panel. El caller
 * pasa el resultado de `usePermission("settings.outlet.manage")`.
 *
 * El precio sale de acá y no de `useBilling()` a propósito: ese endpoint pide
 * `billing.view`, un permiso que el encargado de sucursales puede no tener.
 */
export function useOutletRequestStatus(enabled: boolean) {
  return useQuery<OutletRequestStatus>({
    queryKey: ["outlet-request"],
    queryFn: () => api.get<OutletRequestStatus>("/v1/outlet-requests"),
    enabled,
    staleTime: 60 * 1000,
  })
}

/**
 * Crea la SOLICITUD, no la sucursal. La sucursal la crea /admin al aprobar.
 *
 * La respuesta trae el estado nuevo, así que se siembra el cache en vez de
 * invalidar: el switcher pasa a "Solicitud pendiente" sin un segundo viaje.
 */
export function useCreateOutletRequest() {
  const qc = useQueryClient()

  return useMutation<
    { requestId: string; status: OutletRequestStatus },
    Error,
    CreateOutletRequestInput
  >({
    mutationFn: (values) =>
      api.post<{ requestId: string; status: OutletRequestStatus }>(
        "/v1/outlet-requests",
        {
          name: values.name,
          ...(values.address ? { address: values.address } : {}),
        },
      ),
    onSuccess: (data) => {
      if (data?.status) {
        qc.setQueryData(["outlet-request"], data.status)
      } else {
        qc.invalidateQueries({ queryKey: ["outlet-request"] })
      }
    },
  })
}
