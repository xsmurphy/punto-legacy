"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { ModulesMap } from "@/lib/types/module"

/**
 * Devuelve el mapa completo de módulos nativos (GET /v1/modules).
 * staleTime 60s — los módulos cambian raramente; no vale un refetch por focus.
 */
export function useModules() {
  return useQuery<ModulesMap>({
    queryKey: ["modules"],
    queryFn: () => api.get<ModulesMap>("/v1/modules"),
    staleTime: 60 * 1000,
  })
}

/**
 * Activa / desactiva un módulo.
 * Invalida ["modules"] y ["bootstrap"] (el POS sync depende del estado de módulos).
 */
export function useToggleModule() {
  const qc = useQueryClient()
  return useMutation<unknown, Error, { key: string; enabled: boolean }>({
    mutationFn: ({ key, enabled }) =>
      api.post("/v1/modules", { action: "toggle", key, enabled }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["modules"] })
      qc.invalidateQueries({ queryKey: ["bootstrap"] })
    },
  })
}

/**
 * Actualiza la config de un módulo con config-bearing
 * (loyalty, tables, ordersPanel, feedback, crm).
 */
export function useUpdateModuleConfig() {
  const qc = useQueryClient()
  return useMutation<
    unknown,
    Error,
    { key: string; config: Record<string, unknown> }
  >({
    mutationFn: ({ key, config }) =>
      api.post("/v1/modules", {
        action: "config",
        key,
        config: JSON.stringify(config),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["modules"] })
    },
  })
}
