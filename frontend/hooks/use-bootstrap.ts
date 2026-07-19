"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api, type HttpClient } from "@/lib/api-client"
import type { Bootstrap } from "@/lib/types/bootstrap"

/**
 * Config + datos del usuario logueado. Una sola request por sesión —
 * TanStack Query dedup automáticamente las llamadas concurrentes y
 * el staleTime alto evita refetch innecesarios.
 *
 * Auth: `/v1/bootstrap` es MULTI-REALM (`apiAuthTenant(['panel','pos-app'])`)
 * — acepta cookie `_jwt_panel` (panel) o Bearer del device (POS). El caller
 * define el realm inyectando el cliente: panel usa el default `api`
 * (cookie); el layout del POS pasa `client: posApi` (Bearer) — ver
 * `lib/api/pos-client.ts` y el invariante de realm en `lib/api-client.ts`.
 * Si responde 401, los componentes que dependen de `data` se muestran como
 * skeleton y el middleware de frontend redirecta a /login (slice futuro).
 */
export function useBootstrap(opts: { client?: HttpClient } = {}) {
  const client = opts.client ?? api
  return useQuery<Bootstrap>({
    queryKey: ["bootstrap", client === api ? "panel" : "pos"],
    queryFn: () => client.get<Bootstrap>("/v1/bootstrap"),
    // staleTime alto evita refetch innecesario en navegación normal, pero
    // refetchOnMount: "always" garantiza datos frescos al cargar/recargar
    // la app — antes la cache cliente persistía data vieja sin user.permissions
    // (campo agregado al backend en 2026-06-25) y el sidebar filtraba items
    // que el user sí tenía permitidos. Incidente 2026-06-29.
    staleTime: 5 * 60 * 1000,
    refetchOnMount: "always",
    retry: false, // un 401 no se retry; dejar al middleware redirect
  })
}

/**
 * Cambia la sucursal activa del realm panel. El backend re-emite la cookie
 * `_jwt_panel` con un nuevo claim `oid` — al volver la response, el
 * próximo fetch a cualquier endpoint scopeado por outlet (reports, stock,
 * drawers) usa la sucursal nueva.
 *
 * Tras el cambio invalidamos TODO el cache de TanStack Query — los widgets
 * del dashboard y los listados que dependen del outlet activo refetchean.
 * Sobre-invalidación deliberada: más simple que enumerar query keys y el
 * cambio de sucursal es una acción explícita del usuario, no high-frequency.
 */
export function useSetActiveOutlet() {
  const qc = useQueryClient()
  return useMutation<
    { outletId: string; outletName: string; expiresIn: number },
    Error,
    string
  >({
    mutationFn: (outletId) =>
      api.post<{ outletId: string; outletName: string; expiresIn: number }>(
        "/v1/active-outlet",
        { outletId },
      ),
    onSuccess: () => {
      qc.invalidateQueries()
    },
  })
}
