"use client"

/**
 * Config del POS por caja (toggles Ajustes → controlCaja, tecladoVirtual, etc).
 *
 * El device POS corre con Bearer del device (realm `pos-app`), no con la
 * cookie `_jwt_panel` del panel. Antes este hook pegaba con `api-client`
 * (cookie `_jwt_panel`) — en un device sin sesión de panel abierta el GET
 * fallaba silencioso, `registerConfigData` quedaba undefined y
 * `controlCaja ?? true` mostraba siempre la sección de caja aunque el
 * switch estuviera OFF. Va por `posFetch` (Bearer device) contra el BFF
 * `/api/pos/register-config`, que proxea a `/v1/register?resource=config`
 * (mismo patrón que `use-pos-outlets.ts` / `use-drawer.ts`).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"
import { useCatalogStore } from "@/lib/catalog/store"

export type PosRegisterConfig = {
  /**
   * Control de caja a ciegas — READ-ONLY en el POS. Se administra desde el
   * panel (Sucursal → Cajas → Editar); el PUT del device lo ignora por
   * whitelist server-side. Con true, el dashboard del turno y el arqueo no
   * muestran montos acumulados.
   */
  blindControl: boolean
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
  blindControl: false,
  controlCaja: true,
  tecladoVirtual: false,
  ordenEnVenta: false,
  ordenAImpresion: false,
  servidorImpresion: false,
  sonidosAlertas: false,
  inhabilitarAnimaciones: false,
  // Opt-out: por default se puede guardar venta. false apagaría "Guardar"
  // a todos los tenants sin config explícita (esto recién queda gateado
  // en sale-options-drawer.tsx / nav de guardadas — spec owner 2026-07-31).
  permitirGuardarVentas: true,
  ocultarDetalleCombos: false,
  modoSoloOrdenes: false,
  mergeRepeated: true,
  showSoftKeyboard: false,
}

interface PosRegisterConfigResponse {
  config: PosRegisterConfig
}

async function posJson<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await posFetch(url, init)
  const json = await res.json().catch(() => null)
  if (!res.ok || !json?.ok) {
    throw new Error(json?.error?.message ?? `Error ${res.status}`)
  }
  return json.data as T
}

export function usePosRegisterConfig(registerId: string) {
  return useQuery<PosRegisterConfigResponse>({
    queryKey: ["pos-config", registerId],
    queryFn: () => posJson<PosRegisterConfigResponse>("/api/pos/register-config"),
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
      posJson<PosRegisterConfigResponse>("/api/pos/register-config", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ config: patch }),
      }),
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
