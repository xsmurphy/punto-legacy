"use client"

/**
 * Hook del bootstrap del POS.
 *
 * Consulta el BFF `/api/pos/bootstrap` (NOT la /api PHP directa).
 * El BFF compone items + clientes + config + cajas en una sola respuesta.
 *
 * Auth: requiere cookie `_jwt` válida (realm `pos-app`). Si responde 401,
 * el `PosAuthGuard` redirige a /login.
 *
 * TODO (Slice A): el stub actual devuelve arrays vacíos. Al conectar el BFF
 * real, este hook dispara la hidratación del `useCatalogStore`.
 */

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { PosBootstrap } from "@/lib/types/pos-bootstrap"

export function usePosBootstrap() {
  return useQuery<PosBootstrap>({
    queryKey: ["pos-bootstrap"],
    // Apunta al BFF POS (/api/pos/bootstrap), NO a /v1/bootstrap del panel.
    queryFn: () => api.get<PosBootstrap>("/pos/bootstrap"),
    staleTime: 5 * 60 * 1000, // 5 min
    retry: false, // 401 no se retry
  })
}
