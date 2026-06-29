"use client"

import * as React from "react"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { usePosUIStore } from "@/lib/ui/store"
import { usePosRegisterConfig } from "@/hooks/use-pos-config"

/**
 * Bridge entre el server-state de pos-config (BD por registerId) y los stores
 * Zustand locales (cart / ui). El server-state es la fuente de verdad;
 * los stores quedan como cache de lectura sincronizada para los consumidores
 * que no toleran async (carrito, render del POS).
 *
 * Montar UNA vez dentro del layout del POS.
 */
export function PosConfigSync() {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data } = usePosRegisterConfig(activeRegisterId)

  React.useEffect(() => {
    if (!data?.config) return
    useCartStore.setState({ mergeRepeated: data.config.mergeRepeated })
    usePosUIStore.setState({ showSoftKeyboard: data.config.showSoftKeyboard })
  }, [data])

  return null
}
