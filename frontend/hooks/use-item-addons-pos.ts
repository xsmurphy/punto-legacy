"use client"

/**
 * Add-ons de un producto, para el POS (F4, context/41-addons-y-combos.md).
 *
 * Fuente: `GET /api/pos/item-addons?itemId=X` (BFF del POS, proyección
 * explícita — ver `app/api/pos/item-addons/route.ts`).
 *
 * Cliente: `posFetch` (Bearer del device, realm `pos-app`). NUNCA `api-client`
 * — ese lleva la cookie del panel y en un device sin sesión de operador da 401
 * silencioso (regla del proyecto: un cliente HTTP por realm).
 *
 * NO confundir con `hooks/use-item-addons.ts`: ese es el del PANEL (cookie,
 * incluye las mutaciones replace/copy de la ficha del producto). El device solo
 * lee.
 *
 * `enabled: !!itemId` — con el modal cerrado no se pide nada. `staleTime` 30s:
 * el catálogo de add-ons cambia en el panel, no en la caja; 30s evita un fetch
 * por cada apertura del modal sin dejar la caja con datos de la semana pasada.
 */

import { useQuery } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"

/** Opción de un grupo. Espejo de la proyección del BFF (sin `itemPrice`). */
export interface PosAddonOption {
  id: string
  itemId: string
  itemName: string
  /** Recargo de la opción. 0 = no suma al precio (D2). */
  priceDelta: number
  /** Preseleccionada al abrir el modal. */
  isDefault: boolean
  /** Fija: marcada y no se puede desmarcar (implica isDefault). */
  isLocked: boolean
  /** Cuántas veces se puede repetir la misma opción (≥ 1). */
  maxQty: number
  sort: number
}

export interface PosAddonGroup {
  id: string
  name: string
  /** 0 = grupo opcional. > 0 = el POS no deja confirmar sin elegir. */
  minSelect: number
  /** null = sin tope. */
  maxSelect: number | null
  sort: number
  options: PosAddonOption[]
}

export interface PosItemAddons {
  groups: PosAddonGroup[]
}

export function useItemAddonsPos(itemId: string | null) {
  return useQuery<PosItemAddons>({
    queryKey: ["pos", "item-addons", itemId],
    enabled: !!itemId,
    staleTime: 30 * 1000,
    // El POS puede estar con red de caja inestable: un reintento y el modal
    // pinta su estado de error con botón de reintentar, en vez de dejar al
    // cajero mirando un spinner.
    retry: 1,
    queryFn: async () => {
      const res = await posFetch(
        `/api/pos/item-addons?itemId=${encodeURIComponent(itemId as string)}`,
        { method: "GET" },
      )
      const json = await res.json().catch(() => null)
      if (!res.ok || !json?.ok) {
        throw new Error(json?.error?.message ?? `Error ${res.status}`)
      }
      const groups = Array.isArray(json.data?.groups) ? (json.data.groups as PosAddonGroup[]) : []
      return { groups }
    },
  })
}
