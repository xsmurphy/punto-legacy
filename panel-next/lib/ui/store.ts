/**
 * Store global de UI del POS — abre/cierra dialogs disparados desde fuera
 * del CartPanel (ej. iconos del PosSidebar).
 *
 * Mantenido mínimo a propósito: solo el estado de apertura. La lógica
 * (qué producto seleccionar, qué cliente, etc.) sigue en los stores de
 * dominio (cart, catalog) o en el state local del dialog.
 */

import { create } from "zustand"

interface PosUIState {
  searchOpen: boolean
  customerOpen: boolean
  payOpen: boolean
  menuOpen: boolean
  optionsOpen: boolean
  setSearchOpen: (v: boolean) => void
  setCustomerOpen: (v: boolean) => void
  setPayOpen: (v: boolean) => void
  setMenuOpen: (v: boolean) => void
  setOptionsOpen: (v: boolean) => void
}

export const usePosUIStore = create<PosUIState>()((set) => ({
  searchOpen: false,
  customerOpen: false,
  payOpen: false,
  menuOpen: false,
  optionsOpen: false,
  setSearchOpen: (v) => set({ searchOpen: v }),
  setCustomerOpen: (v) => set({ customerOpen: v }),
  setPayOpen: (v) => set({ payOpen: v }),
  setMenuOpen: (v) => set({ menuOpen: v }),
  setOptionsOpen: (v) => set({ optionsOpen: v }),
}))
