/**
 * Catálogo único de bloques de impresión — fuente compartida entre
 * `render-template.ts` (ESC/POS) y `html-renderer.ts` (fallback navegador).
 *
 * Antes cada renderer tenía su propio `switch (block.type)` y su propio
 * `ITEM_BLOCK_TYPES`, sin catálogo compartido. Un tipo que faltaba en el
 * switch caía en `default:` y se descartaba EN SILENCIO — así llegó a
 * producción una plantilla con ~12 de los 27 tipos de bloque reales sin
 * implementar (ver context/28 y el diagnóstico contra `document_template`
 * en prod). Este módulo es la única fuente de:
 *
 *   1. Qué tipos de bloque existen (`BlockType`, en lib/types/print-template.ts).
 *   2. Cómo se resuelve el VALOR de cada uno contra `TicketData` (los
 *      renderers ya NO duplican esa lógica — solo deciden CÓMO pintar:
 *      encoder ESC/POS vs. HTML).
 *   3. Qué tipos son "de ítem" (se repiten una vez por producto vendido,
 *      agrupando bloques consecutivos del array) vs. "de tabla de ítems"
 *      (item_receipt* — un único bloque que arma el listado completo) vs.
 *      "estructurales" (custom/hor_line/ver_line/company_logo/barcode — no
 *      son un simple lookup de texto, cada renderer los pinta distinto).
 *
 * Un tipo que NO aparece en ninguno de los catálogos de abajo es
 * desconocido de verdad — `isKnownBlockType` lo distingue de un tipo
 * conocido cuyo dato simplemente no está disponible para esta venta/tenant
 * (esos resuelven `null` y se imprimen en blanco, sin error: es un hueco de
 * datos, no un bug de bloque no implementado).
 */

import type { BlockType, PrintBlock } from "@/lib/types/print-template"
import type { TicketData, TicketItem } from "./build-ticket-data"

export function formatMoney(n: number): string {
  return new Intl.NumberFormat("es-PY", { style: "currency", currency: "PYG" }).format(n)
}

/** Interpolación `{{campo}}` de bloques `custom` contra TicketData — antes
 *  solo la soportaba html-renderer; ESC/POS imprimía `block.text` crudo. */
export function interpolate(text: string, data: TicketData): string {
  return text.replace(/\{\{(\w+)\}\}/g, (_, key: string) => {
    const val = (data as unknown as Record<string, unknown>)[key]
    if (val === undefined || val === null) return ""
    return String(val)
  })
}

// ── Tipos "de ítem" — se repiten una vez por producto, agrupando bloques
// consecutivos del array (lógica de agrupado en cada renderer, sin duplicar
// AQUÍ cuáles tipos participan). ──────────────────────────────────────────

export const ITEM_LINE_TYPES: ReadonlySet<BlockType> = new Set<BlockType>([
  "item",
  "item_units",
  "item_id",
  "item_note",
  "item_uid",
  "item_tags",
  "item_tax",
  "item_taxAmount",
  "item_taxAmount_single",
  "item_discount",
  "item_price",
  "item_uni_price",
  "item_price_notax",
  "item_total",
  "item_subtotal",
  "tax_single",
])

/** item_receipt* — un único bloque que arma el listado completo de ítems
 *  (no se agrupa/repite como los ITEM_LINE_TYPES). Faltaba en ESC/POS. */
export const ITEM_TABLE_TYPES: ReadonlySet<BlockType> = new Set<BlockType>([
  "item_receipt",
  "item_receipt_2",
  "item_receipt_3",
  "item_receipt_4",
])

/** Tipos sin sustitución simple de texto — cada renderer los pinta con su
 *  propia lógica (imagen, regla, línea, barcode). Se listan acá solo para
 *  que `isKnownBlockType` no los trate como desconocidos. */
export const STRUCTURAL_TYPES: ReadonlySet<BlockType> = new Set<BlockType>([
  "hor_line",
  "ver_line",
  "company_logo",
  // Itera `data.payments` y pinta una línea por método — no es un lookup de
  // texto único, cada renderer ya lo maneja en su propio caso especial.
  "payment_methods",
])

export interface ItemTableColumns {
  qty: boolean
  unitPrice: boolean
  total: boolean
}

/** Columnas por variante de `item_receipt*` (label del legacy en
 *  print-template-palette.ts): _receipt = "Listado de Venta" (completo),
 *  _4 = "sin __TAX__" (TicketItem no lleva desglose de impuesto por ítem
 *  hoy — ver flag en el reporte — así que por ahora es igual a la
 *  completa), _2 = "sin Precios" (solo cantidad), _3 = "Listado Simple"
 *  (solo nombre). */
export function itemTableColumns(type: BlockType): ItemTableColumns {
  switch (type) {
    case "item_receipt_2":
      return { qty: true, unitPrice: false, total: false }
    case "item_receipt_3":
      return { qty: false, unitPrice: false, total: false }
    case "item_receipt":
    case "item_receipt_4":
    default:
      return { qty: true, unitPrice: true, total: true }
  }
}

// ── Resolución de valores contra TicketData (bloques "de línea única") ────

export type BlockValueResolver = (data: TicketData, block: PrintBlock) => string | null

const DOC_TYPE_LABELS: Record<string, string> = {
  receipt: "Recibo",
  invoice: "Factura",
  factura: "Factura",
  credit: "Factura a Crédito",
  order: "Comanda",
  quote: "Cotización",
  workorder: "Comanda",
  delivery: "Remito",
  withdraw: "Retiro de caja",
  closeReg: "Cierre de caja",
  return: "Nota de crédito",
}

function documentTypeLabel(docType: string): string {
  return DOC_TYPE_LABELS[docType] ?? (docType.charAt(0).toUpperCase() + docType.slice(1))
}

/** "Contado" para todo lo que no sea venta a crédito explícita. */
function saleTypeLabel(docType: string): string {
  return docType === "credit" ? "Crédito" : "Contado"
}

export const BLOCK_VALUE_RESOLVERS: Partial<Record<BlockType, BlockValueResolver>> = {
  custom: (data, block) => interpolate(block.text ?? "", data),

  // Empresa
  company_name: (data) => data.companyName || null,
  // ⚠ companyBillingName/companyTin/companyEmail/companyWebsite: NO existen
  // en el modelo hoy (PosConfig solo trae companyName/companyId/companyLogo).
  // Ver flag en el reporte — requieren ampliar /v1/bootstrap + PosConfig.
  company_billing_name: (data) => data.companyBillingName ?? null,
  company_tin: (data) => data.companyTin ?? null,
  company_address: (data) => data.companyAddress ?? null,
  company_email: (data) => data.companyEmail ?? null,
  company_phone: (data) => data.companyPhone ?? null,
  company_website: (data) => data.companyWebsite ?? null,

  // Sucursal
  outlet_name: (data) => data.outletName ?? null,
  // ⚠ outletBillingName/outletTin/outletPhone/outletAddress: OutletListItem
  // (lib/types/outlet.ts) YA tiene billingName/ruc/phone/address, pero
  // PosOutlet (bootstrap del POS) solo trae id/name/lat/lng — no llegan acá.
  // Ver flag en el reporte.
  outlet_billing_name: (data) => data.outletBillingName ?? null,
  outlet_tin: (data) => data.outletTin ?? null,
  outlet_address: (data) => data.outletAddress ?? null,
  outlet_phone: (data) => data.outletPhone ?? null,

  // Caja / usuario
  register_name: (data) => data.registerName ?? null,
  printer_name: (data) => data.printerName ?? null,
  user_name: (data) => data.userName ?? null,

  // ⚠ Timbrado: registerInvoiceAuth/AuthExpiration existen en BD (mig 26,
  // usadas en TransactionsService para reportes) pero no viajan a
  // /api/pos/bootstrap ni a TicketData hoy. En paralelo hay otra sesión
  // trabajando el módulo de facturación electrónica (api/lib/EInvoice/*)
  // que puede terminar siendo la fuente correcta de timbrado vigente — no
  // se toca ese código acá. Ver flag en el reporte.
  auth_number: (data) => data.authNumber ?? null,
  auth_start_date: (data) => data.authStartDate ?? null,
  auth_expiration: (data) => data.authExpiration ?? null,

  // Cliente
  customer_name: (data) => data.customerName ?? null,
  customer_full_name: (data) => data.customerName ?? null,
  customer_tin: (data) => data.customerTin ?? null,
  customer_ci: (data) => data.customerTin ?? null,
  customer_address: (data) => data.customerAddress ?? null,
  customer_phone: (data) => data.customerPhone ?? null,
  // ⚠ PosCustomer (lib/types/pos-bootstrap.ts) solo trae
  // id/name/phone/tin/storeCredit/isCreditable — sin address_2/location/
  // city/country/phone_2/note/loyalty/birthday/email. Ver flag en el reporte.
  customer_address_2: (data) => data.customerAddress2 ?? null,
  customer_location: (data) => data.customerLocation ?? null,
  customer_city: (data) => data.customerCity ?? null,
  customer_country: (data) => data.customerCountry ?? null,
  customer_phone_2: (data) => data.customerPhone2 ?? null,
  customer_note: (data) => data.customerNote ?? null,
  customer_loyalty: (data) => data.customerLoyalty ?? null,
  customer_birthday: (data) => data.customerBirthday ?? null,
  customer_email: (data) => data.customerEmail ?? null,
  // table_number reusa orderDestination (comandas de espacios) — es el dato
  // más cercano que existe hoy a "número de mesa" (orderDestinationText()).
  // No es un campo dedicado de mesa; documentado como aproximación.
  table_number: (data) => (data.docType === "order" ? data.orderDestination ?? null : null),

  // Transacción
  document_number: (data) => data.documentNumber ?? null,
  document_prefix: (data) => data.documentPrefix ?? null,
  document_sufix: (data) => data.documentSufix ?? null,
  document_type: (data) => documentTypeLabel(data.docType),
  date: (data) => data.date,
  duedate: (data) => data.dueDate ?? null,
  discount: (data) => formatMoney(data.discount),
  subtotal: (data) => formatMoney(data.subtotal),
  tax_total: (data) => formatMoney(data.taxTotal),
  total: (data) => formatMoney(data.total),
  // ⚠ nums_to_words: requiere conversión número→letras en español (ej. "Cien
  // mil guaraníes"). No hay librería ni función propia hoy — implementar un
  // total-en-letras incorrecto en un comprobante fiscal es peor que no
  // imprimirlo. Ver flag en el reporte.
  nums_to_words: () => null,
  sale_type: (data) => saleTypeLabel(data.docType),
  sale_type_contado: (data) => (saleTypeLabel(data.docType) === "Contado" ? "X" : ""),
  sale_type_credit: (data) => (saleTypeLabel(data.docType) === "Crédito" ? "X" : ""),
  tags: (data) => (data.tags && data.tags.length > 0 ? data.tags.join(", ") : null),
  note: (data) => data.note ?? null,
  transaction_id: (data) => data.transactionId,
  transaction_id_barcode: (data) => data.transactionId,
  // ⚠ associated_document: el payload de venta manda `parentTransactionId`
  // (UUID de la cotización origen) al backend, pero no vuelve como número
  // de documento imprimible en CreateSaleResult — requiere resolver ese
  // UUID contra el documentNumber de la transacción padre. Ver flag.
  associated_document: (data) => data.associatedDocument ?? null,
  // ⚠ fe_py: estampa/QR de factura electrónica paraguaya — depende del
  // módulo de facturación electrónica que otra sesión está construyendo en
  // paralelo (api/lib/EInvoice/*). No se implementa acá para no pisar ese
  // trabajo; el bloque queda "conocido" (sin warning) pero en blanco hasta
  // que ese módulo exponga el dato necesario.
  fe_py: () => null,
}

// ── Resolución de valores por ítem (bloques que se repiten por producto) ──

export type ItemFieldResolver = (item: TicketItem, data: TicketData) => string | null

export const ITEM_FIELD_RESOLVERS: Partial<Record<BlockType, ItemFieldResolver>> = {
  item: (item) => item.name,
  item_units: (item) => String(item.qty),
  item_uni_price: (item) => formatMoney(item.unitPrice),
  // item_price ("Precio") no tiene hoy un valor distinto de item_uni_price
  // ("Precio de lista") — TicketItem solo lleva un único unitPrice. Se
  // resuelve igual hasta que se modele precio neto vs. precio de lista.
  item_price: (item) => formatMoney(item.unitPrice),
  item_discount: (item) => formatMoney(item.discount),
  item_total: (item) => formatMoney(item.total),
  // item_subtotal ("Total" antes de impuesto) — sin desglose de impuesto
  // por ítem hoy, se resuelve igual que item_total.
  item_subtotal: (item) => formatMoney(item.total),
  // ⚠ item_id/item_uid/item_note/item_tags/item_tax/item_taxAmount/
  // item_taxAmount_single/item_price_notax/tax_single: TicketItem
  // (build-ticket-data.ts) no lleva id/sku/nota/etiquetas/tasa ni monto de
  // impuesto por ítem — requiere ampliar TicketItem y sus 3 builders
  // (buildTicketData, buildTicketItemsFromTransaction, buildTicketDataFromTxDetail)
  // con esos campos desde PosItem/catálogo. Ver flag en el reporte.
  item_id: () => null,
  item_uid: () => null,
  item_note: () => null,
  item_tags: () => null,
  item_tax: () => null,
  item_taxAmount: () => null,
  item_taxAmount_single: () => null,
  item_price_notax: () => null,
  tax_single: () => null,
}

/** true si el tipo es conocido por el catálogo (tiene resolver de valor, es
 *  de ítem, de tabla de ítems, o estructural) — un tipo fuera de esto es un
 *  bloque realmente no implementado, no un simple hueco de dato. */
export function isKnownBlockType(type: string): type is BlockType {
  return (
    type in BLOCK_VALUE_RESOLVERS ||
    ITEM_LINE_TYPES.has(type as BlockType) ||
    ITEM_TABLE_TYPES.has(type as BlockType) ||
    STRUCTURAL_TYPES.has(type as BlockType)
  )
}

/**
 * Las plantillas son un canvas: cada bloque tiene top/left absolutos, pero
 * los renderers recorrían `template.data` en orden de INSERCIÓN, no en
 * orden visual — un bloque agregado al final pero ubicado arriba se
 * imprimía al final. Se ordena por top y, a igual top, por left (mismo
 * criterio que usaría cualquier lector: arriba-abajo, izquierda-derecha).
 * Los bloques de ítem (ITEM_LINE_TYPES) suelen compartir el mismo `top`
 * (representan columnas de una sola fila-plantilla que el motor repite por
 * producto) y se diferencian por `left` — ordenar por left los deja en
 * orden de columna real, lo que además corrige el agrupado si el operador
 * los insertó fuera de orden en el editor.
 */
export function sortBlocksForRender<T extends { top: number; left: number }>(blocks: T[]): T[] {
  return [...blocks].sort((a, b) => a.top - b.top || a.left - b.left)
}
