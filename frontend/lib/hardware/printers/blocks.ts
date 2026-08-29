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
import { formatAmount as formatAmountShared, formatMoney as formatMoneyShared } from "@/lib/format-money"
import { formatPhone } from "@/lib/phone"
import { resolveNumberLocale } from "@/lib/tenant-locale"
import type { TicketData, TicketItem } from "./build-ticket-data"

/**
 * Formatea un monto para el ticket según la moneda/separadores del TENANT
 * (`TicketData.currency`/`thousand`/`decimal`, poblados desde `PosConfig` por
 * cada builder que tiene bootstrap disponible — build-ticket-data.ts).
 *
 * Delega en el helper YA compartido por el resto del panel/POS
 * (`lib/format-money.ts`) — antes esta función hardcodeaba
 * `Intl.NumberFormat("es-PY", {currency:"PYG"})`, así que CUALQUIER tenant
 * (sin importar su config real) perdía los centavos en todo ticket impreso:
 * un comercio con `decimal="yes"` veía 114.84 impreso como "Gs. 115"
 * (redondeado, no truncado).
 *
 * Fallback cuando `TicketData` no trae la config de moneda (builders sin
 * `config` disponible: remisión, traslado de stock, cierre de caja — ninguno
 * imprime un monto real de todos modos): el MISMO default que ya usa
 * `formatMoney` de `lib/format-money.ts` en el resto de la app, no un
 * hardcode nuevo específico de impresión.
 */
export function formatMoney(
  n: number,
  data: Pick<TicketData, "currency" | "thousand" | "decimal" | "country">,
): string {
  return formatMoneyShared(n, {
    // Sin `?? "Gs"` acá: el fallback de la etiqueta vive en
    // `resolveCurrencyLabel` (lib/tenant-locale.ts), que además cubre el caso
    // que este `??` NO cubría — el bootstrap manda string VACÍO cuando el
    // tenant no configuró moneda, y `??` solo dispara con null/undefined.
    currency: data.currency ?? "",
    // `country` va para que ese resolver pueda caer a la moneda del PAÍS del
    // tenant antes de rendirse al signo genérico. Sin esto, un comercio que
    // eligió país pero no tocó el campo moneda imprimía el ticket sin símbolo.
    country: data.country ?? "",
    // Sin defaults de formato tampoco: `thousand`/`decimal` ausentes se
    // resuelven contra el país igual que la moneda. El `?? "dot"` anterior era
    // correcto para Paraguay y para casi toda LatAm, pero no para MX/US/EC.
    thousand: data.thousand ?? null,
    decimal: data.decimal ?? null,
  })
}

/**
 * Monto SIN el símbolo de moneda — el formato por defecto de casi todo el
 * ticket.
 *
 * Decisión del owner (2026-08-26): el símbolo repetido en cada precio de ítem
 * no informa nada (un ticket no mezcla monedas) y en un rollo de 57 mm gasta
 * ancho en cada línea del detalle. La moneda se declara UNA vez, en el total
 * de la venta — que es donde el cliente la busca.
 *
 * Los separadores siguen siendo los del tenant: lo único que se saca es la
 * etiqueta, nunca el formato.
 */
export function formatAmountOnly(
  n: number,
  data: Pick<TicketData, "thousand" | "decimal" | "country">,
): string {
  return formatAmountShared(n, {
    country: data.country ?? "",
    thousand: data.thousand ?? null,
    decimal: data.decimal ?? null,
  })
}

/**
 * Teléfono para el ticket, en formato nacional.
 *
 * Convención del proyecto (context/20 §8): la BD guarda E.164 sin '+' y
 * TODO lo que ve una persona va en nacional. Los resolvers de teléfono
 * imprimían el crudo ("595991742353") — se notaba porque los datos de demo
 * del editor de plantillas (`build-ticket-data.ts`) ya están en nacional, así
 * que la vista previa mentía respecto del papel. Devuelve `null` y no ""
 * para que el bloque se omita igual que el resto de los resolvers vacíos.
 */
function phoneBlock(value: string | null | undefined): string | null {
  return formatPhone(value) || null
}

/**
 * Formatea el % de descuento de una línea (`TicketItem.discountPercent`) —
 * hasta 2 decimales, sin ceros de más (6.67% en vez de 6.6666666666667%). Es
 * un % EFECTIVO calculado por `allocateLineDiscounts`
 * (frontend/lib/cart/allocate-discounts.ts), casi nunca un entero.
 */
/**
 * Cantidad de una línea, para el ticket.
 *
 * Hasta 2 decimales y sin ceros de relleno: "2", "1,5", "1,25". La cantidad es
 * fraccionable (1,5 kg de azúcar), así que imprimirla cruda podía sacar
 * "1.4999999999" y fijarle 2 decimales siempre gastaba dos caracteres en cada
 * línea entera — en un roll de 57mm eso es ancho que se le quita al nombre del
 * producto (pedido del owner 2026-08-26).
 *
 * El separador decimal sale de la config del tenant, igual que los montos: un
 * comercio que escribe 1,5 no puede leer 1.5 en su propio ticket.
 */
export function formatQty(
  n: number,
  data: Pick<TicketData, "thousand" | "country">,
): string {
  return new Intl.NumberFormat(
    resolveNumberLocale({ thousand: data.thousand ?? null, country: data.country ?? "" }),
    { maximumFractionDigits: 2 },
  ).format(n)
}

export function formatPercent(n: number): string {
  return `${Number(n.toFixed(2))}%`
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
  "item_discount_percent",
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

// ── Geometría de líneas (hor_line / ver_line) ─────────────────────────────

/**
 * Grosor por defecto de una línea, en px del canvas del editor (≈96 DPI, el
 * mismo sistema de coordenadas que `PrintBlock.top/left/width/height`).
 * 1px es lo que el editor ya dibujaba (`h-px` en canvas-block.tsx): el
 * default no cambia el aspecto de ninguna plantilla ya guardada.
 */
export const LINE_DEFAULT_THICKNESS_PX = 1

export interface LineGeometry {
  orientation: "horizontal" | "vertical"
  /** Grosor en px del canvas. Nunca 0 — una línea de 0px no se imprime. */
  thickness: number
  /** Largo sobre el eje principal, en px del canvas. */
  length: number
  /** Corrimiento sobre el eje transversal para centrar la línea en la caja. */
  crossOffset: number
}

/**
 * ÚNICA fuente de la geometría de una línea — la comparten el editor
 * (canvas-block.tsx), el papel (html-renderer.ts) y el rollo térmico
 * (render-template.ts). Antes cada superficie la inventaba por su cuenta y
 * las tres divergían: el canvas la pintaba como la caja entera
 * (`h-px w-full` / `h-full w-px`), el HTML de hoja emitía un `<hr>` con
 * `margin:4px 0` DENTRO del wrapper posicional (que recorta con
 * `overflow:hidden` a la altura del bloque), y ESC/POS tiraba una regla de
 * ancho de papel completo. Resultado: la línea se veía en el editor y NO
 * salía nunca en la simulación ni en la impresión en hoja (bug 2026-08-22).
 *
 * Modelo: la caja del bloque es el HUECO que el operador posiciona y agarra
 * con el mouse (el editor le impone `MIN_BLOCK_SIZE` = 20px de alto mínimo,
 * así que la caja NO puede ser la línea misma sin volverse una barra negra
 * de 20px); la línea se dibuja CENTRADA dentro de ese hueco, con el largo
 * del eje principal de la caja y un grosor propio.
 *
 * El grosor vive en `block.text` — el mismo mecanismo de "campo `text` como
 * METADATO por tipo de bloque" que ya usan `tax_single` (guarda la tasa) y
 * los bloques por-tasa (guardan el `taxId`); ver print-template.ts. Para
 * `hor_line`/`ver_line` ese campo estaba sin uso (`defaultText: ""` en
 * print-template-palette.ts) y ningún resolver lo lee, así que no hace falta
 * ampliar el shape del JSON persistido — que es EXACTO al del legacy a
 * propósito (ver `PrintTemplateConfig`). Una plantilla vieja trae `""` y cae
 * al default de 1px.
 *
 * Devuelve `null` para cualquier tipo que no sea una línea, así el caller lo
 * usa como guarda en vez de repetir el `switch` de tipos.
 */
export function lineGeometry(
  block: Pick<PrintBlock, "type" | "text" | "width" | "height">,
): LineGeometry | null {
  const type = normalizeBlockType(block.type)
  if (type !== "hor_line" && type !== "ver_line") return null

  // La orientación sale del TIPO, no de qué dimensión es mayor: una "Línea
  // Horizontal" que el operador estira a lo alto sigue siendo horizontal —
  // si no, los dos tipos de bloque serían indistinguibles y redimensionar
  // uno lo convertiría en el otro sin avisar.
  const orientation = type === "hor_line" ? "horizontal" : "vertical"
  const horizontal = orientation === "horizontal"
  const length = Math.max(1, Math.round(horizontal ? block.width : block.height))
  const crossSize = Math.max(1, Math.round(horizontal ? block.height : block.width))

  const requested = Number.parseFloat(block.text ?? "")
  const thickness = Math.min(
    // Nunca más gruesa que el hueco que el operador le dejó — si no, el
    // canvas mostraría una línea recortada y el papel una barra que pisa a
    // los bloques vecinos.
    crossSize,
    Number.isFinite(requested) && requested >= 1 ? Math.round(requested) : LINE_DEFAULT_THICKNESS_PX,
  )

  return { orientation, thickness, length, crossOffset: Math.round((crossSize - thickness) / 2) }
}

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

/**
 * Título por defecto del bloque `document_number`, según el TIPO de documento
 * (pedido del owner 2026-08-29): la misma plantilla imprime "Factura Nro.:" en
 * la venta, "Orden Nro.:" en la comanda y "Recibo Nro.:" en un pago. Solo
 * aplica cuando el operador NO escribió un título propio — `block.label`
 * siempre gana (la plantilla decide, context/20).
 */
const DOC_NUMBER_LABELS: Record<string, string> = {
  receipt: "Recibo Nro.:",
  invoice: "Factura Nro.:",
  factura: "Factura Nro.:",
  credit: "Factura Nro.:",
  sale: "Factura Nro.:",
  order: "Orden Nro.:",
  workorder: "Orden Nro.:",
  quote: "Cotización Nro.:",
  delivery: "Remisión Nro.:",
  return: "Nota de crédito Nro.:",
}

function docNumberLabel(docType: string): string {
  return DOC_NUMBER_LABELS[docType] ?? "Nro.:"
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
  company_phone: (data) => phoneBlock(data.companyPhone),
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
  outlet_phone: (data) => phoneBlock(data.outletPhone),

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
  customer_phone: (data) => phoneBlock(data.customerPhone),
  // ⚠ PosCustomer (lib/types/pos-bootstrap.ts) solo trae
  // id/name/phone/tin/storeCredit/isCreditable — sin address_2/location/
  // city/country/phone_2/note/loyalty/birthday/email. Ver flag en el reporte.
  customer_address_2: (data) => data.customerAddress2 ?? null,
  customer_location: (data) => data.customerLocation ?? null,
  customer_city: (data) => data.customerCity ?? null,
  customer_country: (data) => data.customerCountry ?? null,
  customer_phone_2: (data) => phoneBlock(data.customerPhone2),
  customer_note: (data) => data.customerNote ?? null,
  customer_loyalty: (data) => data.customerLoyalty ?? null,
  customer_birthday: (data) => data.customerBirthday ?? null,
  customer_email: (data) => data.customerEmail ?? null,
  // table_number reusa orderDestination (comandas de espacios) — es el dato
  // más cercano que existe hoy a "número de mesa" (orderDestinationText()).
  // No es un campo dedicado de mesa; documentado como aproximación.
  // Sin gate por docType (lo tenía y contradecía context/20: si el bloque está
  // en la plantilla, sale — el dato existe o no, y sin dato el bloque queda en
  // blanco solo). El gate hacía que estos bloques no imprimieran NADA en la
  // vista previa ni en documentos que sí traen el dato (reporte 2026-08-29).
  table_number: (data) => data.orderDestination ?? null,

  // Comanda — reemplazan el banner que los renderers inyectaban solos cuando
  // `docType === "order"` (ver BlockType en lib/types/print-template.ts). Como
  // cualquier otro bloque: si el dato no aplica resuelve `null` y el bloque
  // sale en blanco, sin gating por tipo de documento.
  order_number: (data) => data.ticketNo ?? null,
  order_destination: (data) => data.orderDestination ?? null,

  // Transacción
  document_number: (data) => data.documentNumber ?? null,
  document_prefix: (data) => data.documentPrefix ?? null,
  document_sufix: (data) => data.documentSufix ?? null,
  document_type: (data) => documentTypeLabel(data.docType),
  date: (data) => data.date,
  duedate: (data) => data.dueDate ?? null,
  discount: (data) => formatAmountOnly(data.discount, data),
  subtotal: (data) => formatAmountOnly(data.subtotal, data),
  tax_total: (data) => formatAmountOnly(data.taxTotal, data),
  // EL ÚNICO bloque con símbolo de moneda (decisión del owner 2026-08-26, ver
  // `formatAmountOnly`): el resto de los importes —ítems, subtotal, descuento,
  // IVA por tasa, pagos— salen como número pelado. La moneda se declara una
  // vez, donde el cliente la busca.
  total: (data) => formatMoney(data.total, data),
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
  // CDC en texto (el QR es `fe_py`). Acepta título como cualquier bloque
  // simple ("CDC:"). null hasta que el documento se emita — ver TicketData.
  fe_cdc: (data) => data.einvoiceCdc ?? null,

  // Remisión (context/42) — poblados solo por buildTicketDataFromStockTransfer
  // y buildTicketDataFromRemision (build-ticket-data.ts); null en cualquier
  // otro origen de TicketData, igual que fe_py arriba.
  transfer_reason: (data) => data.transferReason ?? null,
  transfer_origin: (data) => data.originLabel ?? null,
  transfer_destination: (data) => data.destinationLabel ?? null,

  // ── F3c (context/38 §D): bloques parametrizados por tasa ────────────────
  // El bloque guarda el `taxId` en `block.text` (ver comentario en
  // BlockType, print-template.ts). Los valores salen de sumar
  // `taxNet`/`taxAmount` YA CONGELADOS por línea (`groupItemsByTaxRate`
  // abajo) — nunca se recalcula contra el catálogo.
  subtotal_by_rate: (data, block) => {
    const bucket = findTaxBucket(data.items, block.text)
    return bucket ? formatAmountOnly(bucket.base, data) : null
  },
  iva_by_rate: (data, block) => {
    const bucket = findTaxBucket(data.items, block.text)
    return bucket ? formatAmountOnly(bucket.amount, data) : null
  },
  item_total_by_rate: (data, block) => {
    const bucket = findTaxBucket(data.items, block.text)
    return bucket ? formatAmountOnly(bucket.base + bucket.amount, data) : null
  },
  // Suma de todos los buckets — mismo total que `tax_total`, expuesto aparte
  // para plantillas que arman una lista de `iva_by_rate` con un pie
  // `iva_total` (el legacy los trataba como bloques distintos).
  iva_total: (data) => formatAmountOnly(data.taxTotal, data),

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
    return formatAmountOnly(matching.reduce((s, b) => s + b.amount, 0), data)
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

/**
 * Prefijo de una línea hija de add-on (F5, D3 de context/41).
 *
 * Ambos renderers (ESC/POS y HTML) pintan el nombre del ítem con el mismo
 * resolver, así que la indentación se hace en el TEXTO y no con CSS: una
 * comandera térmica no tiene padding. Dos espacios + "+" es lo que ya lee un
 * cajero en cualquier comanda: `  + Queso extra`.
 */
const ADDON_CHILD_PREFIX = "  + "

/**
 * Nombre imprimible de una línea. ÚNICA fuente: el nombre del ítem se pinta en
 * tres lugares (el resolver del bloque `item`, y la tabla `item_receipt*` de
 * cada uno de los dos renderers), y la indentación del add-on tiene que ser la
 * misma en los tres o la comanda y el ticket dejan de coincidir. Cualquier
 * regla nueva sobre el nombre de línea va acá, no en el call-site.
 */
export function ticketItemName(item: TicketItem): string {
  return item.isAddonChild ? `${ADDON_CHILD_PREFIX}${item.name}` : item.name
}

export const ITEM_FIELD_RESOLVERS: Partial<Record<BlockType, ItemFieldResolver>> = {
  item: (item) => ticketItemName(item),
  item_units: (item, data) => formatQty(item.qty, data),
  item_uni_price: (item, data) => formatAmountOnly(item.unitPrice, data),
  // item_price ("Precio") no tiene hoy un valor distinto de item_uni_price
  // ("Precio de lista") — TicketItem solo lleva un único unitPrice. Se
  // resuelve igual hasta que se modele precio neto vs. precio de lista.
  item_price: (item, data) => formatAmountOnly(item.unitPrice, data),
  // item_discount = MONTO del descuento de la línea (defaultText "##.###" en
  // print-template-palette.ts ya lo daba a entender: formato de plata, no de
  // %). Plantillas guardadas con este tipo NO se rompen: su significado no
  // cambia, lo que se corrige es QUÉ dato le llegaba (antes, 2 de los 3
  // builders lo llenaban con el % en vez de la plata — ver TicketItem arriba).
  // El % vive en el bloque nuevo `item_discount_percent`.
  item_discount: (item, data) => formatAmountOnly(item.discountAmount, data),
  item_discount_percent: (item) => formatPercent(item.discountPercent),
  item_total: (item, data) => formatAmountOnly(item.total, data),
  // item_subtotal ("Total" antes de impuesto) — sin desglose de impuesto
  // por ítem hoy, se resuelve igual que item_total.
  item_subtotal: (item, data) => formatAmountOnly(item.total, data),
  // F3b (context/38): TicketItem ya lleva id/sku/nota/tasa/monto de impuesto
  // — poblado por cada builder según lo que su fuente realmente tiene (ver
  // build-ticket-data.ts). item_tags (pedido owner 2026-08-14): etiquetas de
  // línea, uso interno — pobladas por buildTicketData/buildOrderTicketData/
  // buildTicketItemsFromTransaction; `null` en buildTicketDataFromTxDetail
  // (endpoint del panel sin ese dato, igual límite que item_note ahí). Que
  // NO aparezcan en facturas depende de que el comercio no agregue este
  // bloque a la plantilla de su impresora fiscal — no hay una restricción
  // estructural por docType en la paleta (print-template-palette.ts) hoy.
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
  item_tax_amount: (item, data) => (item.taxAmount === null ? null : formatAmountOnly(item.taxAmount, data)),
  // Impuesto de UNA unidad — item_tax_amount / cantidad. qty=0 no debería
  // darse (línea sin unidades no se vende), pero se guarda por las dudas.
  // D4: renombrado desde `item_taxAmount_single`.
  item_tax_amount_single: (item, data) =>
    item.taxAmount === null || item.qty === 0 ? null : formatAmountOnly(item.taxAmount / item.qty, data),
  // Precio unitario NETO (sin impuesto) — item_uni_price/item_price son el
  // precio con impuesto incluido cuando taxIncluded=true; este bloque separa
  // la base imponible por unidad para plantillas que quieren desglosar.
  item_price_notax: (item, data) =>
    item.taxNet === null || item.qty === 0 ? null : formatAmountOnly(item.taxNet / item.qty, data),
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

/**
 * Valor de un bloque para superficies que NO repiten por ítem — el
 * thumbnail de un bloque individual en el canvas del editor
 * (canvas-block.tsx), que muestra UN bloque a la vez, no la plantilla
 * completa. A diferencia de los renderers reales (que repiten un bloque de
 * `ITEM_LINE_TYPES` una vez por producto vendido, ver `sortBlocksForRender`
 * + el loop de agrupado en cada renderer), acá se resuelve contra el
 * PRIMER ítem de `data.items` nada más. Los bloques de tabla
 * (`item_receipt*`) devuelven una fila representativa, no el listado
 * completo — el canvas es una miniatura de una sola fila, no una hoja.
 *
 * Reusa exactamente los mismos resolvers/helpers que la Vista Previa
 * completa (`preview-dialog.tsx`) y los renderers reales — NUNCA un
 * segundo diccionario de texto de ejemplo (esa fue la causa raíz del bug
 * de IVA 5%/10% mostrando el mismo número: dos fuentes de verdad para "qué
 * muestra un bloque").
 */
/**
 * Antepone el título declarado por la plantilla (`block.label`).
 *
 * Vive acá y no en cada renderer porque los tres —canvas/preview, HTML y
 * ESC/POS— resolvían el valor con la misma línea (`resolver(data, block)`) y
 * este archivo existe justamente para que esa decisión se tome UNA vez: la
 * historia del módulo es que tres switches paralelos daban tres resultados
 * distintos.
 *
 * Un bloque sin valor no imprime su título: un "Fecha:" suelto sin fecha es
 * peor que la línea ausente.
 */
export function withBlockLabel(block: PrintBlock, value: string | null): string | null {
  if (value === null || value === undefined || value === "") return null
  const label = (block.label ?? "").trim()
  return label ? `${label} ${value}` : value
}

/**
 * Valor final de un bloque SIMPLE (no de ítem), con su título si lo tiene.
 * `null` = el bloque no imprime nada (sin dato, o tipo sin resolver).
 */
export function resolveSimpleBlock(block: PrintBlock, data: TicketData): string | null {
  const resolver = BLOCK_VALUE_RESOLVERS[block.type]
  if (!resolver) return null
  const value = resolver(data, block) ?? null
  // `document_number` sin título propio: el título sale del TIPO de documento
  // ("Factura Nro.:" / "Orden Nro.:" / "Recibo Nro.:"). Ver DOC_NUMBER_LABELS.
  if (block.type === "document_number" && !(block.label ?? "").trim()) {
    return withBlockLabel({ ...block, label: docNumberLabel(data.docType) }, value)
  }
  return withBlockLabel(block, value)
}

/**
 * Líneas del bloque `payment_methods` — una por pago cobrado.
 *
 * Vive acá y no en cada renderer por la misma razón que `resolveSimpleBlock`:
 * los dos armaban `${método}: ${monto}` por su cuenta, y el título de la
 * plantilla habría llegado a uno solo. El título va SOLO en la primera línea
 * ("Forma de pago: Efectivo …"): repetirlo por pago sería ruido en un rollo.
 */
export function resolvePaymentLines(block: PrintBlock, data: TicketData): string[] {
  const lines = data.payments.map((p) => `${p.method}: ${formatAmountOnly(p.amount, data)}`)
  if (lines.length === 0) return []
  const label = (block.label ?? "").trim()
  if (label === "") return lines
  // El título va en su PROPIA línea y los pagos debajo, uno por línea. Antes se
  // anteponía al primero ("Forma de pago: Efectivo: 200.000") y con dos medios
  // de pago quedaba un renglón con dos títulos y el resto suelto (reporte del
  // owner 2026-08-28). Como encabezado de lista, una venta mixta se lee:
  //
  //   Formas de pago:
  //   Efectivo: 200.000
  //   T. de Crédito: 100.000
  return [label, ...lines]
}

/**
 * Celdas NUMÉRICAS de una fila del listado de ítems (`item_receipt*`), en
 * orden y ya formateadas. El NOMBRE del producto no va acá: ocupa su propia
 * línea, arriba, porque es lo único que puede wrapear.
 *
 * Compartido para que la fila diga lo mismo en la vista previa, el HTML y la
 * térmica — quién la reparte a lo ancho del papel es otra decisión, y esa sí
 * es de la grilla (`distributeRow` en roll-grid.ts).
 */
export function itemTableCells(
  block: PrintBlock,
  item: TicketItem,
  data: TicketData,
): string[] {
  const cols = itemTableColumns(block.type)
  const cells: string[] = []
  if (cols.qty) cells.push(formatQty(item.qty, data))
  if (cols.unitPrice) cells.push(formatAmountOnly(item.unitPrice, data))
  if (cols.total) cells.push(formatAmountOnly(item.total, data))
  return cells
}

export function resolveSingleBlockPreview(block: PrintBlock, data: TicketData): string {
  if (block.type === "payment_methods") {
    return resolvePaymentLines(block, data)[0] ?? ""
  }
  if (ITEM_TABLE_TYPES.has(block.type)) {
    const item = data.items[0]
    if (!item) return ""
    return [ticketItemName(item), ...itemTableCells(block, item, data)].join("  ")
  }
  if (ITEM_LINE_TYPES.has(block.type)) {
    const item = data.items[0]
    if (!item) return ""
    const resolver = ITEM_FIELD_RESOLVERS[block.type]
    return resolver ? resolver(item, data) ?? "" : ""
  }
  return resolveSimpleBlock(block, data) ?? ""
}
