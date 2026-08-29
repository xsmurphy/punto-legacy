/**
 * Tipos del editor de plantillas de impresión.
 *
 * El JSON persistido respeta EXACTAMENTE el shape del legacy para que el motor
 * de impresión `ducumentPrintBuilder.js` del POS lo consuma sin cambios.
 */

// ── Paper size ──────────────────────────────────────────────────────────────
// IDs IDÉNTICOS al legacy (a_settings_templates.js usa estos strings).
export type PaperSize =
  | "a4page"
  | "a4page-h"
  | "legalpage"
  | "legalpage-h"
  | "letterpage"
  | "letterpage-h"
  | "receipt80"
  | "receipt76"
  | "receipt57"

/** Dimensiones del papel en mm. Para los receipt el alto es virtual (rollo). */
export const PAPER_DIMENSIONS: Record<PaperSize, { widthMm: number; heightMm: number; label: string }> = {
  "a4page":       { widthMm: 210,   heightMm: 297,   label: "A4 (Vertical)" },
  "a4page-h":     { widthMm: 297,   heightMm: 210,   label: "A4 (Horizontal)" },
  "legalpage":    { widthMm: 215.9, heightMm: 355.6, label: "Legal (Vertical)" },
  "legalpage-h":  { widthMm: 355.6, heightMm: 215.9, label: "Legal (Horizontal)" },
  "letterpage":   { widthMm: 215.9, heightMm: 279.4, label: "Carta (Vertical)" },
  "letterpage-h": { widthMm: 279.4, heightMm: 215.9, label: "Carta (Horizontal)" },
  "receipt80":    { widthMm: 80,    heightMm: 279,   label: "Roll 80mm" },
  "receipt76":    { widthMm: 76,    heightMm: 279,   label: "Roll 76mm" },
  "receipt57":    { widthMm: 57,    heightMm: 279,   label: "Roll 57mm" },
}

export function isReceipt(size: PaperSize): boolean {
  return size === "receipt80" || size === "receipt76" || size === "receipt57"
}

// ── Mapeo backend ↔ legacy ───────────────────────────────────────────────────
// El backend (DocumentTemplateService) acepta solo este enum reducido:
//   '57mm' | '76mm' | '80mm' | 'A4' | 'A4-landscape' | 'letter' | 'legal'
// El editor usa los IDs del legacy. Estas funciones puentean.
export const BACKEND_PAGE_SIZES = ["57mm", "76mm", "80mm", "A4", "A4-landscape", "letter", "legal"] as const
export type BackendPageSize = typeof BACKEND_PAGE_SIZES[number]

export function paperSizeToBackend(s: PaperSize): BackendPageSize {
  switch (s) {
    case "receipt57":    return "57mm"
    case "receipt76":    return "76mm"
    case "receipt80":    return "80mm"
    case "a4page":       return "A4"
    case "a4page-h":     return "A4-landscape"
    case "letterpage":
    case "letterpage-h": return "letter"
    case "legalpage":
    case "legalpage-h":  return "legal"
  }
}

// ── Block ───────────────────────────────────────────────────────────────────

/**
 * Catálogo de tipos de bloque tipados — IDs IDÉNTICOS al legacy.
 * Cada uno representa un placeholder dinámico que el motor de impresión
 * sustituye por datos reales al imprimir un comprobante.
 *
 * `custom`, `hor_line`, `ver_line` son los únicos sin sustitución (texto/línea
 * estática).
 */
export type BlockType =
  // Herramientas
  | "custom" | "hor_line" | "ver_line"
  // Empresa
  | "company_logo" | "company_name" | "company_billing_name" | "company_tin"
  | "company_address" | "company_email" | "company_phone" | "company_website"
  // Sucursal
  | "outlet_name" | "outlet_billing_name" | "outlet_tin" | "outlet_address"
  | "outlet_phone"
  // Caja / usuario
  | "register_name" | "printer_name" | "user_name"
  // Timbrado
  | "auth_number" | "auth_start_date" | "auth_expiration"
  // Cliente
  | "customer_name" | "customer_full_name" | "customer_tin" | "customer_ci"
  | "customer_address" | "customer_address_2" | "customer_location"
  | "customer_city" | "customer_country" | "customer_phone" | "customer_phone_2"
  | "customer_note" | "customer_loyalty" | "customer_birthday" | "customer_email"
  | "table_number"
  // Comanda (docType "order"). Existen porque el banner "COMANDA #12 · MESA 3"
  // lo INYECTABAN los renderers cuando `docType === "order"`, sin que la
  // plantilla lo pidiera — al revés de la regla del owner ("lo que se imprime
  // lo decide la plantilla"). Al sacar esa inyección, el dato tiene que poder
  // ponerse como bloque, si no la comanda se quedaba sin encabezado.
  | "order_number" | "order_destination"
  // Transacción
  | "document_number" | "document_prefix" | "document_sufix" | "document_type"
  | "date" | "duedate" | "discount" | "subtotal" | "tax_total" | "total"
  | "nums_to_words" | "sale_type" | "sale_type_contado" | "sale_type_credit"
  | "payment_methods" | "tags" | "note"
  | "transaction_id" | "transaction_id_barcode" | "associated_document"
  | "fe_py"
  // CDC del documento electrónico (SIFEN). Nace asíncrono: en el primer ticket
  // puede no existir todavía y el bloque sale en blanco; en la reimpresión ya
  // está. Solo aparece en la paleta con el módulo `einvoicePy` activo.
  | "fe_cdc"
  // Artículos
  | "item_receipt" | "item_receipt_2" | "item_receipt_3" | "item_receipt_4"
  | "item_units" | "item" | "item_id" | "item_note" | "item_uid"
  // D4 (context/38): renombre snake_case de los legacy `item_taxAmount` /
  // `item_taxAmount_single`. El string viejo NO desaparece del universo de
  // datos — persiste en `config.data` de plantillas ya guardadas — pero deja
  // de ser un `BlockType` válido a partir de acá: `normalizeBlockType`
  // (frontend/lib/hardware/printers/blocks.ts) es el ÚNICO lugar que traduce
  // el string legacy al canónico, al leer, sin migración de datos.
  | "item_tags" | "item_tax" | "item_tax_amount" | "item_tax_amount_single"
  // item_discount = MONTO del descuento de línea; item_discount_percent = %
  // efectivo. Antes un solo bloque ambiguo (bug de impresión — ver
  // TicketItem.discountAmount/discountPercent en build-ticket-data.ts):
  // 2 de los 3 builders llenaban `item.discount` con el % en vez de la
  // plata, así que el mismo ticket imprimía distinto según cuándo se
  // imprimiera. `item_discount` NO se renombra (plantillas guardadas ya lo
  // usan con el significado "monto" — ver defaultText "##.###" en
  // print-template-palette.ts) — se agrega el bloque nuevo para el %.
  | "item_discount" | "item_discount_percent" | "item_price" | "item_uni_price" | "item_price_notax"
  | "item_total" | "item_subtotal" | "tax_single"
  // F3c (context/38 §D): bloques parametrizados por tasa. El bloque guarda
  // el `taxId` del tenant en `block.text` (mismo mecanismo que ya usaba el
  // legacy `tax_single` para guardar la tasa tipeada a mano) — sobrevive un
  // rename del impuesto; si guardara `rate` una plantilla vieja seguiría
  // apuntando a un valor que el comercio pudo borrar/reemplazar.
  | "item_total_by_rate" | "subtotal_by_rate" | "iva_by_rate" | "iva_total"
  // `item_total_if_rate` es la versión POR LÍNEA de la familia de arriba, y la
  // pieza que arma la factura de hoja paraguaya clásica:
  //
  //   Cant. | Descripción | Precio unit. | Exentas | IVA 5% | IVA 10% | TOTAL
  //
  // Tres instancias del MISMO bloque con distinto `taxId` en `block.text`, una
  // por columna: cada ítem imprime su monto en la columna de SU tasa y deja
  // las otras dos en blanco. Los `*_by_rate` de arriba no sirven para esto —
  // son agregados de documento, se imprimen una sola vez con la suma de todo
  // el bucket. Poner un `item_total` en la columna del 10% "funciona" solo
  // mientras todos los ítems compartan tasa (bug reportado 2026-08-29).
  | "item_total_if_rate"
  // Remisión (context/42): motivo/origen/destino del traslado. Resuelven
  // TicketData.transferReason/originLabel/destinationLabel — null en
  // cualquier docType que no los pueble (build-ticket-data.ts), el bloque
  // queda en blanco como cualquier otro sin dato. Disponibles en la paleta
  // para TODO docType, sin gating — el comercio decide en qué plantilla los
  // usa (criterio del owner 2026-08-15: el constructor de documentos no
  // restringe qué bloque va en qué documento).
  | "transfer_reason" | "transfer_origin" | "transfer_destination"

export type TextAlign = "left" | "center" | "right"
export type TextWrap = "cut" | "wrap"
export type FontWeight = "normal" | "bold"

/**
 * Shape EXACTO al de los items dentro de `data: []` en el JSON del legacy.
 * Coordenadas en px (el motor las convierte a mm con `mm` ratio).
 */
export interface PrintBlock {
  type: BlockType
  text: string
  /**
   * Título que precede al valor al imprimir: "Fecha: 12-03-2026".
   *
   * Vacío o ausente = solo el valor, que es como se comportaban TODOS los
   * bloques hasta 2026-08-26 (por eso es opcional: las plantillas guardadas no
   * lo traen y tienen que seguir imprimiendo igual).
   *
   * Es una propiedad de la PLANTILLA y no una tabla de títulos en el renderer:
   * la regla del proyecto es que lo que se imprime lo decide la plantilla
   * (context/20). Un mismo bloque `date` puede querer "Fecha:" en la factura,
   * "Emitido:" en el remito y nada en la comanda — eso no lo puede saber el
   * código, y hardcodearlo obligaría a un gating por docType, que es
   * exactamente lo que la regla prohíbe.
   *
   * La puntuación va incluida en el texto que escribe el operador ("Fecha:",
   * no "Fecha"): el sistema no impone ni dos puntos ni mayúsculas, solo pone
   * un espacio entre el título y el valor.
   *
   * NO aplica a los bloques de ítem (`item_*`): esos se repiten una vez por
   * producto y el título quedaría en cada línea.
   */
  label?: string
  top: number
  left: number
  width: number
  height: number
  size: string         // 'inherit' o '8pt'...'30pt'
  family: string       // 'inherit' o 'Arial' / 'Courier New' / etc
  align: TextAlign
  bold: FontWeight
  textwrap: TextWrap
  url: string          // sólo para company_logo
}

/**
 * JSON canónico de la plantilla — EXACTO al shape que el legacy guarda y que
 * `ducumentPrintBuilder.build(arr,sale,conf)` consume. Guardado en
 * `document_template.config` (JSONB).
 */
export interface PrintTemplateConfig {
  page_size: PaperSize
  page_size_name: string
  page_name: string
  page_font_family: string
  page_font_size: string
  page_font_case: "none" | "uppercase"
  receipt_left_margin: string
  /** ratio px → mm, calculado en client con un sentinel div de 1mm */
  mm: number
  data: PrintBlock[]
}

export function defaultTemplateConfig(paperSize: PaperSize = "a4page"): PrintTemplateConfig {
  return {
    page_size: paperSize,
    page_size_name: `Formato: ${PAPER_DIMENSIONS[paperSize].label}`,
    page_name: "",
    page_font_family: "Arial",
    page_font_size: "8pt",
    page_font_case: "none",
    receipt_left_margin: "7",
    mm: 3.78, // ≈ 96 DPI, recalculado al montar
    data: [],
  }
}

export function defaultBlock(type: BlockType, defaultText = ""): PrintBlock {
  return {
    type,
    text: defaultText,
    label: "",
    top: 0,
    left: 0,
    width: 100,
    height: 24,
    size: "inherit",
    family: "inherit",
    align: "left",
    bold: "normal",
    textwrap: "cut",
    url: "",
  }
}

/**
 * Ningún bloque puede quedar con una parte fuera del área imprimible del
 * papel — pedido owner (2026-08-18, screenshot con bloques cruzando el borde
 * derecho de la hoja): "los bloques tienen que adaptarse al ancho del canvas
 * (papel)". `widthPx`/`heightPx` tienen que salir de la MISMA cuenta que ya
 * usa `moveBlocksBy` en template-editor.tsx (`PAPER_DIMENSIONS[size].xMm *
 * mm`, el mismo `mm` que el renderer usa para el `@page` físico real — commit
 * `4f7090cf`) — nunca un número inventado, para que "cabe en el canvas" y
 * "cabe en el papel impreso" sean la MISMA cuenta.
 *
 * Es un CLAMP, no un RESCALE: nunca reduce proporcionalmente ni reacomoda el
 * diseño para que "quepa lindo" — eso podría destruir un layout armado a
 * mano y es una decisión de producto que no toca este helper (ver
 * `canvas-block.tsx`, que es el único caller hoy: corrige un bloque que ya
 * quedó fuera de rango porque LA PLANTILLA SE DISEÑÓ PARA OTRO TAMAÑO DE
 * PAPEL y después alguien cambió `page_size`, caso que ni la creación de
 * bloques ni `moveBlocksBy` cubren — ninguno de los dos se dispara solo
 * porque cambió el papel). Devuelve `null` cuando el bloque YA cabe (para
 * que el caller no dispare un `onChange` de más).
 */
export function clampBlockToPaper(
  block: Pick<PrintBlock, "left" | "top" | "width" | "height">,
  widthPx: number,
  heightPx: number,
): { left: number; top: number; width: number; height: number } | null {
  // SIN Math.round acá — `moveBlocksBy` (template-editor.tsx) clampea left/top
  // contra `widthPx`/`heightPx` en crudo (`Math.max(0, widthPx - b.width)`);
  // si este helper redondeara y el otro no, un bloque arrastrado justo al
  // borde por moveBlocksBy podía disparar una segunda corrección de este
  // efecto por medio píxel de diferencia entre los dos criterios. Mismo
  // número, mismas cuentas, en los dos lugares.
  const maxW = Math.max(1, widthPx)
  const maxH = Math.max(1, heightPx)
  const width = Math.min(block.width, maxW)
  const height = Math.min(block.height, maxH)
  const left = Math.min(Math.max(0, block.left), Math.max(0, maxW - width))
  const top = Math.min(Math.max(0, block.top), Math.max(0, maxH - height))
  if (left === block.left && top === block.top && width === block.width && height === block.height) {
    return null
  }
  return { left, top, width, height }
}

/**
 * Ancho/alto mínimo utilizable de un bloque — por debajo de esto es
 * imposible agarrarlo con el mouse en el canvas (pedido owner 2026-08-18,
 * sobre el ancho por-contenido en modo papel: "pero sí debe tener un
 * mínimo, por ejemplo 20px"). Fuente única para el `minWidth`/`minHeight`
 * de react-rnd (canvas-block.tsx) y el ancho por-contenido al crear un
 * bloque nuevo en papel (`handleAddBlock`, template-editor.tsx) — mismo
 * número en los dos lugares, mismo criterio que ya documenta
 * `clampBlockToPaper` sobre no inventar cuentas paralelas. El alto mínimo
 * no lo pidió el owner explícitamente, pero aplica el mismo motivo (un
 * bloque de 2px de alto es igual de inagarrable) y el alto SIEMPRE es
 * resizeable, en ticket y en papel.
 */
export const MIN_BLOCK_SIZE = 20

/**
 * Ticket: el bloque SIEMPRE ocupa el 100% del ancho del papel — regla owner
 * 2026-08-18 ("cada bloque en modo ticket debe tener siempre el 100% del
 * ancho del ticket y no se puede resize el ancho, solo la altura"). A
 * diferencia de `clampBlockToPaper`, que solo CORRIGE cuando algo se pasa de
 * los bordes, acá el ancho/left se FUERZAN siempre al mismo valor: en modo
 * ticket el resize horizontal está deshabilitado (`enableResizing` en
 * canvas-block.tsx), así que un bloque angosto — heredado de una plantilla
 * diseñada para otro papel, o de un cambio de `page_size` — no tiene
 * NINGUNA forma de corregirse a mano. Si este helper solo clampeara (como
 * `clampBlockToPaper`), ese bloque quedaría angosto para siempre. El
 * alto/top SÍ solo se clampean, no se fuerzan: ese eje sigue editable con
 * el resize vertical, igual criterio que `clampBlockToPaper`.
 *
 * Aplica en memoria al renderizar (mismo patrón que `clampBlockToPaper`) —
 * no hay autoguardado, así que una plantilla de ticket ya diseñada con
 * bloques angostos se ve corregida en el canvas pero el owner decide si
 * guarda ese resultado.
 */
export function applyReceiptWidthRule(
  block: Pick<PrintBlock, "left" | "top" | "width" | "height">,
  widthPx: number,
  heightPx: number,
): { left: number; top: number; width: number; height: number } | null {
  const maxW = Math.max(1, widthPx)
  const maxH = Math.max(1, heightPx)
  const width = maxW
  const left = 0
  const height = Math.min(block.height, maxH)
  const top = Math.min(Math.max(0, block.top), Math.max(0, maxH - height))
  if (left === block.left && top === block.top && width === block.width && height === block.height) {
    return null
  }
  return { left, top, width, height }
}

/**
 * Forma canónica de una plantilla para comparar "¿esto cambió?" — la usa el
 * guard de cambios sin guardar del editor (`useUnsavedChangesGuard`,
 * hooks/use-unsaved-changes-guard.ts).
 *
 * NO se puede comparar el config crudo contra el que vino del backend: al
 * abrir el editor pasan dos cosas que el usuario no hizo y que igual mueven
 * el objeto.
 *
 *  1. `mm` se re-mide con el sentinel de 1mm del browser actual
 *     (`template-editor.tsx`), así que casi siempre difiere del valor
 *     guardado — es una propiedad del DISPOSITIVO que abre el editor, no del
 *     diseño. Por eso se excluye de la comparación.
 *
 *  2. Los bloques se auto-corrigen contra el papel apenas se montan
 *     (`clampBlockToPaper` / `applyReceiptWidthRule`, disparados desde el
 *     efecto de `canvas-block.tsx`) — una plantilla vieja diseñada para otro
 *     tamaño de papel se acomoda sola. Acá se aplican las MISMAS dos reglas a
 *     los dos lados de la comparación, así que ese acomodo no cuenta como
 *     edición.
 *
 * Sin esto el guard preguntaría "¿hay cambios sin guardar?" al abrir y salir
 * sin tocar nada, que es justo el falso positivo que entrena al usuario a
 * ignorar el aviso.
 *
 * `mm` entra por parámetro (no se lee de `config.mm`) para que los dos lados
 * se normalicen con la MISMA escala: si el baseline usara su `mm` guardado y
 * el actual el medido, los clamps darían anchos distintos por redondeo y todo
 * volvería a marcarse como sucio.
 */
export function canonicalizeTemplateForCompare(config: PrintTemplateConfig, mm: number): string {
  const dim = PAPER_DIMENSIONS[config.page_size] ?? PAPER_DIMENSIONS.a4page
  const ratio = mm > 0 ? mm : 3.78
  const widthPx = dim.widthMm * ratio
  const heightPx = dim.heightMm * ratio
  const ticket = isReceipt(config.page_size)

  // Orden de claves FIJO, no spread del bloque original: los bloques del
  // backend vienen de un JSONB y los recién creados de `defaultBlock`, así que
  // el orden de claves puede diferir aunque el contenido sea idéntico —
  // `JSON.stringify` lo notaría y marcaría un "sucio" fantasma.
  const data = (config.data ?? []).map((b) => {
    const fix =
      (ticket ? applyReceiptWidthRule(b, widthPx, heightPx) : clampBlockToPaper(b, widthPx, heightPx)) ?? b
    return {
      type: b.type,
      text: b.text,
      // Se serializa solo si tiene contenido: una plantilla sin títulos no
      // gana claves vacías en su JSON.
      ...(b.label ? { label: b.label } : {}),
      top: fix.top,
      left: fix.left,
      width: fix.width,
      height: fix.height,
      size: b.size,
      family: b.family,
      align: b.align,
      bold: b.bold,
      textwrap: b.textwrap,
      url: b.url,
    }
  })

  // `mm` fuera (es del dispositivo, no del diseño); el resto entra tal cual.
  // Se enumeran los campos en vez de hacer spread para que la comparación sea
  // estable: `JSON.stringify` respeta el orden de inserción de las claves, y
  // dos objetos con las mismas claves en distinto orden darían strings
  // distintos y un "sucio" fantasma.
  return JSON.stringify({
    page_size: config.page_size,
    page_size_name: config.page_size_name,
    page_name: config.page_name,
    page_font_family: config.page_font_family,
    page_font_size: config.page_font_size,
    page_font_case: config.page_font_case,
    receipt_left_margin: config.receipt_left_margin,
    data,
  })
}

// ── Backend row shape (lo que devuelve /v1/document-templates) ──────────────

export interface DocumentTemplateRow {
  templateId: string
  name: string
  docType: "receipt" | "invoice" | "quote" | "workorder" | "giftcard" | "delivery"
  pageSize: BackendPageSize
  isDefault: boolean
  config: Partial<PrintTemplateConfig> & Record<string, unknown>
  created_at: string | null
  updated_at: string | null
}
