/**
 * Store global de UI del POS — abre/cierra dialogs disparados desde fuera
 * del CartPanel (ej. iconos del PosSidebar).
 *
 * Incluye los queries de búsqueda de productos y clientes para que persistan
 * entre aperturas/cierres del dialog dentro de la misma sesión POS. No se
 * usa localStorage — los queries se limpian al resetear la sesión (logout).
 */

import { create } from "zustand"

interface PosUIState {
  searchOpen: boolean
  customerOpen: boolean
  payOpen: boolean
  menuOpen: boolean
  optionsOpen: boolean
  /** Query activo del buscador de productos. Persiste al cerrar el modal. */
  itemSearchQuery: string
  /** Query activo del buscador de clientes. Persiste al cerrar el modal. */
  customerSearchQuery: string
  qtyPadMode: "int" | "decimal"
  discountPadMode: "money" | "percent"
  setSearchOpen: (v: boolean) => void
  setCustomerOpen: (v: boolean) => void
  setPayOpen: (v: boolean) => void
  setMenuOpen: (v: boolean) => void
  setOptionsOpen: (v: boolean) => void
  setItemSearchQuery: (q: string) => void
  setCustomerSearchQuery: (q: string) => void
  clearItemSearchQuery: () => void
  clearCustomerSearchQuery: () => void
  setQtyPadMode: (v: "int" | "decimal") => void
  setDiscountPadMode: (v: "money" | "percent") => void
  showSoftKeyboard: boolean
  setShowSoftKeyboard: (v: boolean) => void
}

export const usePosUIStore = create<PosUIState>()((set) => ({
  searchOpen: false,
  customerOpen: false,
  payOpen: false,
  menuOpen: false,
  optionsOpen: false,
  itemSearchQuery: "",
  customerSearchQuery: "",
  qtyPadMode: "int",
  discountPadMode: "money",
  setSearchOpen: (v) => set({ searchOpen: v }),
  setCustomerOpen: (v) => set({ customerOpen: v }),
  setPayOpen: (v) => set({ payOpen: v }),
  setMenuOpen: (v) => set({ menuOpen: v }),
  setOptionsOpen: (v) => set({ optionsOpen: v }),
  setItemSearchQuery: (q) => set({ itemSearchQuery: q }),
  setCustomerSearchQuery: (q) => set({ customerSearchQuery: q }),
  clearItemSearchQuery: () => set({ itemSearchQuery: "" }),
  clearCustomerSearchQuery: () => set({ customerSearchQuery: "" }),
  setQtyPadMode: (v) => set({ qtyPadMode: v }),
  setDiscountPadMode: (v) => set({ discountPadMode: v }),
  showSoftKeyboard: false,
  setShowSoftKeyboard: (v) => set({ showSoftKeyboard: v }),
}))
