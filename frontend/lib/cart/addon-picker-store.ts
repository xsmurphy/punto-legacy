/**
 * Store del flujo de SELECCIÓN de add-ons (F4, context/41-addons-y-combos.md).
 *
 * Mismo patrón que `giftcard-issue-store.ts`: `addCatalogItem()` intercepta el
 * click en un ítem con `hasAddons` ANTES de agregarlo al carrito y abre este
 * store con el ítem pendiente. `<AddonPickerDialog>` (montado una sola vez en
 * `cart-panel.tsx`, junto a `<GiftcardIssueDialog>`) lo lee y, al confirmar,
 * recién ahí agrega la línea con sus `selections`.
 *
 * El intercept vive en el WRAPPER compartido y no en `product-area.tsx` porque
 * los tres call-sites del POS pasan por ahí (grilla/hotkeys, búsqueda de
 * productos y scanner de barras): con el chequeo en un solo call-site, escanear
 * el código de barras de una hamburguesa la agregaría sin preguntar los
 * add-ons, con el precio equivocado.
 *
 * Dos modos:
 *   - ALTA (`editingLineId === null`): confirmar agrega una línea nueva.
 *   - EDICIÓN (`editingLineId` seteado): confirmar REEMPLAZA las selecciones
 *     de esa línea (`useCartStore.setLineSelections`), sin tocar cantidad ni
 *     precio manual.
 *
 * Cancelar no agrega ni modifica nada — igual que no haber tocado el producto.
 */

import { create } from "zustand"
import type { PosItem } from "@/lib/types/pos-bootstrap"
import type { CartLineAddon } from "@/lib/cart/store"

interface AddonPickerState {
  pendingItem: PosItem | null
  /** Línea del carrito que se está editando. null = alta de línea nueva. */
  editingLineId: string | null
  /** Selecciones con las que abre el modal (las de la línea, al editar). */
  initialSelections: CartLineAddon[]
  open: (item: PosItem) => void
  openForLine: (item: PosItem, lineId: string, selections: CartLineAddon[]) => void
  close: () => void
}

export const useAddonPickerStore = create<AddonPickerState>()((set) => ({
  pendingItem: null,
  editingLineId: null,
  initialSelections: [],
  open: (item) => set({ pendingItem: item, editingLineId: null, initialSelections: [] }),
  openForLine: (item, lineId, selections) =>
    set({ pendingItem: item, editingLineId: lineId, initialSelections: selections }),
  close: () => set({ pendingItem: null, editingLineId: null, initialSelections: [] }),
}))
