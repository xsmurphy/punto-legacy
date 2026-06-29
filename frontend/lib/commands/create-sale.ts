/**
 * Comando: createSale — venta contado (type=0) o crédito (type=3).
 *
 * offlineEligible: true (ver lib/commands/registry.ts).
 *
 * POST a `/api/v1/sales` (BFF → SaleService.php). El backend espera el
 * payload como form-encoded `data[]=<JSON.stringify(payload)>` (patrón
 * legacy de `action.php?action=processData`), por eso se usa
 * `api.postLegacy()` en vez de `api.post()`.
 *
 * Idempotencia: el `uid` v4 client-side se persiste en una columna UNIQUE
 * server-side. Si dos requests llegan con el mismo uid (retry, doble-click,
 * cola offline), el segundo devuelve `duplicated:true` con el transactionId
 * existente — el front lo trata como éxito.
 *
 * Cuando se active la fase offline:
 *   - El interceptor del registry detecta `!navigator.onLine`.
 *   - Persiste el payload en IndexedDB (Dexie) con estado 'pending'.
 *   - Al reconectar, el sync worker llama a `executeSale()` por cada pending.
 *   - El uid garantiza deduplicación server-side.
 *
 * Referencia visual legacy: `app/scripts/app.js` namespace `ncmTransactions`
 * funciones: `saveSale()`, `buildSalePayload()`, `postSale()`.
 * Referencia cobro: namespace `ncmPayments` funciones: `buildPaymentRows()`.
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A y context/14-app-rewrite-analysis.md §9.
 */

import { api } from "@/lib/api-client"
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
  /** Fecha+hora local (ej. '2026-06-15 14:32:07'). Hora del browser, no UTC. */
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
  /** Etiquetas de texto libre asociadas a la venta. */
  tags: string[]
  /**
   * ID de la cotización que originó esta venta (si aplica).
   * Permite al backend vincular la transacción hija con la cotización padre.
   */
  parentTransactionId?: string | null
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
   * true si el backend detectó que ya existía una venta con este uid y
   * devolvió el transactionId existente (idempotencia). El front lo trata
   * como éxito — la UI ya muestra "guardado" sin distinguir.
   */
  duplicated: boolean
}

// ── Shape de respuesta del backend (/v1/sales POST) ──────────────────────────
// SaleResult::toApiPayload() en api/lib/Sales/SaleResult.php.
interface RawSaleResponse {
  success: boolean
  transactionId: string
  uid: string
  duplicated: boolean
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
  /** Etiquetas de texto libre asociadas a la venta. */
  tags: string[]
  /**
   * ID de cotización padre (cuando la venta es una conversión de cotización).
   * Se envía como parentTransactionId al backend para vincular ambas transacciones.
   */
  quoteParentId?: string | null
  /**
   * Descuento de venta (transactionDiscount). Se convierte a plata antes de
   * mandarse al backend (campo `discount` top-level del payload, tipo float).
   * SaleInput.php::fromPayload lee: `(float) ($payload['discount'] ?? 0)`.
   */
  saleDiscount?: { value: number; mode: "percent" | "money" } | null
}

// ── Builders ──────────────────────────────────────────────────────────────────

/**
 * Construye el CreateSalePayload canónico desde el estado del carrito.
 * Separado de executeSale para facilitar el testing y la auditoría del payload.
 */
export function buildSalePayload(input: BuildSaleInput): CreateSalePayload {
  const { lines, payments, credito, interno, customer, userId, tags, quoteParentId, saleDiscount } = input

  const saleItems: SaleItem[] = lines.map((line) => ({
    itemId: line.itemId,
    name: line.name,
    count: line.qty,
    price: line.unitPrice,
    total: line.qty * line.unitPrice,
    // discount por línea: porcentaje aplicado a esa línea (independiente del saleDiscount)
    discount: line.discount ?? 0,
    note: line.note ?? null,
  }))

  const subtotal = saleItems.reduce((s, i) => s + i.total, 0)

  // Resolver el descuento de venta a plata. Mismo cálculo que selectSaleDiscountAmount
  // del store (consistencia con lo que muestra la UI).
  // Base: subtotal de líneas con descuentos de línea ya aplicados.
  const linesSubtotal = lines.reduce((s, line) => {
    const discountFactor = 1 - (line.discount ?? 0) / 100
    return s + line.qty * line.unitPrice * discountFactor
  }, 0)
  const transactionDiscount = (() => {
    if (!saleDiscount || linesSubtotal === 0) return 0
    if (saleDiscount.mode === "money") return Math.min(saleDiscount.value, linesSubtotal)
    const pct = Math.min(100, Math.max(0, saleDiscount.value))
    return Math.round(linesSubtotal * pct / 100)
  })()

  const now = new Date()
  // Construir fecha+hora local con offset de timezone explícito para que PG
  // interprete el instante correctamente (TIMESTAMPTZ). Usar solo 'YYYY-MM-DD'
  // o una cadena sin offset hace que PG asuma el TZ del servidor (típicamente
  // UTC), lo que insertar HH:00:00 o desfasa el instante respecto al epoch.
  const pad = (n: number) => String(n).padStart(2, "0")
  const tzMinutes = -now.getTimezoneOffset() // getTimezoneOffset devuelve negativo para UTC+
  const tzSign = tzMinutes >= 0 ? "+" : "-"
  const tzHH = pad(Math.floor(Math.abs(tzMinutes) / 60))
  const tzMM = pad(Math.abs(tzMinutes) % 60)
  const dateStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}${tzSign}${tzHH}:${tzMM}`
  const timestamp = Math.floor(now.getTime() / 1000)

  return {
    uid: crypto.randomUUID(),
    type: credito ? 3 : 0,
    sale: saleItems,
    subtotal,
    tax: 0,
    discount: transactionDiscount,
    payment: payments,
    date: dateStr,
    timestamp,
    client: customer?.id ?? null,
    user: userId,
    note: null,
    interno,
    tags,
    parentTransactionId: quoteParentId ?? null,
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
 * Ejecuta la venta. POST real al BFF `/api/v1/sales` (Slice A6).
 *
 * - Construye el payload canónico con `buildSalePayload()` y lo envuelve con
 *   `buildApiPayload()` ({ uid, transaction: {...} }) como espera SaleInput.php.
 * - Manda el wrapper via `api.postLegacy()` (form-encoded `data[]=<JSON>`).
 * - Mapea la respuesta `{ success, transactionId, uid, duplicated }` a
 *   `CreateSaleResult`.
 * - Si el backend dice `duplicated:true` (mismo uid ya guardado), devuelve
 *   ese transactionId existente como éxito — comportamiento idempotente.
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

  // ── POST real al BFF ──────────────────────────────────────────────────────
  const apiPayload = buildApiPayload(payload)
  const response = await api.postLegacy<RawSaleResponse>(
    "/v1/sales",
    apiPayload,
  )

  return {
    transactionId: response.transactionId,
    transactionUID: response.uid,
    invoiceNumber: null,
    total: payload.subtotal,
    duplicated: response.duplicated === true,
  }
}
