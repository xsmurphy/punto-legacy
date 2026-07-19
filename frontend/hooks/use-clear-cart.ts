"use client"

/**
 * clear() del carrito que respeta `modoSoloOrdenes` (O1, context/24-orders-
 * module-plan.md): si el toggle está activo, el carrito se re-lockea a
 * posMode="orden" inmediatamente después de vaciarse — el POS "siempre
 * arranca y queda lockeado en modo orden" mientras el flag esté prendido.
 *
 * `lib/cart/store.ts` es deliberadamente config-agnóstico (no importa hooks
 * de config del register) — este hook es la única pieza que conoce el flag y
 * decide el re-lock, tal como indica el comentario de `clear()` en el store.
 *
 * Usar en TODO lugar que llame `useCartStore.getState().clear()` tras un
 * vaciado/cobro/orden confirmada (cart-panel Vaciar/Cancelar, pay-dialog
 * success, Ordenar exitoso) — así el comportamiento es consistente sin
 * duplicar el chequeo del flag en cada call-site.
 */

import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { usePosRegisterConfig } from "@/hooks/use-pos-config"

export function useClearCart() {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: configData } = usePosRegisterConfig(activeRegisterId)
  const modoSoloOrdenes = configData?.config?.modoSoloOrdenes ?? false

  return function clearCart() {
    useCartStore.getState().clear()
    if (modoSoloOrdenes) {
      useCartStore.getState().setPosMode("orden")
    }
  }
}
