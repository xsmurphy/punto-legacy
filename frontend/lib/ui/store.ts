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
  /**
   * Selector de MODO del POS (venta / orden / cotización / …). Vive en el
   * store y no como estado local del sidebar: el trigger está en el sidebar
   * (que en mobile es un Sheet que se cierra al tocar) y el dialog tiene que
   * sobrevivir a ese cierre montado desde el layout del POS.
   */
  modeDialogOpen: boolean
  /** Query activo del buscador de productos. Persiste al cerrar el modal. */
  itemSearchQuery: string
  /** Query activo del buscador de clientes. Persiste al cerrar el modal. */
  customerSearchQuery: string
  discountPadMode: "money" | "percent"
  setSearchOpen: (v: boolean) => void
  setCustomerOpen: (v: boolean) => void
  setPayOpen: (v: boolean) => void
  setMenuOpen: (v: boolean) => void
  setOptionsOpen: (v: boolean) => void
  setModeDialogOpen: (v: boolean) => void
  setItemSearchQuery: (q: string) => void
  setCustomerSearchQuery: (q: string) => void
  clearItemSearchQuery: () => void
  clearCustomerSearchQuery: () => void
  setDiscountPadMode: (v: "money" | "percent") => void
  showSoftKeyboard: boolean
  setShowSoftKeyboard: (v: boolean) => void
  /**
   * Guardado de cotización en vuelo (sale-options-drawer.tsx → createQuote()).
   * Cotización NO es un posMode sticky (lib/cart/store.ts) — esta es la única
   * ventana de tiempo en la que existe un "modo cotización" real, y CartPanel
   * la usa para pintar CTA + banda amber (context/20 "Colores de modo del POS").
   */
  savingQuote: boolean
  setSavingQuote: (v: boolean) => void
  /**
   * Disparador del guardado de cotización desde el CTA del carrito (modo
   * cotización sticky, 2026-07-30). El CTA vive en CartPanel pero el flujo de
   * guardado (createQuote + modal de éxito + impresión) es del
   * SaleOptionsDrawer — ambos montados juntos en CartPanel. El CTA incrementa
   * el nonce; el drawer lo observa y ejecuta. Contador y no boolean para que
   * dos pedidos seguidos disparen dos efectos.
   */
  quoteSaveNonce: number
  requestQuoteSave: () => void
}

export const usePosUIStore = create<PosUIState>()((set) => ({
  searchOpen: false,
  customerOpen: false,
  payOpen: false,
  menuOpen: false,
  optionsOpen: false,
  modeDialogOpen: false,
  itemSearchQuery: "",
  customerSearchQuery: "",
  discountPadMode: "money",
  setSearchOpen: (v) => set({ searchOpen: v }),
  setCustomerOpen: (v) => set({ customerOpen: v }),
  setPayOpen: (v) => set({ payOpen: v }),
  setMenuOpen: (v) => set({ menuOpen: v }),
  setOptionsOpen: (v) => set({ optionsOpen: v }),
  setModeDialogOpen: (v) => set({ modeDialogOpen: v }),
  setItemSearchQuery: (q) => set({ itemSearchQuery: q }),
  setCustomerSearchQuery: (q) => set({ customerSearchQuery: q }),
  clearItemSearchQuery: () => set({ itemSearchQuery: "" }),
  clearCustomerSearchQuery: () => set({ customerSearchQuery: "" }),
  setDiscountPadMode: (v) => set({ discountPadMode: v }),
  showSoftKeyboard: false,
  setShowSoftKeyboard: (v) => set({ showSoftKeyboard: v }),
  savingQuote: false,
  setSavingQuote: (v) => set({ savingQuote: v }),
  quoteSaveNonce: 0,
  requestQuoteSave: () => set((s) => ({ quoteSaveNonce: s.quoteSaveNonce + 1 })),
}))
