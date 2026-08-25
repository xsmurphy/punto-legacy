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
import { enqueueOp } from "@/lib/pos/pending-ops"
import {
  applyPendingConfigPatches,
  loadLocalRegisterConfig,
  saveLocalRegisterConfig,
} from "@/lib/pos/local-register-state"

export type PosRegisterConfig = {
  /**
   * Control de caja a ciegas — READ-ONLY en el POS. Se administra desde el
   * panel (Sucursal → Cajas → Editar); el PUT del device lo ignora por
   * whitelist server-side. Con true, el dashboard del turno y el arqueo no
   * muestran montos acumulados.
   */
  blindControl: boolean
  /**
   * Exigir órdenes y espacios cerrados para cerrar el turno — READ-ONLY en el
   * POS, igual que `blindControl`, pero es del COMERCIO y no de la caja: sale
   * de `company.config` (Ajustes → POS → "Cajas y arqueo"). Baja por acá y no
   * por el bootstrap para que la caché offline de la config lo tenga sin red:
   * sin conexión el POS no puede consultar qué hay abierto, pero sí tiene que
   * saber si la regla está prendida para avisar antes de encolar el cierre.
   */
  requireClosedOrders: boolean
  controlCaja: boolean
  /** IP/host del terminal Bancard (Caja POS Android) en la LAN de esta caja.
   *  Solo relevante con el módulo `bancardPos` activo (panel → Módulos). */
  bancardPosIp: string
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
  // Apagado por default: sin activarlo, el cierre se comporta como siempre.
  requireClosedOrders: false,
  controlCaja: true,
  bancardPosIp: "",
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

/**
 * Lee la config de la caja, con red o sin ella.
 *
 * El árbol es el mismo del arranque en frío del catálogo (`bootstrap-source`):
 * red → cache → nada. Y sobre lo que salga se aplican los patches en cola, en
 * las DOS ramas: una respuesta fresca del servidor todavía no incluye lo que
 * esta caja cambió hace un minuto y no pudo mandar, así que sin ese paso el
 * primer refetch le revierte al cajero un interruptor que él acaba de tocar
 * — que se lee como "el ajuste no anda", que es el bug que esto arregla.
 *
 * Cuando no hay red NI cache (device nuevo que nunca llegó a leer la config)
 * la query falla y los consumidores caen a `POS_REGISTER_CONFIG_DEFAULTS`. Es
 * lo correcto: inventar una config sería peor que usar la canónica.
 */
export function usePosRegisterConfig(registerId: string) {
  return useQuery<PosRegisterConfigResponse>({
    queryKey: ["pos-config", registerId],
    queryFn: async () => {
      try {
        const fresh = await posJson<PosRegisterConfigResponse>("/api/pos/register-config")
        await saveLocalRegisterConfig(registerId, fresh.config)
        return { config: await applyPendingConfigPatches(registerId, fresh.config) }
      } catch (err) {
        const cached = await loadLocalRegisterConfig(registerId)
        if (!cached) throw err
        return { config: await applyPendingConfigPatches(registerId, cached) }
      }
    },
    enabled: registerId !== "",
    staleTime: 30 * 1000,
    refetchOnWindowFocus: false,
  })
}

/**
 * ¿El fallo fue "no se pudo hablar con el servidor"?
 *
 * `navigator.onLine` no alcanza y el codebase ya lo sabe: dice `true` con un
 * cable enchufado a un router sin salida, o con el server caído. Lo que
 * decide si el ajuste se encola es que la request NO haya obtenido respuesta,
 * no lo que opine el navegador.
 */
function isUnreachable(err: unknown): boolean {
  if (typeof navigator !== "undefined" && !navigator.onLine) return true
  // `posJson` convierte el error de red en `TypeError` de fetch; un rechazo del
  // servidor llega como Error con el mensaje del envelope y NO se encola.
  return err instanceof TypeError
}

export function useUpdatePosRegisterConfig() {
  const qc = useQueryClient()
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)

  return useMutation<PosRegisterConfigResponse, Error, Partial<PosRegisterConfig>, { prev: PosRegisterConfigResponse | undefined }>({
    mutationFn: async (patch) => {
      const effective = (): PosRegisterConfig => ({
        ...POS_REGISTER_CONFIG_DEFAULTS,
        ...(qc.getQueryData<PosRegisterConfigResponse>(["pos-config", activeRegisterId])?.config ??
          {}),
        ...patch,
      })

      /**
       * Encolar es la respuesta a "no llegué", no a "me dijeron que no". Se
       * guarda el PATCH —solo las claves que el cajero tocó— porque ahí está
       * la regla de conflicto: el servidor lo mergea sobre lo que tenga
       * guardado, así que un cambio hecho desde el panel en OTRA clave
       * sobrevive. Ver `context/51`.
       */
      const enqueueOffline = async (): Promise<PosRegisterConfigResponse> => {
        const config = effective()
        await enqueueOp({
          kind: "posConfig",
          stream: "pos-config",
          registerId: activeRegisterId,
          payload: patch,
          label: "Ajustes de la caja",
          // Cinco interruptores tocados sin red son UN cambio de ajustes, no
          // cinco operaciones en cola.
          mergePayload: (prev, next) => ({
            ...(prev as Partial<PosRegisterConfig>),
            ...(next as Partial<PosRegisterConfig>),
          }),
        })
        await saveLocalRegisterConfig(activeRegisterId, config)
        return { config }
      }

      if (typeof navigator !== "undefined" && !navigator.onLine) {
        return enqueueOffline()
      }
      try {
        const saved = await posJson<PosRegisterConfigResponse>("/api/pos/register-config", {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ config: patch }),
        })
        await saveLocalRegisterConfig(activeRegisterId, saved.config)
        return saved
      } catch (err) {
        if (isUnreachable(err)) return enqueueOffline()
        throw err
      }
    },
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
