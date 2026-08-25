/**
 * Store en memoria del catálogo del POS (Zustand).
 *
 * Toda la UI del POS lee de este store — NUNCA hace fetch directo para
 * buscar un producto o un cliente. Esto garantiza:
 *   1. Búsqueda síncrona e instantánea (cero round-trips).
 *   2. Frontera offline: la UI no sabe —ni tiene que saber— si el catálogo
 *      vino de la red o del snapshot local. Solo cambia la fuente de
 *      hidratación.
 *
 * Ciclo de vida:
 *   1. Al iniciar sesión de caja, `hydrate()` se llama con los datos del
 *      BFF `/api/pos/bootstrap`. El store pasa a `status: 'ready'`.
 *   2. La UI busca en `lib/catalog/search.ts` (índice local sobre `items`).
 *   3. Los comandos (`lib/commands/`) mutan vía el BFF y llaman a
 *      `patchCustomer` / `patchItem` para actualizar el store sin
 *      re-fetch total.
 *
 * Persistencia (cerrado 2026-08-23 — el viejo "TODO: persistir en IndexedDB
 * (Dexie)"): el store sigue siendo SOLO memoria, y eso es deliberado. Lo que
 * se persiste es el bootstrap COMPLETO del que este store se hidrata, en
 * `lib/pos/bootstrap-cache.ts` (IndexedDB vía `idb`, no Dexie). Así hay un
 * solo formato persistido —la respuesta del BFF tal cual— en vez de un
 * espejo del state shape que habría que migrar cada vez que este store cambia
 * un campo. `usePosBootstrap()` sirve ese snapshot cuando la red no responde y
 * `useCatalogSeed` hidrata igual, sin enterarse.
 *
 * Ver context/16-app-next-rewrite.md §5 (frontera offline) y
 * context/43-sync-incremental.md.
 */

import { create } from "zustand"
import type { PosItem, PosCustomer, PosConfig, PosOutlet, PosRegister, PosTaxRate, PosCategory, PosBrand, PosUser, PaymentMethodConfig, PosPrintTemplate } from "@/lib/types/pos-bootstrap"

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
  /**
   * El bootstrap que hidrató este store NO traía la clave `users`.
   *
   * Distinto de `users: []`, que es el comercio diciendo "no hay ningún
   * operador habilitado acá". Esto dice "esta respuesta no vino con el roster
   * del dispositivo" — `/api` más viejo que el front, o una sesión que no es
   * la del device. Lo consume el lock screen para no acusar al comercio de un
   * problema de sesión (lockout 2026-08-25).
   */
  rosterMissing: boolean
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
  /**
   * Cuántos dígitos ocupa el correlativo de factura al imprimirse
   * (`document_sequence.padwidth`, mig 159; 7 = formato fiscal PY).
   *
   * Viaja con el snapshot del bootstrap porque el ticket se arma en el device
   * y la caja imprime sin red: el ancho tiene que estar acá, no consultarse
   * al momento de imprimir. Es FORMATO — el correlativo que el device asigna
   * (`lib/pos/invoice-numbering.ts`) sigue siendo un entero. Se consume solo
   * vía `lib/documents/format-document-number.ts`.
   */
  invoicePadWidth: number | null
  /**
   * Categorías y marcas del tenant (context/45-satelites-item-contact-sync.md
   * §Decisión: el VÍNCULO es satélite, la ENTIDAD no). `PosItem.categoryId`/
   * `brandId` las referencian por id — la UI resuelve el nombre contra estas
   * listas (`lib/catalog/resolve-names.ts`), nunca contra un campo copiado
   * dentro del ítem. Viajan con el snapshot offline igual que `taxes`.
   */
  categories: PosCategory[]
  brands: PosBrand[]
  /**
   * Plantillas de impresión del tenant (context/08 §53, hueco P0 cerrado
   * 2026-08-16). Antes `printSale`/`printTicketInBrowser` le pedían la
   * plantilla al server EN EL MOMENTO de imprimir (`fetch('/api/v1/
   * document-templates?id=...')`, sin cache ni fallback) — offline, ese
   * fetch fallaba y el ticket físico no salía, aunque la venta ya se hubiera
   * emitido y encolado bien. Ahora viajan acá, con el resto del bundle
   * `settings` — el binding SIEMPRE resuelve contra esta copia local (nunca
   * hace fetch en el camino de impresión), igual que el resto de datos
   * básicos de operación (context/43-sync-incremental.md).
   */
  printTemplates: PosPrintTemplate[]
  /**
   * `Date.now()` del último `patchItem(s)`/`removeItem(s)`/`patchCustomer(s)`/
   * `removeCustomer(s)` — sync realtime quirúrgico (context/15 §Modelo
   * quirúrgico). `useCatalogSeed` lo compara contra `dataUpdatedAt` del
   * bootstrap para decidir si una re-hidratación es más nueva que el último
   * patch (aplica) o más vieja (la descarta — pisaría un cambio que ya
   * llegó por WS con datos más frescos que el fetch que la trajo).
   */
  lastPatchedAt: number

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
    /**
     * `null`/ausente = el bootstrap no traía roster (ver `rosterMissing`). NO
     * es lo mismo que `[]` y `hydrate` no los colapsa.
     */
    users: PosUser[] | null | undefined
    activeRegisterId: string
    /**
     * Opcionales: un bootstrap cacheado ANTES de F2b (service worker /
     * react-query con TTL largo) no los trae. `hydrate` degrada a `[]`/`true`
     * — el carrito cae al fallback "sin tasa conocida → exenta" hasta que se
     * refresque el bootstrap real.
     */
    taxes?: PosTaxRate[]
    outletTaxIncluded?: boolean
    /** Opcional por el mismo motivo — bootstrap cacheado de antes de la mig 159. */
    invoicePadWidth?: number | null
    /** Opcionales por el mismo motivo que `taxes` — bootstrap cacheado viejo. */
    categories?: PosCategory[]
    brands?: PosBrand[]
    /** Opcional por el mismo motivo — bootstrap cacheado de antes del hueco P0 2026-08-16. */
    printTemplates?: PosPrintTemplate[]
  }) => void

  /** Actualiza (o agrega, si no existía) un cliente en memoria tras un CREATE/UPDATE exitoso. */
  patchCustomer: (customer: PosCustomer) => void

  /** Variante batch de `patchCustomer` — un solo `set()` para N clientes (sync realtime quirúrgico). */
  patchCustomers: (customers: PosCustomer[]) => void

  /** Saca un cliente del store (evento `delete`, o `id` que dejó de existir/pertenecer al tenant). */
  removeCustomer: (id: string) => void

  /** Variante batch de `removeCustomer`. */
  removeCustomers: (ids: string[]) => void

  /**
   * Actualiza (o agrega, si no existía) un item en memoria. El caso "agrega"
   * es necesario para el sync realtime quirúrgico: un evento `create` con id
   * pide el item nuevo al BFF y lo mergea acá — antes de esto `patchItem`
   * solo reemplazaba items YA presentes y un item creado en otra caja nunca
   * aparecía hasta el próximo bootstrap completo.
   */
  patchItem: (item: PosItem) => void

  /** Variante batch de `patchItem` — un solo `set()` para N items (sync realtime quirúrgico). */
  patchItems: (items: PosItem[]) => void

  /** Saca un item del store (evento `delete`, o item que quedó inactivo/no-vendible). */
  removeItem: (id: string) => void

  /** Variante batch de `removeItem`. */
  removeItems: (ids: string[]) => void

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
  rosterMissing: false,
  activeRegisterId: "",
  taxes: [] as PosTaxRate[],
  outletTaxIncluded: true,
  invoicePadWidth: null as number | null,
  categories: [] as PosCategory[],
  brands: [] as PosBrand[],
  printTemplates: [] as PosPrintTemplate[],
  lastPatchedAt: 0,
}

/** Reemplaza-o-agrega por id, preservando el orden de `prev` para los ya existentes. */
function mergeById<T extends { id: string }>(prev: T[], incoming: T[]): T[] {
  const byId = new Map(incoming.map((x) => [x.id, x]))
  const merged = prev.map((x) => byId.get(x.id) ?? x)
  const existingIds = new Set(prev.map((x) => x.id))
  const brandNew = incoming.filter((x) => !existingIds.has(x.id))
  return [...brandNew, ...merged]
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
      // Roster ausente ≠ roster vacío. Los consumidores siguen leyendo un
      // array (nadie tiene que chequear null), pero el hecho de que no haya
      // venido queda registrado aparte para que el lock screen pueda decir la
      // verdad sobre por qué no tiene PINs contra los que validar.
      users: data.users ?? [],
      rosterMissing: data.users == null,
      activeRegisterId: data.activeRegisterId,
      // Bootstrap cacheado viejo sin estos campos (ver JSDoc de `hydrate`) →
      // degradación: [] hace que toda línea sin tasa conocida caiga a
      // exenta; `true` es el mismo default fiscal que usa el backend cuando
      // el outlet nunca configuró itemsTaxIncluded.
      taxes: data.taxes ?? [],
      outletTaxIncluded: data.outletTaxIncluded ?? true,
      // `null` → el formateador pone el default legal (7). Nunca "sin
      // padding": un bootstrap viejo no puede hacer que la caja imprima
      // números fuera del formato fiscal.
      invoicePadWidth: data.invoicePadWidth ?? null,
      categories: data.categories ?? [],
      brands: data.brands ?? [],
      printTemplates: data.printTemplates ?? [],
    })
  },

  patchCustomer: (customer) => {
    set((state) => ({
      customers: mergeById(state.customers, [customer]),
      lastPatchedAt: Date.now(),
    }))
  },

  patchCustomers: (customers) => {
    if (customers.length === 0) return
    set((state) => ({
      customers: mergeById(state.customers, customers),
      lastPatchedAt: Date.now(),
    }))
  },

  removeCustomer: (id) => {
    set((state) => ({
      customers: state.customers.filter((c) => c.id !== id),
      lastPatchedAt: Date.now(),
    }))
  },

  removeCustomers: (ids) => {
    if (ids.length === 0) return
    const idSet = new Set(ids)
    set((state) => ({
      customers: state.customers.filter((c) => !idSet.has(c.id)),
      lastPatchedAt: Date.now(),
    }))
  },

  patchItem: (item) => {
    set((state) => ({
      items: mergeById(state.items, [item]),
      lastPatchedAt: Date.now(),
    }))
  },

  patchItems: (items) => {
    if (items.length === 0) return
    set((state) => ({
      items: mergeById(state.items, items),
      lastPatchedAt: Date.now(),
    }))
  },

  removeItem: (id) => {
    set((state) => ({
      items: state.items.filter((i) => i.id !== id),
      lastPatchedAt: Date.now(),
    }))
  },

  removeItems: (ids) => {
    if (ids.length === 0) return
    const idSet = new Set(ids)
    set((state) => ({
      items: state.items.filter((i) => !idSet.has(i.id)),
      lastPatchedAt: Date.now(),
    }))
  },

  resetActiveRegister: () => {
    set({ activeRegisterId: "" })
  },

  reset: () => set(initialState),
}))
