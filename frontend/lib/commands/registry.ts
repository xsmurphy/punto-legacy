/**
 * Registro de comandos del POS con flag `offlineEligible`.
 *
 * REGLA DE PRODUCTO (context/16 §5):
 *   - `offlineEligible: true`  → venta contado (type 0), crédito (type 3),
 *     alta/edición de cliente. Solo estos dos hoy; nada más.
 *   - `offlineEligible: false` → TODO lo demás es ONLINE OBLIGATORIO.
 *
 * Hoy todos los comandos ejecutan online. Cuando se active la fase offline,
 * un interceptor en el executor enciende/apaga según `navigator.onLine`:
 *   - Si !onLine && offlineEligible → encolar en IndexedDB (Dexie).
 *   - Si !onLine && !offlineEligible → throw OfflineError("requiere conexión").
 *
 * NO construir el interceptor ni IndexedDB ahora — solo respetar este registro
 * para que agregarlo sea una fase, no un re-rewrite.
 *
 * Ver context/16-app-next-rewrite.md §5 (frontera offline).
 */

export interface CommandMeta {
  /** Identificador canónico del comando (snake_case). */
  id: string
  /** Si puede ejecutarse offline (cola → sync al reconectar). */
  offlineEligible: boolean
  /** Descripción breve para logs / DevTools. */
  description: string
}

export const COMMAND_REGISTRY: Record<string, CommandMeta> = {
  createSale: {
    id: "createSale",
    offlineEligible: true,
    description: "Crear venta contado (type=0) o crédito (type=3). Payload idempotente por transactionUID.",
  },
  createCustomer: {
    id: "createCustomer",
    offlineEligible: true,
    description: "Alta de cliente. Offline-eligible: se sincroniza al reconectar.",
  },
  updateCustomer: {
    id: "updateCustomer",
    offlineEligible: true,
    description: "Edición de cliente. Offline-eligible: se sincroniza al reconectar.",
  },
  // ── TODO (Slice B+): registrar el resto de comandos como online-only ────────
  createRefund: {
    id: "createRefund",
    offlineEligible: false,
    description: "Devolución (type=6). Online obligatorio.",
  },
  createQuote: {
    id: "createQuote",
    offlineEligible: false,
    description: "Cotización (type=9). Online obligatorio.",
  },
  openDrawer: {
    id: "openDrawer",
    offlineEligible: false,
    description: "Arqueo/apertura de caja. Online obligatorio.",
  },
  closeDrawer: {
    id: "closeDrawer",
    offlineEligible: false,
    description: "Cierre de caja. Online obligatorio.",
  },
  loadGiftcard: {
    id: "loadGiftcard",
    offlineEligible: false,
    description: "Carga de gift card. Online obligatorio.",
  },
  reprint: {
    id: "reprint",
    offlineEligible: false,
    description: "Reimpresión de ticket. Online obligatorio.",
  },
} as const

/** Verifica si un comando es offline-eligible (helper para el interceptor futuro). */
export function isOfflineEligible(commandId: string): boolean {
  return COMMAND_REGISTRY[commandId]?.offlineEligible ?? false
}
