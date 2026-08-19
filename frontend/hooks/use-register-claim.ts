"use client"

import { useQuery } from "@tanstack/react-query"
import { posApi } from "@/lib/api/pos-client"

/**
 * Toma la tenencia de esta caja para este dispositivo — F2 de
 * context/29-numeracion-y-exclusividad-de-caja.md §4
 * (`api/v1/register/claim.php`).
 *
 * BUG REAL detectado 2026-08-19: `claim.php` existía en el backend desde F2,
 * pero NADA en el POS lo llamaba — `register_lease` quedaba vacía para
 * SIEMPRE en cualquier caja de cualquier tenant, y `RegisterLeaseService::
 * holderConflict()` (que `sales.php`/`offline-sync.php` corren antes de
 * cada venta) trata "sin tenencia" como conflicto igual que "tomada por
 * otro" — así que TODA venta rechazaba con 409, sin que ninguna acción del
 * operador o del admin pudiera arreglarlo (no hay tenedor que liberar: nunca
 * hubo tenedor). Este hook es el caller que faltaba.
 *
 * Se llama UNA vez al entrar al workspace del POS
 * (`PosWorkspaceLayoutInner`, después de que el bootstrap resuelve
 * outletId/registerId/deviceId) — antes de que el operador intente vender,
 * no recién cuando falla el cobro. Idempotente: si este device ya es el
 * tenedor, el backend devuelve 200 sin tocar nada (confirmación, no
 * re-toma). Si otro device la tiene tomada de verdad, devuelve 409 con
 * `{holderDeviceId, holderDeviceName}` — el layout muestra la pantalla
 * bloqueante en vez de dejar entrar al workspace.
 *
 * `retry:false` — un 409 es una respuesta real (alguien la tiene, o recién
 * se liberó y hay que reintentar a mano), no un error transitorio de red
 * para reintentar solo. `refetchOnWindowFocus:false` — recuperar el foco de
 * la pestaña no debe re-disparar una toma de caja; el retry es explícito
 * (botón "Reintentar" de la pantalla bloqueante, ver `RegisterTakenScreen`).
 */
export function useRegisterClaim() {
  return useQuery<{ registerLeaseId: string }>({
    queryKey: ["register-claim"],
    queryFn: () => posApi.post<{ registerLeaseId: string }>("/v1/register/claim", {}),
    staleTime: Infinity,
    retry: false,
    refetchOnWindowFocus: false,
  })
}
