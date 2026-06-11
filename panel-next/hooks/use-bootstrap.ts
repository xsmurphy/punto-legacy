"use client"

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { Bootstrap } from "@/lib/types/bootstrap"

/**
 * Config + datos del usuario logueado. Una sola request por sesión —
 * TanStack Query dedup automáticamente las llamadas concurrentes y
 * el staleTime alto evita refetch innecesarios.
 *
 * Auth: requiere cookie `_jwt_panel` válida (realm `panel`). Si responde
 * 401, los componentes que dependen de `data` se muestran como skeleton
 * y el middleware de panel-next redirecta a /login (slice futuro).
 */
export function useBootstrap() {
  return useQuery<Bootstrap>({
    queryKey: ["bootstrap"],
    queryFn: () => api.get<Bootstrap>("/v1/bootstrap"),
    staleTime: 5 * 60 * 1000, // 5 min — config cambia raramente
    retry: false, // un 401 no se retry; dejar al middleware redirect
  })
}
