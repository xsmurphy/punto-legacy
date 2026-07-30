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

// Mismo motivo que en `create-quote.ts`: `/v1/sales` solo acepta el Bearer del
// device (`apiAuthPosContext`), nunca la cookie del panel.
import { posApi as api } from "@/lib/api/pos-client"
import type { CartLine } from "@/lib/cart/store"
import { allocateLineDiscounts, TAX_RATE, type SaleDiscountInput } from "@/lib/cart/allocate-discounts"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"
import { tenantNow } from "@/lib/format-date"

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
  /** BRUTO de la línea = price * count, sin descuentos (semántica de itemSoldTotal). */
  total: number
  /** Porcentaje efectivo de descuento de la línea (propio + parte del de venta). */
  discount: number
  /** Plata del descuento de la línea → itemSold.itemSoldDiscount. */
  totalDiscount: number
  note: string | null
  /**
   * Metadata de EMISIÓN de gift card (F2 giftcard-issue-flow) — presente solo
   * cuando la línea viene de `GiftcardIssueDialog` (item kind="giftcard").
   * El backend (Money::sanitizeSaleArray → SaleService::issueGiftCard) la usa
   * para crear la fila en la tabla `giftcard`. Espejo de
   * `CartLine.giftcard` (lib/cart/store.ts).
   */
  giftcard?: {
    code: string
    beneficiaryContactId?: string | null
    expiresAt?: string | null
    note?: string | null
  }
}

/**
 * Un método de pago — compatible con el array `payment[]` de SaleInput.php.
 * El legacy manda: [{ name/method, total, identifier? }]
 */
export interface SalePaymentMethod {
  /**
   * Nombre LEGIBLE del método (ej. 'Efectivo', 'T. Débito', o el nombre del
   * medio custom). Esto es lo que persiste `transactionPaymentType.name` y lo
   * que lee Control de Caja — nunca el id/slug/UUID (bug: se mandaba
   * `method.id`, guardando el UUID de taxonomía como "nombre" del medio).
   */
  name: string
  /**
   * Id/slug del método (taxonomyId para custom, slug fijo para nativos:
   * 'efectivo'/'tcredito'/'tdebito'/'giftcard'). Se persiste junto al name
   * para que el agrupado por método sea estable aunque el nombre legible
   * cambie, y para que la lectura pueda re-resolver el nombre contra la
   * taxonomía si hiciera falta.
   */
  type?: string
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
  /** Fecha+hora local del TENANT, naive (ej. '2026-06-15 14:32:07'). No UTC. */
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
  /**
   * Venta emitida sin IVA. Los importes ya vienen netos; el backend lo persiste
   * en `transaction.ivaRemoved` (mig 101) para que los reportes que derivan el
   * IVA del `taxId` del ítem no lo devenguen en esta venta.
   */
  ivaRemoved: boolean
  /** Etiquetas de texto libre asociadas a la venta. */
  tags: string[]
  /**
   * ID de la cotización que originó esta venta (si aplica).
   * Permite al backend vincular la transacción hija con la cotización padre.
   */
  parentTransactionId?: string | null
  /**
   * Fecha de vencimiento de la venta a crédito, formato "YYYY-MM-DD".
   * Solo aplica a type=3 (crédito); opcional — SaleInput.php la tolera null.
   * Maps to SaleInput::$dueDate → columna `transactionDueDate`.
   */
  dueDate?: string | null
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
  /**
   * Venta emitida sin IVA (toggle del POS). Los importes del payload salen
   * netos y el flag viaja al backend (columna `transaction.ivaRemoved`, mig
   * 101) — antes moría en el browser: se cobraba sin IVA y se registraba con.
   */
  ivaRemoved?: boolean
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
  saleDiscount?: SaleDiscountInput | null
  /**
   * TZ IANA del tenant (PosConfig.timezone). La fecha de la venta se formatea
   * en esta TZ para que un device en otra zona no la desfase. Si es falsy se
   * cae a la hora local del device (ver tenantNow en lib/format-date.ts).
   */
  timezone?: string | null
  /**
   * Fecha de vencimiento (solo venta a crédito), "YYYY-MM-DD" u opcional.
   * Ver CreateSalePayload.dueDate.
   */
  dueDate?: string | null
  /**
   * uid idempotente provisto por el caller. Un REINTENTO del mismo cobro
   * (timeout que en realidad llegó al server, doble submit) debe reusar el
   * MISMO uid para que la dedupe server-side (columna UNIQUE) lo atrape —
   * generar uno nuevo por intento crea ventas duplicadas. Si es falsy se
   * genera uno (callers one-shot).
   */
  uid?: string | null
}

// ── Builders ──────────────────────────────────────────────────────────────────

/**
 * Construye el CreateSalePayload canónico desde el estado del carrito.
 * Separado de executeSale para facilitar el testing y la auditoría del payload.
 */
export function buildSalePayload(input: BuildSaleInput): CreateSalePayload {
  const { lines, payments, credito, interno, customer, userId, tags, quoteParentId, saleDiscount, timezone, dueDate, uid, ivaRemoved = false } = input

  // Los descuentos se reparten por ÍTEM (ver lib/cart/allocate-discounts.ts):
  // el descuento de venta se prorratea entre las líneas y se suma al descuento
  // propio de cada una. `total` sigue siendo el BRUTO — es la semántica de
  // `itemSold.itemSoldTotal`, que los reportes suman aparte de
  // `itemSoldDiscount` — y el neto sale de restarlos.
  const allocations = allocateLineDiscounts(lines, saleDiscount, ivaRemoved)

  const saleItems: SaleItem[] = lines.map((line, i) => ({
    itemId: line.itemId,
    name: line.name,
    count: line.qty,
    // Precio unitario efectivamente cobrado: con IVA quitado, el neto. El
    // importe de autoridad es `total` (el bruto de la línea, ya ajustado).
    price: ivaRemoved ? Math.round(line.unitPrice / (1 + TAX_RATE)) : line.unitPrice,
    total: allocations[i].gross,
    // Porcentaje EFECTIVO de la línea: su descuento propio más la parte del
    // descuento de venta que le tocó.
    discount: allocations[i].effectivePercent,
    // Plata del descuento de esa línea → itemSold.itemSoldDiscount. Antes no se
    // mandaba, así que esa columna quedaba siempre en 0 y los reportes de
    // descuento por producto/categoría/marca no mostraban nada.
    totalDiscount: allocations[i].totalDiscount,
    note: line.note ?? null,
    ...(line.giftcard
      ? {
          giftcard: {
            code: line.giftcard.code,
            beneficiaryContactId: line.giftcard.beneficiaryContactId ?? null,
            expiresAt: line.giftcard.expiresAt ?? null,
            note: line.giftcard.note ?? null,
          },
        }
      : {}),
  }))

  const subtotal = saleItems.reduce((s, i) => s + i.total, 0)

  // El descuento de la transacción es la SUMA de los descuentos de sus ítems —
  // no una cifra propia. Así `subtotal - discount` da exactamente lo que se
  // cobró.
  //
  // Antes acá solo entraba el descuento de venta: el descuento POR LÍNEA se
  // mostraba en el carrito y se cobraba, pero no llegaba a ningún total de la
  // transacción, que quedaba registrada por el bruto menos el descuento de
  // venta. Es decir, más plata de la que efectivamente entró.
  const transactionDiscount = allocations.reduce((s, a) => s + a.totalDiscount, 0)

  const now = new Date()
  // Storage = hora local del TENANT, naive. El server ya guarda en
  // settingTimeZone (data.php); formatear "ahora" en la TZ del tenant evita
  // que un device en otra zona (tablet en UTC, device que viajó) desfase la
  // fecha de la venta. tenantNow cae a la hora local del device si no hay TZ.
  const dateStr = tenantNow(timezone ?? undefined, now)
  // timestamp = epoch real (instante absoluto), independiente de la TZ de display.
  const timestamp = Math.floor(now.getTime() / 1000)

  return {
    uid: uid || crypto.randomUUID(),
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
    ivaRemoved,
    tags,
    parentTransactionId: quoteParentId ?? null,
    // Solo se manda en venta a crédito — en contado el backend la ignoraría
    // igual (SaleInput no valida por type) pero no tiene sentido persistirla.
    dueDate: credito ? (dueDate || null) : null,
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
