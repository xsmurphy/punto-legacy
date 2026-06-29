"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import { useCatalogStore } from "@/lib/catalog/store"

export type PosRegisterConfig = {
  controlCaja: boolean
  tecladoVirtual: boolean
  ordenEnVenta: boolean
  ordenAImpresion: boolean
  servidorImpresion: boolean
  sonidosAlertas: boolean
  inhabilitarAnimaciones: boolean
  permitirGuardarVentas: boolean
  ocultarDetalleCombos: boolean
  modoSoloOrdenes: boolean
  mergeRepeated: boolean
  showSoftKeyboard: boolean
}

export const POS_REGISTER_CONFIG_DEFAULTS: PosRegisterConfig = {
  controlCaja: true,
  tecladoVirtual: false,
  ordenEnVenta: false,
  ordenAImpresion: false,
  servidorImpresion: false,
  sonidosAlertas: false,
  inhabilitarAnimaciones: false,
  permitirGuardarVentas: false,
  ocultarDetalleCombos: false,
  modoSoloOrdenes: false,
  mergeRepeated: true,
  showSoftKeyboard: false,
}

interface PosRegisterConfigResponse {
  config: PosRegisterConfig
}

export function usePosRegisterConfig(registerId: string) {
  return useQuery<PosRegisterConfigResponse>({
    queryKey: ["pos-config", registerId],
    queryFn: () => api.get<PosRegisterConfigResponse>("/v1/register?resource=config"),
    enabled: registerId !== "",
    staleTime: 30 * 1000,
    refetchOnWindowFocus: false,
  })
}

export function useUpdatePosRegisterConfig() {
  const qc = useQueryClient()
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)

  return useMutation<PosRegisterConfigResponse, Error, Partial<PosRegisterConfig>, { prev: PosRegisterConfigResponse | undefined }>({
    mutationFn: (patch) =>
      api.put<PosRegisterConfigResponse>("/v1/register?resource=config", { config: patch }),
    onMutate: async (patch) => {
      const key = ["pos-config", activeRegisterId]
      await qc.cancelQueries({ queryKey: key })
      const prev = qc.getQueryData<PosRegisterConfigResponse>(key)
      if (prev) {
        qc.setQueryData<PosRegisterConfigResponse>(key, {
          config: { ...prev.config, ...patch },
        })
      }
      return { prev }
    },
    onError: (_err, _patch, ctx) => {
      if (ctx?.prev) {
        qc.setQueryData(["pos-config", activeRegisterId], ctx.prev)
      }
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ["pos-config", activeRegisterId] })
    },
  })
}
