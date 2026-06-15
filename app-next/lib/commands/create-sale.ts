/**
 * Comando: createSale — venta contado (type=0) o crédito (type=3).
 *
 * offlineEligible: true (ver lib/commands/registry.ts).
 *
 * Estado actual (Slice A3): construye el payload canónico idempotente y
 * SIMULA éxito sin POST real a la API. El POST real se cablea en Slice A6
 * cuando haya auth + datos reales (ver TODO abajo).
 *
 * Cuando se active la fase offline:
 *   - El interceptor del registry detecta `!navigator.onLine`.
 *   - Persiste el payload en IndexedDB (Dexie) con estado 'pending'.
 *   - Al reconectar, el sync worker llama a `executeSale()` por cada pending.
 *   - El transactionUID garantiza deduplicación server-side.
 *
 * Referencia visual legacy: `app/scripts/app.js` namespace `ncmTransactions`
 * funciones: `saveSale()`, `buildSalePayload()`, `postSale()`.
 * Referencia cobro: namespace `ncmPayments` funciones: `buildPaymentRows()`.
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A y context/14-app-rewrite-analysis.md §9.
 */

import type { CartLine } from "@/lib/cart/store"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"

// ── Shape del payload ─────────────────────────────────────────────────────────

/**
 * Un ítem de venta, compatible con el array `sale[]` que espera SaleInput.php.
 *
 * Campos mínimos para el path simple (35a):
 *   - itemId   → SaleInput::$sale[n]['itemId']
 *   - count    → SaleInput::$sale[n]['count']  (alias: quantity)
 *   - price    → SaleInput::$sale[n]['price']  (precio unitario)
 *   - total    → SaleInput::$sale[n]['total']  (precio * qty, calculado)
 *   - discount → SaleInput::$sale[n]['discount']
 *   - note     → SaleInput::$sale[n]['note']
 */
export interface SaleItem {
  itemId: string
  /** Nombre del item (no requerido por SaleInput.php pero útil para auditoría). */
  name: string
  count: number
  price: number
  /** subtotal = price * count (pre-computed, mirrors el legacy). */
  total: number
  discount: number
  note: string | null
}

/**
 * Un método de pago — compatible con el array `payment[]` de SaleInput.php.
 * El legacy manda: [{ name/method, total, identifier? }]
 */
export interface SalePaymentMethod {
  /** Código/label del método (ej. 'efectivo', 'tarjeta', 'transferencia', 'qr'). */
  name: string
  /** Monto aplicado con este método. */
  total: number
  /** Identificador opcional (nro de cheque, nro de voucher, etc). */
  identifier?: string
}

/**
 * Payload canónico de la venta — shape compatible con SaleInput.php::fromPayload().
 *
 * El servidor espera el payload envuelto en `{ uid, transaction: { ... } }`
 * (ver SaleInput.php línea 70: `$payload = $raw['transaction'] ?? $raw`).
 * La función buildApiPayload() arma esa envoltura.
 */
export interface CreateSalePayload {
  /**
   * UUID v4 generado client-side. El SaleService lo usa para deduplicar
   * (UNIQUE constraint en `transactionUID`). Idempotente ante retries.
   * TODO (Slice A6): migrar a UUID v7 cuando se importe la librería `uuid`.
   */
  uid: string
  /**
   * Tipo de transacción:
   *   0 = contado
   *   3 = crédito (requiere client con isCreditable=true)
   */
  type: 0 | 3
  /** Ítems vendidos. */
  sale: SaleItem[]
  /** Subtotal antes de impuestos y descuentos (= sum de item.total). */
  subtotal: number
  /** Impuesto total (calculado; 0 en path simple sin taxObj). */
  tax: number
  /** Descuento global (0 en path simple). */
  discount: number
  /** Métodos de pago aplicados. */
  payment: SalePaymentMethod[]
  /** Fecha ISO local (ej. '2026-06-15'). */
  date: string
  /** Unix timestamp en segundos. */
  timestamp: number
  /**
   * UUID del cliente. Maps to SaleInput::$clientId.
   * Requerido para type=3 (crédito), opcional para type=0 (contado).
   * Nombre del campo según lo que SaleInput.php lee: `client`.
   */
  client: string | null
  /** UUID del usuario vendedor. Maps to SaleInput::$userId (`user`). */
  user: string | null
  /** Nota de la venta. */
  note: string | null
  /** Flag venta interna (consumo propio). */
  interno: boolean
}

export interface CreateSaleResult {
  /** UUID de la transacción creada en BD. */
  transactionId: string
  /** UID idempotente que mandamos. */
  transactionUID: string
  /** Número de comprobante asignado (null si es número server-side). */
  invoiceNumber: string | null
  /** Total de la venta. */
  total: number
  /**
   * true si esta ejecución fue simulada (sin POST real a la API).
   * Quitado en Slice A6 cuando se conecte el POST real.
   */
  simulated: boolean
}

// ── Input para buildPayload ───────────────────────────────────────────────────

export interface BuildSaleInput {
  lines: CartLine[]
  payments: SalePaymentMethod[]
  credito: boolean
  interno: boolean
  customer: PosCustomer | null
  /** UUID del usuario autenticado (del JWT / sesión de caja). */
  userId: string | null
}

// ── Builders ──────────────────────────────────────────────────────────────────

/**
 * Construye el CreateSalePayload canónico desde el estado del carrito.
 * Separado de executeSale para facilitar el testing y la auditoría del payload.
 */
export function buildSalePayload(input: BuildSaleInput): CreateSalePayload {
  const { lines, payments, credito, interno, customer, userId } = input

  const saleItems: SaleItem[] = lines.map((line) => ({
    itemId: line.itemId,
    name: line.name,
    count: line.qty,
    price: line.unitPrice,
    total: line.qty * line.unitPrice,
    discount: 0,
    note: line.note ?? null,
  }))

  const subtotal = saleItems.reduce((s, i) => s + i.total, 0)

  const now = new Date()
  const dateStr = now.toISOString().slice(0, 10) // 'YYYY-MM-DD'
  const timestamp = Math.floor(now.getTime() / 1000)

  return {
    uid: crypto.randomUUID(),
    type: credito ? 3 : 0,
    sale: saleItems,
    subtotal,
    tax: 0,
    discount: 0,
    payment: payments,
    date: dateStr,
    timestamp,
    client: customer?.id ?? null,
    user: userId,
    note: null,
    interno,
  }
}

/**
 * Arma el wrapper `{ uid, transaction: {...} }` que SaleInput.php::fromPayload()
 * espera cuando el payload viene envuelto (SaleInput.php línea 70).
 */
export function buildApiPayload(payload: CreateSalePayload): Record<string, unknown> {
  return {
    uid: payload.uid,
    transaction: payload,
  }
}

// ── Executor ──────────────────────────────────────────────────────────────────

/**
 * Ejecuta la venta.
 *
 * ⚠️  SIMULADO en Slice A3: construye el payload pero NO hace el POST real.
 *     Devuelve { ok: true, simulated: true, ... }.
 *
 * TODO (Slice A6): descomentar y reemplazar el bloque simulado por:
 *   ```ts
 *   import { api } from "@/lib/api-client"
 *   const apiPayload = buildApiPayload(payload)
 *   const result = await api.post<CreateSaleResult>("/v1/sales", apiPayload)
 *   return result
 *   ```
 *   El endpoint BFF `/api/v1/sales` reenvía a `api/v1/sales.php` (SaleService).
 *   Razón del diferimiento: contra fixtures no hay `_jwt` (realm pos-app) ni items
 *   reales → el POST daría 401/422. Se cablea cuando haya auth + datos reales.
 */
export async function executeSale(
  input: BuildSaleInput,
): Promise<CreateSaleResult> {
  const payload = buildSalePayload(input)

  // ── Validaciones de negocio ──────────────────────────────────────────────
  if (payload.sale.length === 0) {
    throw new Error("El carrito está vacío")
  }
  if (payload.type === 3 && payload.client === null) {
    throw new Error("Venta a crédito requiere un cliente seleccionado")
  }
  if (payload.payment.length === 0) {
    throw new Error("Debe agregar al menos un método de pago")
  }

  // ── TODO (Slice A6): POST real al BFF ────────────────────────────────────
  // Descomentar y eliminar el bloque simulado cuando haya auth + datos reales:
  //
  //   import { api } from "@/lib/api-client"
  //   const apiPayload = buildApiPayload(payload)
  //   return api.post<CreateSaleResult>("/v1/sales", apiPayload)
  //
  // El BFF `/api/v1/sales` (Next route handler) reenvía al SaleService.php.
  // Cablear en Slice A6 junto con el handoff de auth (cookie _jwt realm pos-app).

  // ── Simulación (Slice A3) ────────────────────────────────────────────────
  // Simula latencia mínima de red para feedback realista.
  await new Promise<void>((resolve) => setTimeout(resolve, 300))

  console.log("[createSale] PAYLOAD (auditar contra SaleInput.php):", JSON.stringify(payload, null, 2))

  return {
    transactionId: crypto.randomUUID(),
    transactionUID: payload.uid,
    invoiceNumber: null,
    total: payload.subtotal,
    simulated: true,
  }
}
