"use client"

import { useQuery } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"
import type { ModulesMap } from "@/lib/types/module"

/**
 * ¿El módulo está activo? Solo responde `false` cuando el backend DIJO que está
 * apagado; mientras no haya respuesta buena, devuelve `undefined`.
 *
 * La versión anterior era `!isLoading && m?.[key]?.enabled === true`, que
 * colapsaba tres estados distintos —cargando, error y apagado— en un mismo
 * `false`. Con eso, un fallo de red o un 401 escondía Espacios y Órdenes sin
 * decir nada: el cajero veía el sidebar vacío y el panel seguía mostrando los
 * módulos habilitados. Un módulo no puede desaparecer por un error de lectura.
 */
export function posModuleEnabled(
  m: ModulesMap | undefined,
  isLoading: boolean,
  isError: boolean,
  key: string,
): boolean | undefined {
  if (isLoading || isError || m === undefined) return undefined
  return m?.[key]?.enabled === true
}

/**
 * Módulos activos del comercio, leídos con la sesión del DISPOSITIVO.
 *
 * Cliente: `posFetch` (Bearer del device) — NUNCA `api-client`, que manda la
 * cookie del panel. `useModules()` (panel) sigue existiendo para el panel; el
 * POS usa ESTE.
 *
 * El bug que arregla: el sidebar del POS decide con esto si muestra Espacios y
 * Órdenes, y lo pedía con el cliente del panel. La cookie `_jwt_panel` vence a
 * las 24 h, la sesión del dispositivo no vence nunca — así que de un día para
 * el otro la request daba 401, el hook se quedaba sin datos y los módulos
 * desaparecían del sidebar sin ningún error a la vista, mientras el panel los
 * seguía mostrando habilitados.
 *
 * `staleTime` alto y `retry`: activar un módulo es una acción rara y
 * deliberada; lo caro acá es quedarse sin datos, no tener un dato de un minuto
 * de antigüedad.
 */
export function usePosModules() {
  return useQuery<ModulesMap>({
    queryKey: ["pos-modules"],
    queryFn: async () => {
      const res = await posFetch("/api/pos/modules", { method: "GET" })
      if (!res.ok) {
        throw new Error("No se pudieron leer los módulos del comercio")
      }
      const json = await res.json()
      // El endpoint responde el mapa directo o envuelto en `data` según el
      // helper de respuesta del backend — se aceptan las dos formas para no
      // depender de un detalle que no es de este hook.
      return (json?.data ?? json) as ModulesMap
    },
    staleTime: 5 * 60 * 1000,
    retry: 2,
  })
}
