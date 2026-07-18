/**
 * Store del flujo de EMISIÓN de gift card (F2 giftcard-issue-flow).
 *
 * `addCatalogItem()` (lib/cart/add-catalog-item.ts) intercepta el click en un
 * item de catálogo kind="giftcard" ANTES de agregarlo al carrito — en vez de
 * llamar `useCartStore.addItem` directo, abre este store con el item
 * pendiente. `<GiftcardIssueDialog>` (montado una sola vez en el layout del
 * POS, mismo patrón que `<PayDialog>`) lo lee y, al confirmar, recién ahí
 * agrega la línea al carrito (con `giftcard` metadata) vía `addLines`.
 *
 * Cancelar el dialog no agrega nada — mismo comportamiento que no haber
 * clickeado el item.
 */

import { create } from "zustand"
import type { PosItem } from "@/lib/types/pos-bootstrap"

interface GiftcardIssueState {
  pendingItem: PosItem | null
  open: (item: PosItem) => void
  close: () => void
}

export const useGiftcardIssueStore = create<GiftcardIssueState>()((set) => ({
  pendingItem: null,
  open: (item) => set({ pendingItem: item }),
  close: () => set({ pendingItem: null }),
}))
