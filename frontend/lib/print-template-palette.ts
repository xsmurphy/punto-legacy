/**
 * Catálogo de bloques para la paleta del editor — VERBATIM del legacy
 * (panel/views/settings.html §panel.templates). Cada item replica el
 * `data-type` y `data-default` de los `<a class="addField">` del HTML.
 *
 * El POS imprime usando `ducumentPrintBuilder.js` (legacy en /panel/scripts),
 * que hace lookup por `type` para sustituir el texto por el dato real.
 */

import type { BlockType, PaperSize } from "@/lib/types/print-template"
import { isReceipt } from "@/lib/types/print-template"
import type { Tax } from "@/lib/types/tax"

export interface PaletteItem {
  type: BlockType
  label: string
  defaultText: string
  /** Si está presente, oculta el bloque cuando NO se está en hoja receipt. */
  receiptOnly?: boolean
  /** Si está presente, oculta el bloque cuando SÍ se está en hoja receipt. */
  receiptHidden?: boolean
}

export interface PaletteSection {
  id: string
  label: string
  items: PaletteItem[]
}

/** Aviso: __TIN__ y __TAX__ se sustituyen por el tin_name del país (RUC / NIT / CUIT) y el nombre del impuesto principal (IVA / VAT). El motor del POS hace la sustitución. */
export const PALETTE: PaletteSection[] = [
  {
    id: "tools",
    label: "Herramientas",
    items: [
      { type: "custom", label: "Texto Personalizado", defaultText: "" },
      { type: "hor_line", label: "Línea Horizontal", defaultText: "", receiptHidden: true },
      { type: "ver_line", label: "Línea Vertical", defaultText: "", receiptHidden: true },
    ],
  },
  {
    id: "company",
    label: "Empresa",
    items: [
      { type: "company_logo", label: "Logo", defaultText: "" },
      { type: "company_logo", label: "Logo (B&W)", defaultText: "" },
      { type: "company_name", label: "Nombre", defaultText: "" },
      { type: "company_billing_name", label: "Razón Social", defaultText: "" },
      { type: "company_tin", label: "__TIN__ de la empresa", defaultText: "" },
      { type: "company_address", label: "Dirección", defaultText: "" },
      { type: "company_email", label: "Email", defaultText: "" },
      { type: "company_website", label: "Sitio Web", defaultText: "" },
      { type: "outlet_name", label: "Nombre - Sucursal", defaultText: "Nombre - Sucursal" },
      { type: "outlet_billing_name", label: "Razón social - Sucursal", defaultText: "Razón social - Sucursal" },
      { type: "outlet_tin", label: "__TIN__ - Sucursal", defaultText: "__TIN__ - Sucursal" },
      { type: "outlet_address", label: "Dirección - Sucursal", defaultText: "Dirección - Sucursal" },
      { type: "outlet_phone", label: "Teléfono - Sucursal", defaultText: "Teléfono de Sucursal" },
      { type: "register_name", label: "Caja Registradora", defaultText: "Caja Registradora" },
      { type: "printer_name", label: "Impresora", defaultText: "Impresora" },
      { type: "auth_number", label: "No. Timbrado o Autorización", defaultText: "######" },
      { type: "auth_start_date", label: "Inicio de Timbrado", defaultText: "####-##-##" },
      { type: "auth_expiration", label: "Fin de Timbrado", defaultText: "####-##-##" },
      { type: "user_name", label: "Usuario", defaultText: "Usuario" },
    ],
  },
  {
    id: "customer",
    label: "Cliente",
    items: [
      { type: "customer_name", label: "Razón Social", defaultText: "Razón Social" },
      { type: "customer_full_name", label: "Nombre y Apellido", defaultText: "Nombre y Apellido" },
      { type: "customer_tin", label: "__TIN__", defaultText: "__TIN__" },
      { type: "customer_ci", label: "Doc. de Identidad", defaultText: "#######" },
      { type: "customer_address", label: "Dirección 1", defaultText: "Dirección 1 del cliente" },
      { type: "customer_address_2", label: "Dirección 2", defaultText: "Dirección 2 del cliente" },
      { type: "customer_location", label: "Localidad", defaultText: "Localidad" },
      { type: "customer_city", label: "Ciudad", defaultText: "Ciudad" },
      { type: "customer_country", label: "País", defaultText: "País" },
      { type: "customer_phone", label: "Teléfono 1", defaultText: "Teléfono 1 del Cliente" },
      { type: "customer_phone_2", label: "Teléfono 2", defaultText: "Teléfono 2 del Cliente" },
      { type: "customer_note", label: "Nota", defaultText: "Nota del Cliente" },
      { type: "customer_loyalty", label: "Loyalty", defaultText: "Loyalty Acumulado" },
      { type: "table_number", label: "Nro. de Mesa", defaultText: "Mesa: ###" },
    ],
  },
  {
    id: "transaction",
    label: "Transacción",
    items: [
      { type: "document_number", label: "Documento No.", defaultText: "######" },
      { type: "document_prefix", label: "Prefijo", defaultText: "######" },
      { type: "document_sufix", label: "Sufijo", defaultText: "######" },
      { type: "document_type", label: "Tipo de Documento", defaultText: "Tipo de Documento" },
      { type: "date", label: "Fecha y Hora", defaultText: "__TODAY__" },
      { type: "duedate", label: "Fecha de Vencimiento", defaultText: "__TODAY__" },
      { type: "discount", label: "Descuento", defaultText: "Descuento" },
      { type: "subtotal", label: "Subtotal", defaultText: "Subtotal" },
      { type: "tax_total", label: "Total __TAX__", defaultText: "Total __TAX__" },
      { type: "total", label: "Total", defaultText: "Total" },
      { type: "nums_to_words", label: "Total en letras", defaultText: "Total en letras" },
      { type: "sale_type", label: "Contado/Crédito texto", defaultText: "Contado/Crédito" },
      { type: "sale_type_contado", label: "✕ Venta al Contado", defaultText: "✕" },
      { type: "sale_type_credit", label: "✕ Venta a Crédito", defaultText: "✕" },
      { type: "payment_methods", label: "Métodos de pago", defaultText: "Métodos de pago" },
      { type: "tags", label: "Etiquetas", defaultText: "Etiquetas" },
      { type: "note", label: "Nota", defaultText: "Nota" },
      { type: "transaction_id", label: "ID de transacción", defaultText: "ID" },
      { type: "associated_document", label: "Documento Asociado", defaultText: "######" },
      // Remisión (context/42) — disponibles para cualquier documento, no
      // solo "delivery": es el comercio quien decide en qué plantilla los
      // usa (owner 2026-08-15). Sin dato en documentos que no son un
      // traslado (venta/factura/comanda/etc.), igual que cualquier otro
      // bloque con hueco de dato — se imprimen en blanco, no es un error.
      { type: "transfer_reason", label: "Motivo de Traslado", defaultText: "Motivo de Traslado" },
      { type: "transfer_origin", label: "Origen del Traslado", defaultText: "Origen" },
      { type: "transfer_destination", label: "Destino del Traslado", defaultText: "Destino" },
    ],
  },
  {
    id: "items",
    label: "Artículos",
    items: [
      { type: "item_receipt", label: "Listado de Venta", defaultText: "Listado de Venta", receiptOnly: true },
      { type: "item_receipt_4", label: "List. de Venta sin __TAX__", defaultText: "Listado de Venta Sin __TAX__", receiptOnly: true },
      { type: "item_receipt_2", label: "Listado sin Precios", defaultText: "Listado sin Precios", receiptOnly: true },
      { type: "item_receipt_3", label: "Listado Simple", defaultText: "Listado Simple", receiptOnly: true },
      { type: "item_units", label: "Cantidad", defaultText: "#####", receiptHidden: true },
      { type: "item", label: "Articulo", defaultText: "Producto", receiptHidden: true },
      { type: "item_id", label: "Código Interno", defaultText: "Código Interno", receiptHidden: true },
      { type: "item_note", label: "Nota", defaultText: "Nota del Artículo", receiptHidden: true },
      { type: "item_uid", label: "SKU", defaultText: "SKU", receiptHidden: true },
      { type: "item_tags", label: "Etiquetas", defaultText: "Etiquetas", receiptHidden: true },
      { type: "item_tax", label: "__TAX__ %", defaultText: "__TAX__ %", receiptHidden: true },
      // D4 (context/38): renombrado a snake_case — el alias de lectura de
      // plantillas guardadas con el string viejo vive en `normalizeBlockType`
      // (frontend/lib/hardware/printers/blocks.ts), no acá.
      { type: "item_tax_amount", label: "__TAX__", defaultText: "__TAX__", receiptHidden: true },
      // item_tax_amount_single: ya tenía resolver en blocks.ts (F3b) pero
      // faltaba en la paleta — sin esto el operador no podía agregarlo desde
      // el editor aunque el motor de impresión ya lo soportara.
      { type: "item_tax_amount_single", label: "__TAX__ (unidad)", defaultText: "__TAX__", receiptHidden: true },
      { type: "item_discount", label: "Descuento", defaultText: "##.###", receiptHidden: true },
      { type: "item_price", label: "Precio", defaultText: "Precio", receiptHidden: true },
      { type: "item_uni_price", label: "Precio de lista", defaultText: "Precio de lista", receiptHidden: true },
      { type: "item_price_notax", label: "Precio sin __TAX__", defaultText: "Precio sin __TAX__", receiptHidden: true },
      { type: "item_total", label: "Total", defaultText: "Total", receiptHidden: true },
    ],
  },
]

/**
 * F3c (context/38 §D): sección "Impuestos" — UNA entrada por tasa del
 * comercio (`subtotal_by_rate`/`iva_by_rate`/`item_total_by_rate`) + el pie
 * `iva_total`. A diferencia del resto de PALETTE (estático), esta sección es
 * función de `/v1/taxes` — no hay forma de tipar "una entrada por impuesto
 * del tenant" como catálogo fijo. `defaultText` guarda el `taxId`: es lo que
 * `handleAddBlock` (template-editor.tsx) copia a `block.text` al insertar el
 * bloque (mismo mecanismo que ya usaba `tax_single` para la tasa tipeada a
 * mano).
 */
export function buildTaxRateSection(taxes: Tax[]): PaletteSection {
  const items: PaletteItem[] = []
  for (const tax of taxes) {
    const rateLabel = tax.kind === "exempt" ? "Exento" : `${tax.rate ?? tax.name}%`
    items.push(
      { type: "subtotal_by_rate", label: `Subtotal __TAX__ ${rateLabel}`, defaultText: tax.id, receiptHidden: true },
      { type: "iva_by_rate", label: `__TAX__ ${rateLabel}`, defaultText: tax.id, receiptHidden: true },
      { type: "item_total_by_rate", label: `Total __TAX__ ${rateLabel}`, defaultText: tax.id, receiptHidden: true },
    )
  }
  items.push({ type: "iva_total", label: "Total __TAX__ (todas las tasas)", defaultText: "__TAX__" })
  return { id: "taxes", label: "Impuestos", items }
}

/** Filtra la paleta según paper size (oculta receiptOnly/receiptHidden donde
 *  corresponde) y agrega la sección "Impuestos" cuando llegaron las tasas
 *  del tenant (`taxes` viene de `useTaxes()`, puede tardar en cargar — sin
 *  eso, la sección simplemente no aparece, no rompe el render). */
export function filterPaletteForSize(paperSize: PaperSize, taxes: Tax[] = []): PaletteSection[] {
  const ticket = isReceipt(paperSize)
  const sections = taxes.length > 0 ? [...PALETTE, buildTaxRateSection(taxes)] : PALETTE
  return sections
    .map((sec) => ({
      ...sec,
      items: sec.items.filter((it) => (ticket ? !it.receiptHidden : !it.receiptOnly)),
    }))
    .filter((sec) => sec.items.length > 0)
}

/** Sustituciones de placeholders en labels de la UI (TIN/TAX configurables por país). */
export function substituteLabels(label: string, opts: { tin?: string; tax?: string }): string {
  return label
    .replace(/__TIN__/g, opts.tin ?? "RUC")
    .replace(/__TAX__/g, opts.tax ?? "IVA")
}

/** Catálogo de fuentes disponibles en el menú per-bloque (orden del legacy). */
export const FONT_FAMILIES = [
  "inherit",
  "Courier New",
  "Arial",
  "Times New Roman",
  "Comic Sans MS",
  "Trebuchet MS",
  "Verdana",
] as const

/** Catálogo de tamaños de fuente per-bloque (orden del legacy). */
export const FONT_SIZES = ["inherit", "8pt", "10pt", "12pt", "14pt", "16pt", "18pt", "20pt", "24pt", "30pt"] as const
