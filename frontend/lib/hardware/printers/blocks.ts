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
import type { TicketData, TicketItem, TicketMoneyFormat } from "./build-ticket-data"
import { formatMoney as formatMoneyBase } from "@/lib/format"

/** Wrapper del helper canónico (lib/format.ts) para el pipeline de impresión
 *  — antes hardcodeaba `es-PY`/0 decimales acá y en print-in-browser.ts,
 *  ignorando `decimal==="yes"` del tenant (perdía centavos en el ticket).
 *  `money: null` (sin config) preserva el fallback previo. */
export function formatMoney(n: number, money: TicketMoneyFormat | null): string {
  return formatMoneyBase(n, money ?? undefined)
}

// ── D4 (context/38): alias de lectura para bloques renombrados ────────────
// `item_taxAmount`/`item_taxAmount_single` → `item_tax_amount`/
// `item_tax_amount_single`, SIN migración de datos: las plantillas guardadas
// siguen persistiendo el string legacy en `config.data[].type`. Este es el
// ÚNICO lugar que normaliza — a propósito el mismo módulo que ya es "la
// única fuente" del catálogo de bloques (ver comentario de archivo arriba).
// Un segundo mapa en el editor o en cada renderer se desincroniza con este
// tarde o temprano; todo lookup de `block.type` pasa por acá primero
// (`sortBlocksForRender`, que ambos renderers llaman como entrada única).
const LEGACY_BLOCK_TYPE_ALIASES: Partial<Record<string, BlockType>> = {
  item_taxAmount: "item_tax_amount",
  item_taxAmount_single: "item_tax_amount_single",
}

export function normalizeBlockType(type: string): BlockType {
  return (LEGACY_BLOCK_TYPE_ALIASES[type] ?? type) as BlockType
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
  "item_tax_amount",
  "item_tax_amount_single",
  "item_discount",
  "item_price",
  "item_uni_price",
  "item_price_notax",
  "item_total",
  "item_subtotal",
  // tax_single NO va acá desde F3c: es un agregado por-tasa a nivel venta
  // (ver BLOCK_VALUE_RESOLVERS abajo), no algo que se repita por línea.
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
  discount: (data) => formatMoney(data.discount, data.money),
  subtotal: (data) => formatMoney(data.subtotal, data.money),
  tax_total: (data) => formatMoney(data.taxTotal, data.money),
  total: (data) => formatMoney(data.total, data.money),
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
  // fe_py: link al portal de consulta del comprador (F6). En ESC/POS se
  // imprime como QR (ver render-template.ts, que intercepta este tipo antes de
  // llegar al resolver); acá se devuelve la URL en texto, que es lo que usa el
  // fallback HTML del navegador. null cuando la venta no generó documento
  // electrónico — el bloque queda en blanco, como cualquier otro sin dato.
  fe_py: (data) => data.einvoiceUrl ?? null,

  // ── F3c (context/38 §D): bloques parametrizados por tasa ────────────────
  // El bloque guarda el `taxId` en `block.text` (ver comentario en
  // BlockType, print-template.ts). Los valores salen de sumar
  // `taxNet`/`taxAmount` YA CONGELADOS por línea (`groupItemsByTaxRate`
  // abajo) — nunca se recalcula contra el catálogo.
  subtotal_by_rate: (data, block) => {
    const bucket = findTaxBucket(data.items, block.text)
    return bucket ? formatMoney(bucket.base, data.money) : null
  },
  iva_by_rate: (data, block) => {
    const bucket = findTaxBucket(data.items, block.text)
    return bucket ? formatMoney(bucket.amount, data.money) : null
  },
  item_total_by_rate: (data, block) => {
    const bucket = findTaxBucket(data.items, block.text)
    return bucket ? formatMoney(bucket.base + bucket.amount, data.money) : null
  },
  // Suma de todos los buckets — mismo total que `tax_total`, expuesto aparte
  // para plantillas que arman una lista de `iva_by_rate` con un pie
  // `iva_total` (el legacy los trataba como bloques distintos).
  iva_total: (data) => formatMoney(data.taxTotal, data.money),

  // tax_single (legacy: `documentPrintBuilder.source.js`, `type ==
  // 'tax_single'`): el operador tipeaba una TASA (ej. "10") en `block.text`
  // y el bloque imprimía el subtotal de impuesto de TODA la venta para esa
  // tasa — un agregado a nivel venta, no algo que se repita por línea (así
  // que NO está en ITEM_LINE_TYPES desde F3c). Ahora resuelto con la misma
  // infraestructura por-tasa que `iva_by_rate`, buscando el bucket por
  // `rate` en vez de por `taxId` (bloques legacy no tienen taxId guardado).
  tax_single: (data, block) => {
    const rate = Number(block.text)
    if (Number.isNaN(rate)) return null
    // Suma TODOS los buckets con esa tasa, no solo el primero — el
    // agrupamiento ahora es por `taxId` cuando existe (ver
    // `groupItemsByTaxRate`), así que dos impuestos distintos con la misma
    // tasa caen en buckets separados y el bloque legacy (que solo conoce la
    // tasa, no el taxId) necesita juntarlos de nuevo.
    const matching = groupItemsByTaxRate(data.items).filter((b) => b.rate === rate)
    if (matching.length === 0) return null
    return formatMoney(matching.reduce((s, b) => s + b.amount, 0), data.money)
  },
}

// ── F3c (context/38 §D): agregación por tasa ───────────────────────────────
// Espejo TS de `SaleService::groupTaxByRate` (PHP) pero del lado del ticket:
// suma `taxNet`/`taxAmount` que TicketItem YA trae congelados por línea
// (F3b), nunca recalcula contra el catálogo. A diferencia del PHP no filtra
// líneas de descuento sintéticas ni de canje de voucher (TicketItem no
// modela esos flags hoy) — para el ticket impreso el desvío es cosmético
// (una línea de canje ya llega con `taxAmount=0`/exenta desde el motor, ver
// build-ticket-data.ts); el Libro Ventas real (F5) usa el agregado del
// backend, no este.
export interface TaxByRateBucket {
  taxId: string | null
  rate: number | null
  kind: TicketItem["taxKind"]
  base: number
  amount: number
}

export function groupItemsByTaxRate(items: TicketItem[]): TaxByRateBucket[] {
  const buckets = new Map<string, TaxByRateBucket>()
  const order: string[] = []
  for (const item of items) {
    if (item.taxRate === null || item.taxKind === null) continue
    // Agrupar por `taxId` cuando está disponible — dos impuestos distintos
    // pueden compartir tasa/kind (nada lo impide en el schema: mig 120 no
    // tiene UNIQUE sobre rate+kind), y agrupar solo por rate|kind mezclaría
    // sus líneas en un mismo bucket, pisando el `taxId` de una con el de la
    // otra. Fallback a `rate|kind` únicamente cuando no hay taxId (bloques
    // legacy como `tax_single`, que buscan por tasa tipeada a mano, no por
    // impuesto del catálogo).
    const key = item.taxId ?? `${item.taxRate}|${item.taxKind}`
    if (!buckets.has(key)) {
      buckets.set(key, { taxId: item.taxId, rate: item.taxRate, kind: item.taxKind, base: 0, amount: 0 })
      order.push(key)
    }
    const bucket = buckets.get(key)!
    bucket.base += item.taxNet ?? 0
    bucket.amount += item.taxAmount ?? 0
  }
  return order.map((k) => buckets.get(k)!)
}

function findTaxBucket(items: TicketItem[], taxId: string): TaxByRateBucket | undefined {
  return groupItemsByTaxRate(items).find((b) => b.taxId === taxId)
}

// ── Resolución de valores por ítem (bloques que se repiten por producto) ──

export type ItemFieldResolver = (item: TicketItem, data: TicketData) => string | null

export const ITEM_FIELD_RESOLVERS: Partial<Record<BlockType, ItemFieldResolver>> = {
  item: (item) => item.name,
  item_units: (item) => String(item.qty),
  item_uni_price: (item, data) => formatMoney(item.unitPrice, data.money),
  // item_price ("Precio") no tiene hoy un valor distinto de item_uni_price
  // ("Precio de lista") — TicketItem solo lleva un único unitPrice. Se
  // resuelve igual hasta que se modele precio neto vs. precio de lista.
  item_price: (item, data) => formatMoney(item.unitPrice, data.money),
  item_discount: (item, data) => formatMoney(item.discount, data.money),
  item_total: (item, data) => formatMoney(item.total, data.money),
  // item_subtotal ("Total" antes de impuesto) — sin desglose de impuesto
  // por ítem hoy, se resuelve igual que item_total.
  item_subtotal: (item, data) => formatMoney(item.total, data.money),
  // F3b (context/38): TicketItem ya lleva id/sku/nota/tasa/monto de impuesto
  // — poblado por cada builder según lo que su fuente realmente tiene (ver
  // build-ticket-data.ts). item_tags sigue null en los 3 builders (ninguno
  // modela etiquetas por ítem hoy, solo a nivel venta).
  item_id: (item) => item.id,
  item_uid: (item) => item.uid,
  item_note: (item) => item.note,
  item_tags: (item) => (item.tags && item.tags.length > 0 ? item.tags.join(", ") : null),
  // Tasa de la línea. Exenta (`taxKind === "exempt"`) se rotula "Exento" en
  // vez de imprimir "0" — 0% tasado y exento son fiscalmente distintos
  // (context/38 §Reglas LATAM), no hay que confundirlos en el ticket.
  item_tax: (item) => {
    if (item.taxKind === "exempt") return "Exento"
    if (item.taxRate === null) return null
    return `${item.taxRate}%`
  },
  // Impuesto de la línea completa (todas las unidades). D4: renombrado desde
  // `item_taxAmount` — el alias de lectura vive en `normalizeBlockType`.
  item_tax_amount: (item, data) => (item.taxAmount === null ? null : formatMoney(item.taxAmount, data.money)),
  // Impuesto de UNA unidad — item_tax_amount / cantidad. qty=0 no debería
  // darse (línea sin unidades no se vende), pero se guarda por las dudas.
  // D4: renombrado desde `item_taxAmount_single`.
  item_tax_amount_single: (item, data) =>
    item.taxAmount === null || item.qty === 0 ? null : formatMoney(item.taxAmount / item.qty, data.money),
  // Precio unitario NETO (sin impuesto) — item_uni_price/item_price son el
  // precio con impuesto incluido cuando taxIncluded=true; este bloque separa
  // la base imponible por unidad para plantillas que quieren desglosar.
  item_price_notax: (item, data) =>
    item.taxNet === null || item.qty === 0 ? null : formatMoney(item.taxNet / item.qty, data.money),
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
 *
 * También es el punto único donde se aplica `normalizeBlockType` (D4,
 * context/38): ambos renderers (`render-template.ts`, `html-renderer.ts`)
 * llaman esta función como entrada antes de iterar `template.data`, así que
 * normalizar acá alcanza para cubrir los dos sin duplicar el alias en cada
 * uno.
 */
export function sortBlocksForRender<T extends { type: string; top: number; left: number }>(
  blocks: T[],
): T[] {
  const normalized = blocks.map((b) => ({ ...b, type: normalizeBlockType(b.type) })) as T[]
  return normalized.sort((a, b) => a.top - b.top || a.left - b.left)
}
