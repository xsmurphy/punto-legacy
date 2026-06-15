/**
 * Comando: createSale — venta contado (type=0) o crédito (type=3).
 *
 * offlineEligible: true (ver lib/commands/registry.ts).
 *
 * Hoy ejecuta ONLINE: llama al BFF → `api/v1/sales` → SaleService.php.
 * El payload incluye un `transactionUID` (UUID v7 generado client-side)
 * que el SaleService usa para deduplicar (UNIQUE constraint + dup-check),
 * lo que hace el comando idempotente ante retries / doble-click.
 *
 * Cuando se active la fase offline:
 *   - El interceptor del registry detecta `!navigator.onLine`.
 *   - Persiste el payload en IndexedDB (Dexie) con estado 'pending'.
 *   - Al reconectar, el sync worker llama a `executeSale()` por cada pending.
 *   - El transactionUID garantiza deduplicación server-side.
 *
 * TODO (Slice A): implementar la lógica real:
 *   1. Validar carrito (items, montos, cliente si es crédito).
 *   2. Generar transactionUID (UUID v7 — `import { v7 as uuidv7 } from 'uuid'`).
 *   3. Construir SalePayload según el shape de `api/v1/sales.php`.
 *   4. Llamar `api.post('/v1/sales', payload)`.
 *   5. Actualizar store (limpiar carrito, patch cliente si crédito).
 *   6. Disparar impresión via `lib/hardware/ticket-builder.ts` + `lib/hardware/qz-tray.ts`.
 *
 * Referencia visual legacy: `app/scripts/app.js` namespace `ncmTransactions`
 * funciones: `saveSale()`, `buildSalePayload()`, `postSale()`.
 * Referencia cobro: namespace `ncmPayments` funciones: `buildPaymentRows()`.
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A.
 */

import { api } from "@/lib/api-client"

// ── Shape del payload ─────────────────────────────────────────────────────────

export interface SaleItem {
  itemId: string
  quantity: number
  price: number
  discount: number | null
  note: string | null
}

export interface PaymentMethod {
  /** Código del método (ej. 'efectivo', 'tarjeta', 'transferencia'). */
  method: string
  amount: number
  /** Moneda ISO (ej. 'PYG', 'USD'). */
  currency: string
}

export interface CreateSalePayload {
  /**
   * UUID v7 generado client-side. El SaleService lo usa para deduplicar
   * (UNIQUE constraint en `transaction_uid`). Idempotente ante retries.
   */
  transactionUID: string
  /**
   * Tipo de transacción:
   *   0 = contado
   *   3 = crédito (requiere customerId con isCreditable=true)
   */
  type: 0 | 3
  items: SaleItem[]
  payments: PaymentMethod[]
  /** UUID del cliente. Requerido para type=3, opcional para type=0. */
  customerId: string | null
  registerId: string
  note: string | null
}

export interface CreateSaleResult {
  transactionId: string
  transactionUID: string
  invoiceNumber: string | null
  total: number
}

// ── Executor ──────────────────────────────────────────────────────────────────

/**
 * Ejecuta la venta contra el BFF.
 * TODO (Slice A): implementar validaciones + payload build + print trigger.
 * Hoy solo firma el contrato de la función.
 */
export async function executeSale(
  _payload: CreateSalePayload,
): Promise<CreateSaleResult> {
  // TODO (Slice A): implementar.
  // return api.post<CreateSaleResult>("/v1/sales", _payload)
  throw new Error("createSale: no implementado aún — ver TODO en lib/commands/create-sale.ts")
}

// Re-export para que el registry pueda importar la fn sin conocer el módulo.
export { api }
