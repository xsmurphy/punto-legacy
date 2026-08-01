import type { CreateSalePayload, CreateSaleResult } from "@/lib/commands/create-sale"
import type { PosConfig } from "@/lib/types/pos-bootstrap"
import { useCatalogStore } from "@/lib/catalog/store"

export interface TicketData {
  // empresa / tenant
  companyName: string
  companyAddress?: string
  companyPhone?: string
  companyLogoUrl?: string
  /**
   * Razón social/RUC/email/sitio del tenant — poblados desde `PosConfig`
   * (`companyBillingName`/`companyTin`/`companyEmail`/`companyWebsite`,
   * `/api/pos/bootstrap` ← `/v1/bootstrap`). Null si el tenant no los cargó
   * en Ajustes. Los bloques `company_billing_name`/`company_tin`/
   * `company_email`/`company_website` (ver `blocks.ts`) los resuelven.
   */
  companyBillingName?: string | null
  companyTin?: string | null
  companyEmail?: string | null
  companyWebsite?: string | null
  // sucursal
  outletName?: string
  /**
   * Datos fiscales de la sucursal activa — poblados desde `PosOutlet`
   * (`state.outlet.address`/`billingName`/`tin`/`phone`, ampliado igual que
   * company* — ver `/api/pos/bootstrap` ← `/v1/bootstrap`). Null si el
   * outlet no los cargó.
   */
  outletAddress?: string
  outletBillingName?: string | null
  outletTin?: string | null
  outletPhone?: string | null
  // cliente
  customerName?: string
  /** Ver nota de PosCustomer abajo — no modelado hoy, siempre null. */
  customerAddress?: string
  customerPhone?: string
  customerTin?: string
  /**
   * customerAddress2/Location/City/Country/Phone2/Note/Loyalty/Birthday/
   * Email: `PosCustomer` (`lib/types/pos-bootstrap.ts`) solo trae
   * id/name/phone/tin/storeCredit/isCreditable. Requiere ampliar el modelo
   * de cliente (backend `/v1/contacts` + bootstrap) para poblarlos.
   */
  customerAddress2?: string | null
  customerLocation?: string | null
  customerCity?: string | null
  customerCountry?: string | null
  customerPhone2?: string | null
  customerNote?: string | null
  customerLoyalty?: string | null
  customerBirthday?: string | null
  customerEmail?: string | null
  // documento
  docType: string
  documentNumber?: string
  documentPrefix?: string
  documentSufix?: string
  ticketNo?: string
  transactionId: string
  saleId?: string
  /** Fecha de vencimiento (ventas a crédito) — `payload.dueDate`. */
  dueDate?: string | null
  /** Etiquetas de texto libre de la venta — `payload.tags`. */
  tags?: string[]
  /**
   * Número de documento asociado (ej. cotización origen de esta venta).
   * `CreateSalePayload.parentTransactionId` es un UUID interno, no un
   * número de documento imprimible — resolver ese UUID contra el
   * `documentNumber` de la transacción padre no está implementado. Queda
   * null hasta que exista ese lookup.
   */
  associatedDocument?: string | null
  /**
   * F6 — link del portal de consulta del comprador (bloque `fe_py`): QR en
   * ESC/POS, texto en el fallback HTML. Solo lo trae la impresión de una venta
   * recién creada, que es la que recibe el link en la respuesta del backend
   * (`CreateSaleResult.einvoicePortalUrl`). En reimpresiones desde el listado
   * de transacciones queda null — ese camino reconstruye el ticket desde la
   * transacción persistida, que todavía no expone el link.
   */
  einvoiceUrl?: string | null
  // usuario
  userName?: string
  /** Caja activa (`activeRegisterId` resuelto contra `registers` del catálogo). */
  registerName?: string | null
  /**
   * Impresora física que está imprimiendo este ticket — NO se completa acá
   * (buildTicketData no sabe con qué binding se va a imprimir; puede haber
   * más de uno). La inyecta `printSale` (lib/hardware/printers/index.ts)
   * por binding, clonando `TicketData` antes de renderizar cada copia.
   */
  printerName?: string | null
  /**
   * Timbrado (No./inicio/vencimiento): `register.data.registerInvoiceAuth*`
   * (mig 26), poblado desde `PosRegister` (`state.registers`, resuelto
   * contra `activeRegisterId`). Esto cubre el flujo NO-FE (tickets/facturas
   * preimpresas); la fuente correcta a futuro para facturación electrónica
   * puede terminar siendo otro módulo (api/lib/EInvoice/*, en desarrollo en
   * paralelo — no tocado acá).
   */
  authNumber?: string | null
  authStartDate?: string | null
  authExpiration?: string | null
  // fecha
  date: string
  // items
  items: TicketItem[]
  // totales
  subtotal: number
  discount: number
  taxTotal: number
  total: number
  // pagos
  payments: TicketPayment[]
  // nota
  note?: string
  /**
   * Destino de la orden ("Espacio 4", "Mostrador", "Retiro", "Envío") — solo
   * `docType==="order"` (comandas), viene de `orderDestinationText()`
   * (lib/orders/order-display.ts). Forzado en la cabecera del ticket por
   * `render-template.ts`/`html-renderer.ts` SIEMPRE que esté presente, sin
   * depender de qué bloques tenga configurada la plantilla del binding: es
   * el primer dato que necesita quien arma el pedido, no un campo más que el
   * operador pueda olvidarse de agregar al template. También reusado como
   * valor best-effort del bloque `table_number` (ver blocks.ts).
   */
  orderDestination?: string
}

export interface TicketItem {
  name: string
  qty: number
  unitPrice: number
  discount: number
  total: number
  categoryId: string | null
}

export interface TicketPayment {
  method: string
  amount: number
}

interface BuildTicketDataInput {
  payload: CreateSalePayload
  result: CreateSaleResult
  config: PosConfig | null
}

export function buildTicketData({ payload, result, config }: BuildTicketDataInput): TicketData {
  const state = useCatalogStore.getState()
  const outletName = state.outlet?.name
  const customer = state.customers.find((c) => c.id === payload.client) ?? null
  const activeRegister = state.registers.find((r) => r.id === state.activeRegisterId) ?? null
  const registerName = activeRegister?.name ?? null

  const items: TicketItem[] = payload.sale.map((s) => {
    const catalogItem = state.items.find((i) => i.id === s.itemId)
    return {
      name: s.name,
      qty: s.count,
      unitPrice: s.price,
      discount: s.discount,
      total: s.total,
      categoryId: catalogItem?.categoryId ?? null,
    }
  })

  const payments: TicketPayment[] = payload.payment.map((p) => ({
    method: p.name,
    amount: p.total,
  }))

  return {
    companyName: config?.companyName ?? "",
    companyBillingName: config?.companyBillingName ?? null,
    companyTin: config?.companyTin ?? null,
    companyEmail: config?.companyEmail ?? null,
    companyWebsite: config?.companyWebsite ?? null,
    outletName,
    outletAddress: state.outlet?.address ?? undefined,
    outletBillingName: state.outlet?.billingName ?? null,
    outletTin: state.outlet?.tin ?? null,
    outletPhone: state.outlet?.phone ?? null,
    customerName: customer?.name,
    customerPhone: customer?.phone ?? undefined,
    customerTin: customer?.tin ?? undefined,
    docType: payload.type === 3 ? "credit" : "receipt",
    transactionId: result.transactionId,
    einvoiceUrl: result.einvoicePortalUrl ?? null,
    dueDate: payload.dueDate ?? null,
    tags: payload.tags,
    registerName,
    authNumber: activeRegister?.authNumber ?? null,
    authStartDate: activeRegister?.authStartDate ?? null,
    authExpiration: activeRegister?.authExpiration ?? null,
    date: payload.date,
    items,
    subtotal: payload.subtotal,
    discount: payload.discount,
    taxTotal: payload.tax,
    total: result.total,
    payments,
    note: payload.note ?? undefined,
  }
}

// ── Reconstrucción de TicketData desde una transacción ya guardada ─────────
// Wrapper compartido: reimprimir/cotizar armaba el mismo shape de TicketData
// en 3 lugares (quote-print-view, transactions-list onReprint, pos-
// transactions-dialog handleReprint), cada uno con su propio mapeo de items
// y categoryId=null hardcodeado (rompía el filtrado por categoría en
// printSale/getBindingsForSale). Este wrapper es la única fuente — nuevos
// call-sites deben usarlo, no copiar el mapeo inline.

/** Shape mínimo común entre TransactionDetail (use-transactions) y otros
 *  hooks de detalle de transacción, para no acoplar este módulo a un hook. */
export interface TicketableTransaction {
  transactionId: string
  customerName?: string
  documentNo?: string
  invoicePrefix?: string
  date?: string
  discount?: string | number
  total?: string | number
  note?: string
  transactionDatas?: TicketableTransactionItem[] | null
  pMethods?: TicketablePaymentMethod[] | null
}

export interface TicketableTransactionItem {
  itemId?: string
  name: string
  count: number
  price: number
  total: number
  discount: number
  status?: number
}

export interface TicketablePaymentMethod {
  name?: string
  type?: string
  amount: number
}

/** Mapea items de una transacción a TicketItem, resolviendo categoryId real
 *  contra el catálogo cargado (necesario para el filtrado por categoría de
 *  `printSale`/`getBindingsForSale` — categoryId=null hardcodeado lo rompía). */
export function buildTicketItemsFromTransaction(
  items: TicketableTransactionItem[] | null | undefined,
): TicketItem[] {
  const catalogItems = useCatalogStore.getState().items
  return (items ?? [])
    .filter((i) => i.status !== 0)
    .map((i) => {
      const catalogItem = i.itemId ? catalogItems.find((c) => c.id === i.itemId) : undefined
      return {
        name: i.name,
        qty: i.count,
        unitPrice: i.price,
        discount: i.discount,
        total: i.total,
        categoryId: catalogItem?.categoryId ?? null,
      }
    })
}

export function buildTicketDataFromTransaction(
  tx: TicketableTransaction,
  config: PosConfig | null,
  docType: string,
): TicketData {
  const items = buildTicketItemsFromTransaction(tx.transactionDatas)
  const payments: TicketPayment[] = (tx.pMethods ?? []).map((p) => ({
    method: p.name || p.type || "—",
    amount: p.amount,
  }))
  const discount = Number(tx.discount ?? 0)
  const total = Number(tx.total ?? 0)
  const itemsTotal = items.reduce((s, i) => s + i.total, 0)
  // Reimpresión: mismo device/sesión, así que el catálogo hidratado en
  // useCatalogStore refleja la sucursal/caja actuales — la transacción
  // persistida no trae estos campos, no hay otra fuente hoy.
  const state = useCatalogStore.getState()
  const activeRegister = state.registers.find((r) => r.id === state.activeRegisterId) ?? null

  return {
    companyName: config?.companyName ?? "",
    companyBillingName: config?.companyBillingName ?? null,
    companyTin: config?.companyTin ?? null,
    companyEmail: config?.companyEmail ?? null,
    companyWebsite: config?.companyWebsite ?? null,
    outletName: state.outlet?.name,
    outletAddress: state.outlet?.address ?? undefined,
    outletBillingName: state.outlet?.billingName ?? null,
    outletTin: state.outlet?.tin ?? null,
    outletPhone: state.outlet?.phone ?? null,
    registerName: activeRegister?.name ?? null,
    authNumber: activeRegister?.authNumber ?? null,
    authStartDate: activeRegister?.authStartDate ?? null,
    authExpiration: activeRegister?.authExpiration ?? null,
    customerName: tx.customerName?.trim() || undefined,
    docType,
    documentNumber: tx.documentNo || undefined,
    documentPrefix: tx.invoicePrefix || undefined,
    transactionId: tx.transactionId,
    date: tx.date ?? new Date().toISOString(),
    items,
    subtotal: itemsTotal > 0 ? itemsTotal : total + discount,
    discount,
    taxTotal: 0,
    total,
    payments,
    note: tx.note || undefined,
  }
}

// ── Reconstrucción de TicketData desde TxDetailFull (panel) ────────────────
// Segundo adapter del mismo wrapper compartido: el panel usa `useTransactionDetail`
// (/v1/reports/transactions?id=), que devuelve un shape distinto al POS
// (`TxDetailFull`: transaction.{transactionPaymentType,...} + items[] con
// itemSoldId/itemSoldUnits/itemSoldTotal, sin `transactionDatas`/`pMethods`).
// El panel tampoco tiene el catálogo POS hidratado en `useCatalogStore`
// (categoryId quedaría siempre null igual — no filtra bindings de hardware,
// que el panel no usa: printTicketInBrowser es la única vía ahí), así que
// este adapter no depende de `useCatalogStore.items`.

/** Shape mínimo de TxDetailFull (hooks/use-reports.ts) que este adapter necesita. */
export interface TicketableTxDetail {
  transaction: {
    transactionId: string
    transactionDate: string
    transactionNote: string | null
    transactionTotal: number
    transactionDiscount: number
    transactionPaymentType: Array<{ type: string; name: string; total: number }>
    invoiceNo: string | null
    customerName: string | null
  }
  items: Array<{
    itemName: string
    itemSoldUnits: number
    itemSoldTotal: number
  }>
}

export function buildTicketDataFromTxDetail(
  detail: TicketableTxDetail,
  companyName: string,
  docType: string,
): TicketData {
  const tx = detail.transaction
  const items: TicketItem[] = detail.items.map((i) => ({
    name: i.itemName,
    qty: i.itemSoldUnits,
    unitPrice: i.itemSoldUnits > 0 ? i.itemSoldTotal / i.itemSoldUnits : i.itemSoldTotal,
    discount: 0,
    total: i.itemSoldTotal,
    // Sin catálogo POS hidratado en el panel — printTicketInBrowser no filtra
    // por categoría (solo lo hace el matching de bindings de hardware, que
    // el panel no usa), así que null acá es inocuo.
    categoryId: null,
  }))
  const payments: TicketPayment[] = tx.transactionPaymentType.map((p) => ({
    method: p.name || p.type || "—",
    amount: p.total,
  }))
  const itemsTotal = items.reduce((s, i) => s + i.total, 0)

  return {
    companyName,
    customerName: tx.customerName?.trim() || undefined,
    docType,
    documentNumber: tx.invoiceNo || undefined,
    transactionId: tx.transactionId,
    date: tx.transactionDate,
    items,
    subtotal: itemsTotal > 0 ? itemsTotal : tx.transactionTotal + tx.transactionDiscount,
    discount: tx.transactionDiscount,
    taxTotal: 0,
    total: tx.transactionTotal,
    payments,
    note: tx.transactionNote || undefined,
  }
}
