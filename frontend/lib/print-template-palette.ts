/**
 * Catálogo de bloques para la paleta del editor — VERBATIM del legacy
 * (panel/views/settings.html §panel.templates). Cada item replica el
 * `data-type` y `data-default` de los `<a class="addField">` del HTML.
 *
 * El POS imprime usando `ducumentPrintBuilder.js` (legacy en /panel/scripts),
 * que hace lookup por `type` para sustituir el texto por el dato real.
 */

import type { BlockType, PaperSize, PrintBlock } from "@/lib/types/print-template"
import { isReceipt } from "@/lib/types/print-template"
import type { Tax } from "@/lib/types/tax"

export interface PaletteItem {
  type: BlockType
  label: string
  defaultText: string
  /** Título sugerido para `block.label` al insertar. Solo lo usan los bloques
   *  por-tasa, que no pueden salir de `DEFAULT_BLOCK_LABELS` (el título lleva
   *  la tasa, que es dato del comercio). */
  defaultLabel?: string
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
      // Ya NO son `receiptHidden`: en el rollo la salida se arma sobre una
      // grilla de caracteres (roll-grid.ts), así que una línea horizontal es
      // una fila de guiones y una vertical una columna de barras — antes el
      // rollo era flujo lineal sin columnas y no había dónde ponerlas.
      { type: "hor_line", label: "Línea Horizontal", defaultText: "" },
      { type: "ver_line", label: "Línea Vertical", defaultText: "" },
    ],
  },
  {
    id: "company",
    label: "Empresa",
    items: [
      { type: "company_logo", label: "Logo", defaultText: "" },
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
      { type: "order_number", label: "Nro. de Comanda", defaultText: "###" },
      { type: "order_destination", label: "Destino de Comanda", defaultText: "Mesa 3" },
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
      { type: "item_discount", label: "Descuento (monto)", defaultText: "##.###", receiptHidden: true },
      // Bloque nuevo (bug de impresión — el viejo `item_discount` confundía
      // monto y % según qué builder armara el ticket, ver build-ticket-data.ts
      // TicketItem.discountAmount/discountPercent): el operador ahora elige
      // explícitamente cuál de los dos quiere en su plantilla.
      { type: "item_discount_percent", label: "Descuento (%)", defaultText: "##%", receiptHidden: true },
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
/**
 * Título sugerido al AGREGAR un bloque a la plantilla (`block.label`).
 *
 * Un backfill sobre las plantillas guardadas no alcanza: un bloque agregado
 * DESPUÉS nace sin título, y el operador tiene que escribirlo a mano uno por
 * uno — que es lo que reportó el owner ("muchas líneas del ticket siguen sin
 * título", 2026-08-28) después de que la migración corriera.
 *
 * Es un DEFAULT, no una regla: el título vive en la plantilla y el operador lo
 * edita o lo borra. Los que no están acá salen sin título a propósito — el
 * nombre del comercio, el logo o el pie se leen solos, y rotularlos es ruido.
 */
export const DEFAULT_BLOCK_LABELS: Partial<Record<BlockType, string>> = {
  // Transacción
  date: "Fecha:",
  duedate: "Vencimiento:",
  // `document_number` NO está acá a propósito: su título por defecto es
  // DINÁMICO por tipo de documento ("Factura Nro.:" / "Orden Nro.:" /
  // "Recibo Nro.:", ver DOC_NUMBER_LABELS en blocks.ts). Un título estático
  // acá lo pisaría para siempre.
  sale_type: "Condición:",
  payment_methods: "Formas de pago:",
  associated_document: "Documento asociado:",
  // Totales
  subtotal: "Subtotal:",
  discount: "Descuento:",
  tax_total: "Total IVA:",
  iva_total: "Total IVA:",
  total: "TOTAL A PAGAR:",
  nums_to_words: "Son:",
  // Caja / operador
  register_name: "Caja:",
  user_name: "Usuario:",
  // Timbrado
  auth_number: "Timbrado No.:",
  auth_start_date: "Válido desde:",
  auth_expiration: "Válido hasta:",
  // Cliente
  customer_name: "Cliente:",
  customer_full_name: "Cliente:",
  customer_tin: "R.U.C.:",
  customer_ci: "C.I.:",
  customer_address: "Dirección:",
  customer_phone: "Teléfono:",
  customer_email: "Email:",
  // Comanda / mesa
  order_number: "Orden Nro.:",
  order_destination: "Espacio:",
  table_number: "Espacio:",
  // Remisión
  transfer_reason: "Motivo:",
  transfer_origin: "Origen:",
  transfer_destination: "Destino:",
}

export function buildTaxRateSection(taxes: Tax[]): PaletteSection {
  const items: PaletteItem[] = []
  for (const tax of taxes) {
    const rateLabel = taxRateLabel(tax)
    items.push(
      // `defaultLabel`: el título del bloque en el ticket. Se arma con la tasa
      // porque es lo único que distingue una línea de otra ("Total IVA 10%:" vs
      // "Total IVA 5%:") — un mapa estático no puede saberlo, la tasa sale del
      // catálogo del comercio.
      { type: "subtotal_by_rate", label: `Subtotal __TAX__ ${rateLabel}`, defaultText: tax.id, defaultLabel: `Subtotal ${rateLabel}:`, receiptHidden: true },
      { type: "iva_by_rate", label: `__TAX__ ${rateLabel}`, defaultText: tax.id, defaultLabel: `IVA ${rateLabel}:`, receiptHidden: true },
      { type: "item_total_by_rate", label: `Total __TAX__ ${rateLabel}`, defaultText: tax.id, defaultLabel: `Total ${rateLabel}:`, receiptHidden: true },
      // Bloque de LÍNEA (los tres de arriba son agregados de documento): va en
      // la columna de su tasa dentro del cuerpo de la tabla, y cada ítem
      // imprime su monto solo en la columna que le toca. Es lo que arma la
      // factura de hoja paraguaya (Exentas | IVA 5% | IVA 10%).
      //
      // Sin `defaultLabel` a propósito: los bloques de ítem se repiten una vez
      // por producto y un título saldría en cada fila (misma regla que
      // documenta `PrintBlock.label`). El encabezado de la columna es un
      // bloque de texto aparte, como el resto de los encabezados.
      //
      // `receiptHidden`: en 33-48 columnas no entran tres columnas de tasa.
      { type: "item_total_if_rate", label: `Monto del ítem si es __TAX__ ${rateLabel}`, defaultText: tax.id, receiptHidden: true },
    )
  }
  items.push({ type: "iva_total", label: "Total __TAX__ (todas las tasas)", defaultText: "__TAX__" })
  return { id: "taxes", label: "Impuestos", items }
}

/**
 * Los bloques parametrizados por tasa — los que guardan un `taxId` del catálogo
 * en `block.text` en vez de un texto tipeado.
 *
 * Existe como SET y no como tres/cuatro condiciones repetidas porque la lista
 * se consultaba en dos lugares (`getBlockTitle`, `getBlockPlaceholder`) con la
 * misma cadena de `||`: al agregar `item_total_if_rate` (2026-08-29) había que
 * acordarse de tocar los dos, y el que se olvidara dejaba el bloque mostrando
 * su `type` crudo en el tooltip del canvas. Un tipo nuevo por-tasa se agrega
 * acá y las dos funciones lo toman solas.
 */
const TAX_RATE_BLOCK_TYPES: ReadonlySet<BlockType> = new Set<BlockType>([
  "subtotal_by_rate",
  "iva_by_rate",
  "item_total_by_rate",
  "item_total_if_rate",
])

/** "Exento" o "N%" para una tasa del tenant — mismo criterio en los dos
 *  lugares que arman un label con la tasa real: la sección "Impuestos" de
 *  la paleta (`buildTaxRateSection`) y el título de tooltip de un bloque
 *  por-tasa ya colocado (`getBlockTitle`). Un solo lugar evita que diverjan. */
function taxRateLabel(tax: Pick<Tax, "kind" | "rate" | "name">): string {
  return tax.kind === "exempt" ? "Exento" : `${tax.rate ?? tax.name}%`
}

/** Filtra la paleta según paper size (oculta receiptOnly/receiptHidden donde
 *  corresponde) y agrega la sección "Impuestos" cuando llegaron las tasas
 *  del tenant (`taxes` viene de `useTaxes()`, puede tardar en cargar — sin
 *  eso, la sección simplemente no aparece, no rompe el render). */
/**
 * Sección "Factura electrónica" — SOLO con el módulo `einvoicePy` activo
 * (pedido del owner 2026-08-29): en un comercio sin FE, un bloque de QR o de
 * CDC solo puede imprimir un hueco, así que ni se ofrece.
 *
 * `fe_py` ya existía como tipo (el renderer lo intercepta como QR) pero NUNCA
 * estuvo en la paleta: no había forma de agregarlo a una plantilla. El QR del
 * KuDE y el CDC llegan como dato de la VENTA (TicketData), no de la plantilla.
 */
function buildEInvoiceSection(): PaletteSection {
  return {
    id: "einvoice",
    label: "Factura electrónica",
    items: [
      { type: "fe_py", label: "QR de consulta (KuDE)", defaultText: "" },
      { type: "fe_cdc", label: "CDC", defaultText: "", defaultLabel: "CDC:" },
    ],
  }
}

export function filterPaletteForSize(
  paperSize: PaperSize,
  taxes: Tax[] = [],
  opts: { einvoiceEnabled?: boolean } = {},
): PaletteSection[] {
  const ticket = isReceipt(paperSize)
  const sections = [
    ...PALETTE,
    ...(taxes.length > 0 ? [buildTaxRateSection(taxes)] : []),
    ...(opts.einvoiceEnabled ? [buildEInvoiceSection()] : []),
  ]
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

/**
 * Mapa type → label del catálogo estático (PALETTE) — ÚNICA fuente de "cómo
 * se llama este bloque", reusada por los tooltips del canvas (canvas-block.tsx)
 * en vez de mantener una segunda lista de nombres que divergiría con el
 * tiempo (mismo criterio que `normalizeBlockType` en blocks.ts).
 *
 * INVARIANTE: cada `type` aparece UNA sola vez en PALETTE. Dos entradas con
 * el mismo `type` son indistinguibles una vez colocadas —`PrintBlock` guarda
 * el `type`, no de qué ítem de la paleta salió (ver print-template.ts)—, así
 * que ofrecerlas es prometer una elección que no existe. Fue el caso de
 * "Logo" / "Logo (B&W)", eliminado 2026-08-29: el blanco y negro del logo lo
 * decide el transporte del binding, no el bloque (ESC/POS siempre dithera a
 * B&W puro, ver `renderGraphic` en render-template.ts). El guard de abajo se
 * queda con la PRIMERA etiqueta por si el invariante se rompe.
 */
const BLOCK_TYPE_LABELS: Partial<Record<BlockType, string>> = (() => {
  const map: Partial<Record<BlockType, string>> = {}
  for (const section of PALETTE) {
    for (const item of section.items) {
      if (!(item.type in map)) map[item.type] = item.label
    }
  }
  return map
})()

/**
 * Título legible de un bloque colocado en el canvas — para tooltips
 * (canvas-block.tsx): el problema concreto del owner es "con varios bloques
 * ya no sé cuál es cuál", así que el tooltip necesita el nombre del
 * placeholder, no solo su valor de ejemplo.
 *
 * Reusa `BLOCK_TYPE_LABELS` de arriba. Los bloques por-tasa
 * (`subtotal_by_rate`/`iva_by_rate`/`item_total_by_rate`) no tienen una
 * entrada fija en PALETTE — dependen de qué tasa tenga el tenant, ver
 * `buildTaxRateSection` — así que se arma el mismo formato de label ahí,
 * buscando la tasa real por `block.text` (el `taxId` que el bloque guarda,
 * ver `PrintBlock`/`BlockType` en print-template.ts). Bloques legacy que ya
 * no viven en la paleta (ej. `tax_single`, agregado hoy solo desde
 * plantillas viejas) caen al `type` crudo — no vale la pena mantener una
 * entrada de catálogo para un tipo que ya no se puede agregar desde la UI.
 */
export function getBlockTitle(
  block: PrintBlock,
  taxes: Tax[] = [],
  opts: { tin?: string; tax?: string } = {},
): string {
  if (TAX_RATE_BLOCK_TYPES.has(block.type)) {
    const tax = taxes.find((t) => t.id === block.text)
    const rateLabel = tax ? taxRateLabel(tax) : "?"
    const prefix =
      block.type === "subtotal_by_rate"
        ? "Subtotal __TAX__"
        : block.type === "iva_by_rate"
          ? "__TAX__"
          : block.type === "item_total_if_rate"
            ? "Monto del ítem si es __TAX__"
            : "Total __TAX__"
    return substituteLabels(`${prefix} ${rateLabel}`, opts)
  }
  const label = BLOCK_TYPE_LABELS[block.type] ?? block.type
  return substituteLabels(label, opts)
}

/**
 * Mapa type → `defaultText` del catálogo estático — el "molde" del dato
 * (`"######"`, `"Mesa: ###"`, `"__TIN__"`) que ya vive en PALETTE para
 * pre-llenar `block.text` al insertar el bloque (`handleAddBlock`,
 * template-editor.tsx). Mismo criterio de "primera entrada gana" que
 * `BLOCK_TYPE_LABELS` — reusa el catálogo en vez de mantener una segunda
 * lista, ver `getBlockPlaceholder` abajo.
 */
const BLOCK_TYPE_DEFAULT_TEXT: Partial<Record<BlockType, string>> = (() => {
  const map: Partial<Record<BlockType, string>> = {}
  for (const section of PALETTE) {
    for (const item of section.items) {
      // Primera entrada gana, SIN mirar si trae texto — igual que
      // BLOCK_TYPE_LABELS arriba. Si la primera entrada tiene
      // `defaultText: ""` (ej. company_name, que nunca necesitó molde:
      // siempre resuelve real), `getBlockPlaceholder` ya cae a
      // `getBlockTitle` cuando el valor da falsy — no hace falta que este
      // mapa "busque" una segunda entrada con texto.
      if (!(item.type in map)) map[item.type] = item.defaultText
    }
  }
  return map
})()

/**
 * Texto a mostrar en el canvas cuando `resolveSingleBlockPreview` (blocks.ts)
 * NO devuelve nada para este bloque contra `buildDemoTicketData()` — un
 * campo que la venta de ejemplo no puebla (`transfer_reason` fuera de
 * remisión, `table_number` fuera de comanda), un cálculo no implementado
 * (`nums_to_words`) o una tasa del tenant sin línea de ejemplo que la
 * matchee (`subtotal_by_rate`/`iva_by_rate`/`item_total_by_rate` para un
 * impuesto "Exento" — `buildDemoTicketData` solo arma ítems con las dos
 * tasas "rate" más altas, ver su comentario).
 *
 * Pedido del owner: "nunca vacío, nunca un identificador interno" — así que
 * ACÁ NO se repite el bug que ya se corrigió en canvas-block.tsx (pintar
 * `block.text` crudo, que en los bloques por-tasa es el `taxId`, un UUID).
 * Para esos tres tipos `defaultText` en PALETTE **es** ese mismo UUID (es
 * metadato para `handleAddBlock`, no una etiqueta — ver `buildTaxRateSection`
 * arriba), así que ahí se cae a `getBlockTitle` (ej. "Subtotal IVA 10%"),
 * igual que en el resto del catálogo cuando no hay `defaultText` legible
 * (ej. `company_name`, que en PALETTE tiene `defaultText: ""` porque nunca
 * necesitó un molde — siempre resuelve real, así que este fallback no llega
 * a usarse ahí en la práctica).
 */
export function getBlockPlaceholder(
  block: PrintBlock,
  taxes: Tax[] = [],
  opts: { tin?: string; tax?: string } = {},
): string {
  if (TAX_RATE_BLOCK_TYPES.has(block.type)) {
    return getBlockTitle(block, taxes, opts)
  }
  const text = BLOCK_TYPE_DEFAULT_TEXT[block.type]
  return substituteLabels(text || getBlockTitle(block, taxes, opts), opts)
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
