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
  /**
   * Sección abierta dentro del menú del POS (`pos-main-menu.tsx`), por `key`.
   * `null` = pantalla de bienvenida.
   *
   * Vive en el store y no como estado local del menú porque es un destino de
   * NAVEGACIÓN: el indicador de estado del carrito manda al cajero derecho a
   * "Ventas pendientes" (2026-08-23). Antes ese salto abría un diálogo aparte
   * (`SyncQueueDialog`) con una copia de la misma lista que la sección — dos
   * lugares para lo mismo, y la sección no listaba nada.
   */
  menuSection: string | null
  optionsOpen: boolean
  /**
   * Selector de MODO del POS (venta / orden / cotización / …). Vive en el
   * store y no como estado local del sidebar: el trigger está en el sidebar
   * (que en mobile es un Sheet que se cierra al tocar) y el dialog tiene que
   * sobrevivir a ese cierre montado desde el layout del POS.
   */
  modeDialogOpen: boolean
  /**
   * Asistente IA de la caja (context/59). Mismo motivo que `modeDialogOpen`:
   * el trigger está en el footer del sidebar —que en mobile es un drawer que
   * se cierra al tocar— y el diálogo se monta desde el layout del POS, así
   * que su estado de apertura no puede vivir en el sidebar.
   */
  agentDialogOpen: boolean
  /** Query activo del buscador de productos. Persiste al cerrar el modal. */
  itemSearchQuery: string
  /** Query activo del buscador de clientes. Persiste al cerrar el modal. */
  customerSearchQuery: string
  discountPadMode: "money" | "percent"
  setSearchOpen: (v: boolean) => void
  setCustomerOpen: (v: boolean) => void
  setPayOpen: (v: boolean) => void
  setMenuOpen: (v: boolean) => void
  setMenuSection: (key: string | null) => void
  /** Abre el menú directamente en una sección (deep-link desde el carrito). */
  openMenuSection: (key: string) => void
  setOptionsOpen: (v: boolean) => void
  setModeDialogOpen: (v: boolean) => void
  setAgentDialogOpen: (v: boolean) => void
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
  /**
   * Disparador de re-resolución de precios (bug de listas de precios,
   * 2026-08-16 — ver context/15-realtime-sync-plan.md). `usePriceContext`
   * (hooks/use-price-context.ts) re-corre `/v1/price_resolve` cuando cambia
   * el cliente, la lista elegida o las líneas del carrito — pero NO cuando
   * el admin edita la lista de precios activa mientras el carrito ya está
   * armado, porque `/v1/price_resolve` es una mutación sin queryKey (nada
   * la invalida). `useRealtimeSync` (clientScope pos) incrementa este nonce
   * al recibir un evento `price-list`; `usePriceContext` lo suma a su
   * dependencia de efecto y re-resuelve con el mismo contexto que ya tenía.
   * Mismo patrón que `quoteSaveNonce`: el hook de UI NO sabe de realtime, el
   * realtime solo mueve este estado.
   */
  priceResolveNonce: number
  bumpPriceResolveNonce: () => void
}

export const usePosUIStore = create<PosUIState>()((set) => ({
  searchOpen: false,
  customerOpen: false,
  payOpen: false,
  menuOpen: false,
  menuSection: null,
  optionsOpen: false,
  modeDialogOpen: false,
  agentDialogOpen: false,
  itemSearchQuery: "",
  customerSearchQuery: "",
  discountPadMode: "money",
  setSearchOpen: (v) => set({ searchOpen: v }),
  setCustomerOpen: (v) => set({ customerOpen: v }),
  setPayOpen: (v) => set({ payOpen: v }),
  // Cerrar el menú también olvida la sección: la próxima apertura arranca en
  // la bienvenida, como cuando `activeKey` era estado local del menú.
  setMenuOpen: (v) => set(v ? { menuOpen: true } : { menuOpen: false, menuSection: null }),
  setMenuSection: (key) => set({ menuSection: key }),
  openMenuSection: (key) => set({ menuOpen: true, menuSection: key }),
  setOptionsOpen: (v) => set({ optionsOpen: v }),
  setModeDialogOpen: (v) => set({ modeDialogOpen: v }),
  setAgentDialogOpen: (v) => set({ agentDialogOpen: v }),
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
  priceResolveNonce: 0,
  bumpPriceResolveNonce: () => set((s) => ({ priceResolveNonce: s.priceResolveNonce + 1 })),
}))
