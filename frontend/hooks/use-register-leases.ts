"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * Tenencia de caja (context/29-numeracion-y-exclusividad-de-caja.md, F4).
 * `register_lease` (mig 141) — una caja tiene UN tenedor (dispositivo) a la
 * vez; esto lee ese estado para la pantalla "Liberar caja" del tab Cajas de
 * cada sucursal, y expone la acción de revocarla.
 *
 * Endpoint propio `/v1/register-lease` (api/v1/register-lease.php) — NO es
 * `/v1/numbering/lease.php` (eso es el arriendo de bloques de números, F2/F3,
 * otro tema).
 */
export interface RegisterLeaseHolder {
  registerLeaseId: string
  deviceId: string
  deviceName: string
  /** Timestamptz genuino (no naive-local de negocio) — cuándo tomó la caja. */
  takenAt: string
  /**
   * true = la tenencia sigue 'active' sobre esta caja pero el dispositivo ya
   * fue reasignado a otra (bug real 2026-08-20, fix en
   * api/v1/active-register.php + api/v1/register/claim.php — ver
   * context/29-numeracion-y-exclusividad-de-caja.md §4). No debería verse en
   * uso normal; si aparece, "Liberar" es la acción correcta igual.
   */
  orphaned: boolean
}

export interface RegisterLeaseItem {
  registerId: string
  registerName: string
  /** null = caja libre, sin tenedor activo. */
  lease: RegisterLeaseHolder | null
}

export function useRegisterLeases(outletId: string) {
  return useQuery<{ leases: RegisterLeaseItem[] }>({
    queryKey: ["register-leases", outletId],
    queryFn: () =>
      api.get<{ leases: RegisterLeaseItem[] }>(`/v1/register-lease?outletId=${encodeURIComponent(outletId)}`),
    enabled: outletId !== "",
    // La tenencia puede cambiar sin que este admin haga nada (otro dispositivo
    // toma o suelta la caja) — refresco corto para que "Libre"/tenedor no
    // quede desactualizado mientras el admin mira la pantalla.
    staleTime: 15 * 1000,
    refetchInterval: 30 * 1000,
  })
}

export function useReleaseRegisterLease() {
  const qc = useQueryClient()
  return useMutation<
    { ok: boolean; alreadyReleased: boolean; releasedDeviceId?: string },
    Error,
    { registerLeaseId: string }
  >({
    mutationFn: (vars) =>
      api.post<{ ok: boolean; alreadyReleased: boolean; releasedDeviceId?: string }>(
        "/v1/register-lease",
        { action: "release", registerLeaseId: vars.registerLeaseId },
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["register-leases"] })
    },
  })
}
