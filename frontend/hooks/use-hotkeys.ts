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
import { posApi as api } from "@/lib/api/pos-client"
import { useCatalogStore } from "@/lib/catalog/store"
import { useHotkeysStore, type Hotkey } from "@/lib/hotkeys/store"
import { enqueueOp } from "@/lib/pos/pending-ops"
import { pendingHotkeys } from "@/lib/pos/local-register-state"

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
    queryFn: async () => {
      // La grilla que el cajero editó sin red gana sobre la del servidor
      // mientras siga en cola. Sin esto, el refetch que dispara la reconexión
      // puede entrar ANTES de que la cola drene y le borra la edición de la
      // pantalla — para que reaparezca sola unos segundos después, cuando el
      // PUT sí sale. Ver la regla de conflicto en `context/51`.
      const queued = await pendingHotkeys(activeRegisterId)
      if (queued) return { hotkeys: queued }
      return api.get<HotkeysResponse>("/v1/register?resource=hotkeys")
    },
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
    mutationFn: async (hotkeys) => {
      /**
       * Sin red la grilla se encola y el cajero la sigue viendo como la dejó
       * (el store ya persiste en localStorage). La cola manda la grilla ENTERA
       * porque el endpoint no tiene forma parcial; encolar una sola reemplaza
       * a la anterior en vez de acumularse, así que reacomodar la grilla diez
       * veces sin red no son diez requests al volver la conexión.
       */
      const enqueueOffline = async (): Promise<HotkeysResponse> => {
        await enqueueOp({
          kind: "hotkeys",
          stream: "hotkeys",
          registerId: activeRegisterId,
          payload: { hotkeys },
          label: "Accesos directos de la caja",
          mergePayload: (_prev, next) => next,
        })
        return { hotkeys }
      }

      if (typeof navigator !== "undefined" && !navigator.onLine) return enqueueOffline()
      try {
        return await api.put<HotkeysResponse>("/v1/register?resource=hotkeys", { hotkeys })
      } catch (err) {
        // Solo el fallo de red se encola. Un rechazo del servidor (permiso,
        // validación) es una respuesta y tiene que llegarle al cajero como
        // error, no quedar en cola reintentándose contra un "no".
        if (err instanceof TypeError) return enqueueOffline()
        throw err
      }
    },
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
