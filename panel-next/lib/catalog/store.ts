/**
 * Store en memoria del catálogo del POS (Zustand).
 *
 * Toda la UI del POS lee de este store — NUNCA hace fetch directo para
 * buscar un producto o un cliente. Esto garantiza:
 *   1. Búsqueda síncrona e instantánea (cero round-trips).
 *   2. Frontera offline: al activarla (fase offline), solo cambia la
 *      fuente de hidratación (BFF → IndexedDB), la UI no toca.
 *
 * Ciclo de vida:
 *   1. Al iniciar sesión de caja, `hydrate()` se llama con los datos del
 *      BFF `/api/pos/bootstrap`. El store pasa a `status: 'ready'`.
 *   2. La UI busca en `lib/catalog/search.ts` (índice local sobre `items`).
 *   3. Los comandos (`lib/commands/`) mutan vía el BFF y llaman a
 *      `patchCustomer` / `patchItem` para actualizar el store sin
 *      re-fetch total.
 *
 * TODO (Slice A): conectar `hydrate()` al fetch real de `/api/pos/bootstrap`.
 * TODO (Fase offline): persistir en IndexedDB (Dexie) + delta-sync.
 *
 * Ver context/16-app-next-rewrite.md §5 (frontera offline) y §7 Slice A.
 */

import { create } from "zustand"
import type { PosItem, PosCustomer, PosConfig, PosRegister } from "@/lib/types/pos-bootstrap"

export type CatalogStatus = "idle" | "loading" | "ready" | "error"

interface CatalogState {
  status: CatalogStatus
  error: string | null

  // ── Datos del catálogo ────────────────────────────────────────────────────
  items: PosItem[]
  customers: PosCustomer[]
  config: PosConfig | null
  registers: PosRegister[]

  // ── Acciones ──────────────────────────────────────────────────────────────

  /**
   * Hidrata el store con los datos del BFF bootstrap.
   * Llamar una vez al iniciar sesión de caja.
   * TODO (Slice A): reemplazar el stub con fetch real.
   */
  hydrate: (data: {
    items: PosItem[]
    customers: PosCustomer[]
    config: PosConfig
    registers: PosRegister[]
  }) => void

  /** Actualiza un cliente en memoria tras un CREATE/UPDATE exitoso. */
  patchCustomer: (customer: PosCustomer) => void

  /** Actualiza un item en memoria si el precio/config cambia. */
  patchItem: (item: PosItem) => void

  /** Reset completo (logout / cambio de outlet). */
  reset: () => void
}

const initialState = {
  status: "idle" as CatalogStatus,
  error: null,
  items: [],
  customers: [],
  config: null,
  registers: [],
}

export const useCatalogStore = create<CatalogState>()((set) => ({
  ...initialState,

  hydrate: (data) => {
    set({
      status: "ready",
      error: null,
      items: data.items,
      customers: data.customers,
      config: data.config,
      registers: data.registers,
    })
  },

  patchCustomer: (customer) => {
    set((state) => ({
      customers: state.customers.some((c) => c.id === customer.id)
        ? state.customers.map((c) => (c.id === customer.id ? customer : c))
        : [customer, ...state.customers],
    }))
  },

  patchItem: (item) => {
    set((state) => ({
      items: state.items.map((i) => (i.id === item.id ? item : i)),
    }))
  },

  reset: () => set(initialState),
}))
