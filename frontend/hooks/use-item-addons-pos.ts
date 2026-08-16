"use client"

/**
 * Add-ons de un producto, para el POS (F4, context/41-addons-y-combos.md).
 *
 * Hueco P0 cerrado 2026-08-16 (context/08 §53): antes pedía `GET
 * /api/pos/item-addons?itemId=X` al servidor CADA VEZ que se abría el modal
 * — sin conexión, cualquier ítem con un grupo obligatorio (`minSelect > 0`)
 * era invendible, y en gastronomía ese es el flujo NORMAL, no un borde.
 *
 * Ahora lee `PosItem.addonGroups`, ya embebido en el ítem del bootstrap
 * (`useCatalogStore` — mismo dato que items/customers/taxes, cero fetch en
 * el camino de armado del carrito). Alineado con
 * `context/45-satelites-item-contact-sync.md`: add-ons es satélite de
 * `item`, así que viaja DENTRO del payload del ítem — no un mecanismo de
 * cache paralelo.
 *
 * Se mantiene la forma de `useQuery` (data/isPending/isError/refetch) para
 * no tocar `addon-picker-dialog.tsx`: con el ítem ya en el store, la
 * "query" resuelve sync en el primer render — `isPending` prácticamente
 * nunca es `true`, `isError` nunca dispara (no hay red que falle). `refetch`
 * sigue siendo útil (relee el store), pero ya no hace ningún request — solo
 * vuelve a evaluar `queryFn` contra el snapshot actual de `item.addonGroups`.
 *
 * NO confundir con `hooks/use-item-addons.ts`: ese es el del PANEL (cookie,
 * incluye las mutaciones replace/copy de la ficha del producto, SIEMPRE
 * red — el panel no tiene catálogo local). El device solo lee, y ahora lee
 * local.
 */

import { useQuery } from "@tanstack/react-query"
import { useCatalogStore } from "@/lib/catalog/store"
import type { PosAddonGroup, PosAddonOption } from "@/lib/types/pos-bootstrap"

export type { PosAddonGroup, PosAddonOption }

export interface PosItemAddons {
  groups: PosAddonGroup[]
}

export function useItemAddonsPos(itemId: string | null) {
  // Selector reactivo: si `patchItem`/`patchItems` actualiza este ítem
  // (realtime, ver use-realtime-sync.ts entity 'item') mientras el modal
  // está abierto, el modal ve los grupos nuevos sin tener que reabrirse.
  const item = useCatalogStore((s) => (itemId ? s.items.find((i) => i.id === itemId) : undefined))

  return useQuery<PosItemAddons>({
    queryKey: ["pos", "item-addons", itemId, item?.addonGroups],
    enabled: !!itemId,
    // Local — no hay staleTime que gestionar (no es una respuesta de red
    // que pueda "envejecer" independiente del store).
    staleTime: Infinity,
    queryFn: () => ({ groups: item?.addonGroups ?? [] }),
  })
}
