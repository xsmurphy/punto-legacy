"use client"

/**
 * Hooks de sucursales/cajas para el POS (Ajustes → selector de Sucursal/Caja).
 *
 * El device POS corre con Bearer del device (realm `pos-app`), no con la
 * cookie `_jwt_panel` del panel. `useOutlets`/`useRegistersAdmin` (hooks del
 * panel, en `hooks/use-outlets.ts` / `hooks/use-registers-admin.ts`) pegan
 * con `api-client` (cookie `_jwt_panel`) — en un device sin sesión de panel
 * abierta esos GET dan 401 silencioso y las listas quedan vacías.
 *
 * Estos hooks van por `posFetch` (Bearer device) contra los BFF
 * `/api/pos/outlets` y `/api/pos/registers`, que proxean a `/v1/outlets` y
 * `/v1/register?resource=listAll` — ambos habilitados server-side para el
 * realm `pos-app` en GET (mismo patrón que `/v1/price_list`).
 *
 * Queries propias (`["pos", "outlets"]` / `["pos", "registers"]`) — no
 * comparten cache con los hooks del panel.
 */

import { useQuery } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"
import type { OutletListItem } from "@/lib/types/outlet"
import type { RegisterListItem } from "@/hooks/use-registers-admin"

async function posGetJson<T>(url: string): Promise<T> {
  const res = await posFetch(url, { method: "GET" })
  const json = await res.json().catch(() => null)
  if (!res.ok || !json?.ok) {
    throw new Error(json?.error?.message ?? `Error ${res.status}`)
  }
  return json.data as T
}

/** Lista de sucursales del tenant, vía Bearer del device. */
export function usePosOutlets() {
  return useQuery<{ rows: OutletListItem[] }>({
    queryKey: ["pos", "outlets"],
    queryFn: () => posGetJson<{ rows: OutletListItem[] }>("/api/pos/outlets"),
    staleTime: 30 * 1000,
  })
}

/** Lista de todas las cajas del tenant, vía Bearer del device. */
export function usePosRegisters() {
  return useQuery<{ registers: RegisterListItem[] }>({
    queryKey: ["pos", "registers"],
    queryFn: () => posGetJson<{ registers: RegisterListItem[] }>("/api/pos/registers"),
    staleTime: 30 * 1000,
  })
}
