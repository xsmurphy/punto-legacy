"use client"

/**
 * Hook TanStack Query para los hotkeys del POS.
 *
 * — Query de carga: GET /v1/register?resource=hotkeys
 *   Solo se activa cuando hay caja activa (activeRegisterId !== "").
 *   En onSuccess hidrata el store (el backend es la fuente de verdad;
 *   localStorage solo es cache entre sesiones sin caja activa).
 *
 * — Mutation de guardado: PUT /v1/register?resource=hotkeys
 *   Expone `saveHotkeys()` que toma los hotkeys del store y los persiste.
 *
 * Uso: llamar `useHotkeys()` en el layout del POS (junto a useCatalogSeed)
 * para que la query viva mientras el POS está montado.
 */

import * as React from "react"
import { useQuery, useMutation } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import { useCatalogStore } from "@/lib/catalog/store"
import { useHotkeysStore, type Hotkey } from "@/lib/hotkeys/store"

// ── Tipos ─────────────────────────────────────────────────────────────────────

interface HotkeysResponse {
  hotkeys: Hotkey[]
}

// ── Hook principal ────────────────────────────────────────────────────────────

export function useHotkeys() {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const hydrateStore = useHotkeysStore((s) => s.hydrate)

  // ── Query de carga ──────────────────────────────────────────────────────────
  const { isLoading, data } = useQuery<HotkeysResponse>({
    queryKey: ["pos-hotkeys", activeRegisterId],
    queryFn: () => api.get<HotkeysResponse>("/v1/register?resource=hotkeys"),
    // Solo cuando hay caja activa.
    enabled: activeRegisterId !== "",
    // No refetch on window focus (grilla local que el usuario edita).
    refetchOnWindowFocus: false,
    // La config de hotkeys es estable — no stale en segundos.
    staleTime: Infinity,
  })

  // Hidratar el store cuando llegan los datos del backend.
  React.useEffect(() => {
    if (data?.hotkeys) {
      hydrateStore(data.hotkeys)
    }
  }, [data, hydrateStore])

  // ── Mutation de guardado ────────────────────────────────────────────────────
  const { mutateAsync, isPending: isSaving } = useMutation<HotkeysResponse, Error, Hotkey[]>({
    mutationFn: (hotkeys) =>
      api.put<HotkeysResponse>("/v1/register?resource=hotkeys", { hotkeys }),
    onSuccess: (res) => {
      // Pisa el store con la respuesta normalizada del backend.
      if (res?.hotkeys) {
        hydrateStore(res.hotkeys)
      }
    },
  })

  /**
   * Persiste los hotkeys actuales del store en el backend.
   * Retorna la promesa para que el caller pueda manejar success/error.
   */
  const saveHotkeys = React.useCallback(async () => {
    const hotkeys = useHotkeysStore.getState().hotkeys
    return mutateAsync(hotkeys)
  }, [mutateAsync])

  return { isLoading, saveHotkeys, isSaving }
}
