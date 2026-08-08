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
import type { PosItem, PosCustomer, PosConfig, PosOutlet, PosRegister, PosTaxRate, PosUser, PaymentMethodConfig } from "@/lib/types/pos-bootstrap"

export type CatalogStatus = "idle" | "loading" | "ready" | "error"

interface CatalogState {
  status: CatalogStatus
  error: string | null

  // ── Datos del catálogo ────────────────────────────────────────────────────
  items: PosItem[]
  customers: PosCustomer[]
  config: PosConfig | null
  /** Sucursal activa (outlet). */
  outlet: PosOutlet | null
  /** Todas las sucursales del tenant (para el selector de setup). */
  outlets: Array<{ id: string; name: string }>
  registers: PosRegister[]
  paymentMethods: PaymentMethodConfig[]
  users: PosUser[]
  /** UUID de la caja activa. '' = sin caja seleccionada (guard la pide). */
  activeRegisterId: string
  /**
   * Tasas de impuesto del tenant (F2b, context/38) — el carrito las busca
   * por `taxId` para calcular el IVA con `lib/tax/engine.ts`. Viaja con el
   * snapshot offline igual que `items`/`config`: es solo el bootstrap
   * cacheado, sin fetch propio.
   */
  taxes: PosTaxRate[]
  /**
   * Default incluido/añadido de la sucursal activa. Fallback cuando
   * `PosItem.taxIncluded` (o `CartLine.taxIncluded`) es `null`.
   */
  outletTaxIncluded: boolean

  // ── Acciones ──────────────────────────────────────────────────────────────

  /**
   * Hidrata el store con los datos del BFF bootstrap.
   * Llamar una vez al iniciar sesión de caja.
   */
  hydrate: (data: {
    items: PosItem[]
    customers: PosCustomer[]
    config: PosConfig
    outlet: PosOutlet
    outlets: Array<{ id: string; name: string }>
    registers: PosRegister[]
    paymentMethods: PaymentMethodConfig[]
    users: PosUser[]
    activeRegisterId: string
    /**
     * Opcionales: un bootstrap cacheado ANTES de F2b (service worker /
     * react-query con TTL largo) no los trae. `hydrate` degrada a `[]`/`true`
     * — el carrito cae al fallback "sin tasa conocida → exenta" hasta que se
     * refresque el bootstrap real.
     */
    taxes?: PosTaxRate[]
    outletTaxIncluded?: boolean
  }) => void

  /** Actualiza un cliente en memoria tras un CREATE/UPDATE exitoso. */
  patchCustomer: (customer: PosCustomer) => void

  /** Actualiza un item en memoria si el precio/config cambia. */
  patchItem: (item: PosItem) => void

  /**
   * Resetea la caja activa a '' para forzar que el guard vuelva a mostrar
   * el modal de selección (acción "Cambiar caja/sucursal").
   */
  resetActiveRegister: () => void

  /** Reset completo (logout / cambio de outlet). */
  reset: () => void
}

const initialState = {
  status: "idle" as CatalogStatus,
  error: null,
  items: [],
  customers: [],
  config: null,
  outlet: null,
  outlets: [] as Array<{ id: string; name: string }>,
  registers: [],
  paymentMethods: [] as PaymentMethodConfig[],
  users: [] as PosUser[],
  activeRegisterId: "",
  taxes: [] as PosTaxRate[],
  outletTaxIncluded: true,
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
      outlet: data.outlet,
      outlets: data.outlets,
      registers: data.registers,
      paymentMethods: data.paymentMethods,
      users: data.users,
      activeRegisterId: data.activeRegisterId,
      // Bootstrap cacheado viejo sin estos campos (ver JSDoc de `hydrate`) →
      // degradación: [] hace que toda línea sin tasa conocida caiga a
      // exenta; `true` es el mismo default fiscal que usa el backend cuando
      // el outlet nunca configuró itemsTaxIncluded.
      taxes: data.taxes ?? [],
      outletTaxIncluded: data.outletTaxIncluded ?? true,
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

  resetActiveRegister: () => {
    set({ activeRegisterId: "" })
  },

  reset: () => set(initialState),
}))
