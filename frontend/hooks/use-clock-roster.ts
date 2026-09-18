"use client"

/**
 * El personal que puede marcar en este reloj (context/83 §9.2).
 *
 * ── Es el bootstrap del reloj, y por eso es tan chico ──────────────────────
 *
 * Hasta el §9.2 esta lista bajaba dentro del bootstrap del POS, o sea a TODA
 * caja del comercio. El owner movió la marcación a un dispositivo propio, así
 * que la lista baja a UN aparato y por su propio camino. Lo demás que el reloj
 * necesita —nombre del comercio, sucursal, formatos— ya lo trae el contexto del
 * device (`usePairedScreen`), que es el mismo que usan las otras pantallas
 * pareadas: no hace falta un segundo endpoint que lo repita.
 *
 * ── Funciona sin internet ──────────────────────────────────────────────────
 *
 * La lista se cachea por sucursal (`lib/clock/roster-cache.ts`) y el hook cae
 * ahí cuando la red no contesta. Sin eso, el reloj de un comercio con el
 * internet caído dejaría a la gente sin poder fichar, que es exactamente lo que
 * la D7 no permite.
 *
 * ── Cómo se entera de que cambió ───────────────────────────────────────────
 *
 * Por el `queryKey`. La entidad `employee` del bus de invalidación mapea a
 * `["employees"]`, y como esta consulta cuelga de ahí, tocar un legajo en el
 * panel —dar de alta, egresar, cambiar el código— la refresca sola.
 */

import { useQuery } from "@tanstack/react-query"

import { ApiError } from "@/lib/api-client"
import { posFetch } from "@/lib/api/pos-fetch"
import { loadRoster, saveRoster } from "@/lib/clock/roster-cache"
import type { ClockEmployee } from "@/lib/types/clock"

export interface ClockRoster {
  employees: ClockEmployee[]
  /**
   * Esta lista salió del caché local y no de la red. La pantalla no lo muestra
   * —no es información accionable para quien marca, y el owner pidió que el
   * reloj no hable de conexión— pero el hook lo distingue para no pisar un dato
   * fresco con uno viejo.
   */
  fromCache: boolean
}

const KEY = ["employees", "clock-roster"] as const

/**
 * `outletId` NO se manda al servidor: el alcance lo resuelve el backend con el
 * contexto del device. Acá sirve solo para la clave del caché local.
 */
export function useClockRoster(outletId: string, enabled = true) {
  return useQuery<ClockRoster>({
    queryKey: [...KEY, outletId],
    enabled,
    queryFn: async () => {
      try {
        const res = await posFetch("/api/v1/attendance?resource=roster", {}, "clock")
        const json = (await res.json().catch(() => null)) as {
          ok?: boolean
          data?: { employees?: ClockEmployee[] }
        } | null

        if (!res.ok || json?.ok === false) {
          throw new ApiError(res.status, json, `Error ${res.status}`)
        }

        const employees = Array.isArray(json?.data?.employees) ? json.data.employees : []
        // El caché se refresca con lo que acaba de llegar, incluso vacío: una
        // lista vacía es un dato (el comercio no cargó a nadie), no una falla, y
        // conservar la anterior dejaría fichando a alguien que ya egresó.
        void saveRoster(outletId, employees)

        return { employees, fromCache: false }
      } catch {
        const cached = await loadRoster(outletId)
        return { employees: cached?.employees ?? [], fromCache: true }
      }
    },
    // Lo que la refresca de verdad es el evento de realtime, no el paso del
    // tiempo: el personal de una sucursal cambia de mes en mes.
    staleTime: 5 * 60 * 1000,
    // Sin reintentos: el fallo ya cae al caché local, que es una respuesta
    // válida. Reintentar solo demoraría esa caída.
    retry: false,
  })
}
