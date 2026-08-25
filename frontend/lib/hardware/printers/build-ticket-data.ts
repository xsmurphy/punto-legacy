import type { CreateSalePayload, CreateSaleResult } from "@/lib/commands/create-sale"
import { buildSalePayload } from "@/lib/commands/create-sale"
import type { PosConfig } from "@/lib/types/pos-bootstrap"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { computeTaxes, type TaxKind } from "@/lib/tax/engine"
import type { Tax } from "@/lib/types/tax"
// Formateador único del correlativo (mig 159) — ver
// `lib/documents/format-document-number.ts`. Prohibido `padStart` suelto acá.
import { padDocumentNumber } from "@/lib/documents/format-document-number"

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
  // ── Moneda / formato de números del tenant (PosConfig.currency/thousand/
  // decimal, `/api/pos/bootstrap` ← `/v1/bootstrap`) — de acá sale el
  // `formatMoney` del ticket (blocks.ts), NUNCA de un locale hardcodeado.
  // `null`/`undefined` en los builders que no reciben `config` (remisión,
  // traslado de stock, cierre de caja): esos documentos no imprimen montos
  // reales de todos modos, y `formatMoney` cae al mismo default que ya usa
  // el resto de la app (lib/format-money.ts), no a un hardcode nuevo de
  // impresión.
  currency?: string | null
  thousand?: "comma" | "dot" | null
  decimal?: string | null
  // País del tenant (PosConfig.country). No se imprime: es el que le permite a
  // `resolveCurrencyLabel` caer a la moneda del PAÍS cuando el tenant no
  // configuró el símbolo, en vez de asumir guaraníes.
  country?: string | null
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
  // ── Remisión (context/42) — resuelven los bloques `transfer_reason`/
  // `transfer_origin`/`transfer_destination` (blocks.ts). Pobladas por
  // `buildTicketDataFromStockTransfer` (traslado entre depósitos propios) y
  // `buildTicketDataFromRemision` (venta/devolución a proveedor/consignación/
  // exposición/compra) — los dos "orígenes" de datos de una nota de
  // remisión, ver docblock de la mig 137. `null` en cualquier otro docType:
  // el bloque queda en blanco, no es un dato "no disponible" sino "no
  // aplica" para ese documento — el comercio decide si lo agrega a su
  // plantilla de factura/comanda igual (owner: "es problema del cliente").
  /** Label de `RemisionMotivo` (o "Transferencia entre depósitos" para stock_transfer). */
  transferReason?: string | null
  /** Sucursal [→ depósito] de origen del traslado. */
  originLabel?: string | null
  /** Sucursal [→ depósito] destino, o el contacto/nota libre cuando el
   *  destino no es un outlet propio (ver `document_remision.destinationnote`). */
  destinationLabel?: string | null
}

export interface TicketItem {
  name: string
  qty: number
  unitPrice: number
  /**
   * Descuento de la línea — DOS datos distintos, nunca uno solo (hallazgo del
   * bug de impresión: `item_discount` imprimía "Gs. 7" en vez de "Gs. 1.000"
   * porque 2 de los 3 builders llenaban este campo con el % en vez de la
   * plata). Cada builder puebla los dos con el MISMO criterio; cuál se
   * imprime lo decide la plantilla del comercio, vía dos bloques distintos
   * (`item_discount` = monto, `item_discount_percent` = %, ver blocks.ts).
   */
  /** Plata del descuento de la línea — `SaleItem.totalDiscount` /
   *  `itemSold.itemSoldDiscount`. Imprime `item_discount`. */
  discountAmount: number
  /** Porcentaje EFECTIVO de descuento de la línea (propio + prorrateo del
   *  descuento de venta) — `SaleItem.discount` (allocateLineDiscounts). NUNCA
   *  confundir con `discountAmount` aunque ambos midan "el descuento": uno es
   *  plata, el otro %. Imprime `item_discount_percent`. */
  discountPercent: number
  total: number
  categoryId: string | null
  /** UUID del ítem de catálogo. `null` si la línea no está atada a un ítem
   *  (ej. línea armada a mano). */
  id: string | null
  /** SKU/código interno del ítem — `PosItem.sku` o `TransactionDataItem.sku`.
   *  `null` cuando la fuente no lo trae (panel, sin catálogo POS hidratado). */
  uid: string | null
  /** Nota de línea. `null` si la fuente no la modela (panel). */
  note: string | null
  /**
   * Etiquetas de línea (uso interno — pedido owner 2026-08-14, ver
   * `CartLine.tags`). Pobladas por `buildTicketData` (recién cobrada, desde
   * `payload.sale[i].tags`) y `buildTicketItemsFromTransaction` (reimpresión,
   * desde `transactionDatas[i].tags` — decode de `itemSold`/`meta.
   * transactionDetails`). `buildTicketDataFromTxDetail` (panel, TxDetailFull)
   * sigue en `null`: ese endpoint no expone tags por ítem, mismo límite que
   * `note` ahí. El bloque `item_tags` (blocks.ts) las imprime en la comanda
   * si la plantilla del binding lo trae — el que NO deben salir en facturas
   * depende de que el comercio no agregue ese bloque a su plantilla fiscal,
   * no hay una restricción estructural por docType hoy.
   */
  tags: string[] | null
  /**
   * La línea es un add-on de la línea inmediatamente anterior (F5, D3 de
   * context/41), no un ítem suelto: se imprime indentada y con "+" delante.
   *
   * Es presentación pura — el renglón sigue siendo una línea de venta normal
   * (tiene su propio itemId, su impuesto congelado y su descuento de stock,
   * ver F3). La indentación la aplica el resolver `item` de blocks.ts, que es
   * el único punto donde el nombre se convierte en texto para ambos renderers
   * (ESC/POS y HTML).
   */
  isAddonChild?: boolean
  // ── Fiscal (F3b, context/38) — congelado server-side (F2a) cuando la
  // fuente es una transacción persistida; calculado con el motor real
  // (lib/tax/engine.ts) cuando la fuente es el carrito pre-venta. `null`
  // cuando el builder no tiene de dónde sacarlo (ver comentario en cada uno).
  taxId: string | null
  /** Porcentaje (ej. 10). Irrelevante si `taxKind === "exempt"`. */
  taxRate: number | null
  taxKind: TaxKind | null
  taxIncluded: boolean | null
  /** Impuesto de la línea COMPLETA (todas las unidades). */
  taxAmount: number | null
  /** Neto de la línea (sin impuesto). */
  taxNet: number | null
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

  // Impuesto por línea: mismo motor y misma fuente que `selectCartIva`
  // (lib/cart/store.ts) — tasa real del catálogo (`taxes` por `taxId`), modo
  // incluido/añadido del ítem o default de la sucursal. `ivaRemoved` corre
  // como exenta (mismo criterio que SaleService::enrichWithTaxes) en vez de
  // recalcular con tasa 0 — es la venta completa la que no devenga IVA, no
  // que cada línea tenga tasa 0%.
  const taxesById = new Map(state.taxes.map((t) => [t.id, t]))
  const decimals = config?.decimal === "yes" ? 2 : 0

  const engineLines = payload.sale.map((s) => {
    const catalogItem = state.items.find((i) => i.id === s.itemId)
    const tax = !payload.ivaRemoved && catalogItem?.taxId ? taxesById.get(catalogItem.taxId) : undefined
    return {
      catalogItem,
      taxId: payload.ivaRemoved ? null : catalogItem?.taxId ?? null,
      taxRate: payload.ivaRemoved ? 0 : tax?.rate ?? 0,
      taxKind: (payload.ivaRemoved ? "exempt" : tax?.kind ?? "exempt") as TaxKind,
      taxIncluded: catalogItem?.taxIncluded ?? state.outletTaxIncluded,
      qty: s.count,
      unitPrice: s.price,
      discount: s.totalDiscount,
    }
  })
  const taxResult = computeTaxes(
    engineLines.map((l) => ({
      qty: l.qty,
      unitPrice: l.unitPrice,
      discount: l.discount,
      taxRate: l.taxRate,
      taxKind: l.taxKind,
      taxIncluded: l.taxIncluded,
    })),
    { decimals },
  )

  const items: TicketItem[] = payload.sale.map((s, i) => {
    const line = engineLines[i]
    const lineResult = taxResult.lines[i]
    return {
      name: s.name,
      qty: s.count,
      unitPrice: s.price,
      // s.discount = % efectivo, s.totalDiscount = plata (create-sale.ts) —
      // ambos ya vienen calculados en el payload, sin recalcular acá.
      discountAmount: s.totalDiscount,
      discountPercent: s.discount,
      total: s.total,
      categoryId: line.catalogItem?.categoryId ?? null,
      id: s.itemId,
      uid: line.catalogItem?.sku ?? null,
      note: s.note,
      // Etiquetas de línea (pedido owner 2026-08-14) — vienen directo del
      // payload que se acaba de mandar (ticket recién cobrado, sin volver a
      // consultar el backend).
      tags: s.tags ?? null,
      taxId: line.taxId,
      taxRate: line.taxRate,
      taxKind: line.taxKind,
      taxIncluded: line.taxIncluded,
      taxAmount: lineResult.tax,
      taxNet: lineResult.net,
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
    // Número de comprobante ya consumido del lease (online y offline, misma
    // fuente — ver CreateSalePayload.invoiceno). Antes esta key faltaba en
    // el return: el bloque `document_number` de la plantilla (blocks.ts)
    // renderizaba `data.documentNumber ?? null` → nunca imprimía nada, ni
    // siquiera en la venta offline donde SÍ había un número arrendado.
    //
    // mig 159: se pinta con los ceros a la izquierda del talonario
    // (`document_sequence.padwidth`, bajado en el bootstrap). El prefijo NO
    // se concatena acá — `document_prefix` es su propio bloque y la plantilla
    // decide si sale (context/08, "lo que se imprime lo decide la plantilla").
    documentNumber: padDocumentNumber(result.invoiceNumber, state.invoicePadWidth) || undefined,
    documentPrefix: activeRegister?.expeditionPoint ?? undefined,
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
    // payload.tax siempre viaja en 0 (el front ya no calcula el impuesto de
    // autoridad — lo congela el backend, F2a). El ticket necesita un valor
    // real para mostrar ANTES de esa respuesta, así que usa el mismo motor
    // que calculó cada línea arriba.
    taxTotal: taxResult.totals.tax,
    total: result.total,
    payments,
    note: payload.note ?? undefined,
    currency: config?.currency ?? null,
    thousand: config?.thousand ?? null,
    decimal: config?.decimal ?? null,
    country: config?.country ?? null,
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
  /** Porcentaje EFECTIVO de descuento de la línea (`SaleItem.discount`, ver
   *  frontend/lib/commands/create-sale.ts). NO es plata — ver `totalDiscount`. */
  discount: number
  /** Plata del descuento de la línea (`SaleItem.totalDiscount` →
   *  `itemSold.itemSoldDiscount`). Presente en `meta.transactionDetails` desde
   *  que existe el reparto por línea (allocate-discounts.ts) — `undefined` en
   *  ventas anteriores a ese corte, tratado como 0 (ver `buildTicketItemsFromTransaction`). */
  totalDiscount?: number
  status?: number
  sku?: string
  note?: string
  /** Etiquetas de línea (uso interno) — `Money::sanitizeSaleArray` ya las
   *  incluye en `meta.transactionDetails`, decodificado tal cual acá. */
  tags?: string[] | null
  /**
   * IVA congelado por línea (F2a) — `TransactionService::getSingle` decodifica
   * `meta->transactionDetails` a `transactionDatas` (api/lib/services/
   * TransactionService.php, `$rawDetails`/`$transactionDatas`), que trae estos
   * campos escritos por `SaleService::enrichWithTaxes` al confirmar la venta.
   * Opcionales: el hook consumidor (hooks/use-transactions.ts) puede no
   * tiparlos todavía, y ventas anteriores al corte de F2 no los tienen.
   */
  taxId?: string | null
  taxRate?: number
  taxKind?: "rate" | "exempt"
  taxIncluded?: boolean
  taxAmount?: number
  taxNet?: number
  /**
   * Tipo de línea, tal cual lo escribió `SaleService` en
   * `meta.transactionDetails` (`TransactionService::getSingle` hace un
   * `json_decode` directo, sin whitelist de campos, así que TODA clave que la
   * venta escribió llega hasta acá). `"addon"` = línea hija de add-on
   * (`SaleService::expandAddonSelections`, F3). `"compound"` = línea hija de
   * la receta de un combo FIJO (`SaleService::expandCompoundSelections`, F6
   * context/41 -> reportes, 2026-08-19) - mismo tratamiento visual, importe
   * SIEMPRE 0 (la plata vive en el padre). Las hijas quedan SIEMPRE
   * inmediatamente después de su padre en el detalle.
   */
  type?: string
  /** Opción de add-on que originó la línea (`addon_group_option.optionId`).
   *  Solo en líneas con `type === "addon"`. */
  addonOptionId?: string | null
}

export interface TicketablePaymentMethod {
  name?: string
  type?: string
  amount: number
}

/**
 * Mapea items de una transacción a TicketItem, resolviendo categoryId real
 * contra el catálogo cargado (necesario para el filtrado por categoría de
 * `printSale`/`getBindingsForSale` — categoryId=null hardcodeado lo rompía).
 *
 * Add-ons (D3 de context/41): el ticket del CLIENTE lista solo los add-ons que
 * COBRAN. Un "sin cebolla" o un "punto de cocción" con importe 0 es
 * información de COCINA, no un renglón de un documento fiscal — ensucia la
 * factura sin aportar plata. Los que sí cobran se listan indentados bajo su
 * padre, porque el importe tiene que poder atribuirse a algo.
 *
 * Compound (F6 context/41 → reportes, 2026-08-19): las hijas de la receta de
 * un combo FIJO (`type: "compound"`, `SaleService::expandCompoundSelections`)
 * siguen EXACTAMENTE el mismo criterio que un add-on gratis — su importe es
 * SIEMPRE 0 (la plata vive en el padre), así que se comportan como una hija
 * gratuita: la comanda las lista (útil para cocina — "1 combo Sandy" =
 * armar sandwich + papas + gaseosa), el ticket fiscal no.
 *
 * La cocina imprime TODO, y por eso este filtro es un parámetro y no una
 * constante: es la única diferencia entre las dos impresiones.
 *
 * @param opts.includeFreeAddons `true` = comanda (lista add-ons/compound de
 *   importe 0). Default `false` = ticket fiscal. Lo decide
 *   `buildTicketDataFromTransaction` por `docType`; un caller directo tiene
 *   que elegirlo a mano.
 */
export function buildTicketItemsFromTransaction(
  items: TicketableTransactionItem[] | null | undefined,
  opts?: { includeFreeAddons?: boolean },
): TicketItem[] {
  const catalogItems = useCatalogStore.getState().items
  const includeFreeAddons = opts?.includeFreeAddons ?? false
  return (items ?? [])
    .filter((i) => i.status !== 0)
    .filter((i) => {
      // Solo se descartan HIJAS gratuitas (add-on o compound). Una línea
      // top-level de importe 0 (promo, cortesía, canje) se sigue imprimiendo:
      // es una entrega real al cliente y su ausencia del ticket sería un
      // faltante, no una limpieza.
      if (i.type !== "addon" && i.type !== "compound") return true
      if (includeFreeAddons) return true
      return (i.total ?? 0) !== 0
    })
    .map((i) => {
      const catalogItem = i.itemId ? catalogItems.find((c) => c.id === i.itemId) : undefined
      return {
        name: i.name,
        qty: i.count,
        unitPrice: i.price,
        // i.discount = % efectivo, i.totalDiscount = plata — mismo criterio
        // que buildTicketData (ver TicketableTransactionItem arriba). `?? 0`
        // cubre ventas anteriores al reparto por línea, sin `totalDiscount`.
        discountAmount: i.totalDiscount ?? 0,
        discountPercent: i.discount,
        total: i.total,
        categoryId: catalogItem?.categoryId ?? null,
        id: i.itemId ?? null,
        uid: i.sku ?? null,
        note: i.note ?? null,
        tags: i.tags ?? null,
        isAddonChild: i.type === "addon" || i.type === "compound",
        // Congelado real (F2a) tal cual, sin recalcular — ver comentario en
        // TicketableTransactionItem. `?? null`/`?? undefined` acá cubre tanto
        // el caller que no tipa estos campos como la venta pre-F2 sin ellos.
        taxId: i.taxId ?? null,
        taxRate: i.taxRate ?? null,
        taxKind: i.taxKind ?? null,
        taxIncluded: i.taxIncluded ?? null,
        taxAmount: i.taxAmount ?? null,
        taxNet: i.taxNet ?? null,
      }
    })
}

export function buildTicketDataFromTransaction(
  tx: TicketableTransaction,
  config: PosConfig | null,
  docType: string,
): TicketData {
  // D3 (context/41): la comanda lista TODOS los add-ons, el ticket del cliente
  // solo los que cobran. `docType` es lo único que distingue una impresión de
  // la otra en este builder.
  const items = buildTicketItemsFromTransaction(tx.transactionDatas, {
    includeFreeAddons: docType === "order",
  })
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
    // mig 159 — mismo formateador que la venta recién cobrada, para que el
    // reimpreso salga idéntico al original.
    documentNumber: padDocumentNumber(tx.documentNo, state.invoicePadWidth) || undefined,
    documentPrefix: tx.invoicePrefix || undefined,
    transactionId: tx.transactionId,
    date: tx.date ?? new Date().toISOString(),
    items,
    subtotal: itemsTotal > 0 ? itemsTotal : total + discount,
    discount,
    // Suma de `taxAmount` congelado por línea (ver TicketableTransactionItem)
    // — 0 si ninguna línea lo trae (venta pre-F2, o el caller no tipa esos
    // campos todavía), no una recomputación.
    taxTotal: items.reduce((s, i) => s + (i.taxAmount ?? 0), 0),
    total,
    payments,
    note: tx.note || undefined,
    currency: config?.currency ?? null,
    thousand: config?.thousand ?? null,
    decimal: config?.decimal ?? null,
    country: config?.country ?? null,
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
    /** Impuesto total de la venta, ya congelado (F2a) — suma de `itemSoldTax`. */
    transactionTax: number
    transactionPaymentType: Array<{ type: string; name: string; total: number }>
    invoiceNo: string | null
    /**
     * Correlativo YA formateado por el backend con el ancho del talonario
     * (`DocumentNumber::pad`, mig 159) y prefijo del timbrado. Opcionales
     * porque `TxDetailFull` los marca así, pero el resolver canónico
     * (`TransactionDetailService`) siempre los manda.
     *
     * Se prefieren sobre `invoiceNo` a propósito: el panel NO conoce el
     * `padwidth` de la caja que emitió (no hidrata el store del POS), así que
     * re-padear acá inventaría un ancho. El backend ya lo resolvió.
     */
    invoiceNoPad?: string
    invoicePrefix?: string
    customerName: string | null
  }
  items: Array<{
    itemId: string
    itemName: string
    itemSoldUnits: number
    itemSoldTotal: number
    /** Impuesto de la línea, congelado server-side (F2a) — TransactionsService
     *  lo trae de `itemSold.itemSoldTax`. */
    itemSoldTax: number
  }>
}

export function buildTicketDataFromTxDetail(
  detail: TicketableTxDetail,
  companyName: string,
  docType: string,
  /** Moneda del tenant (Bootstrap.currency/thousand/decimal/country, panel) —
   *  mismo shape que PosConfig, opcional para no romper callers existentes.
   *  `null` cae a la cadena de fallbacks de `resolveCurrencyLabel`, que no
   *  asume Paraguay (ver TicketData.currency/country arriba). */
  moneyConfig?: Pick<PosConfig, "currency" | "thousand" | "decimal" | "country"> | null,
): TicketData {
  const tx = detail.transaction
  const items: TicketItem[] = detail.items.map((i) => ({
    name: i.itemName,
    qty: i.itemSoldUnits,
    unitPrice: i.itemSoldUnits > 0 ? i.itemSoldTotal / i.itemSoldUnits : i.itemSoldTotal,
    // Sin desglose de descuento por ítem en este endpoint (TxDetailItem no lo
    // trae) — igual límite que taxRate/taxKind/etc. abajo.
    discountAmount: 0,
    discountPercent: 0,
    total: i.itemSoldTotal,
    // Sin catálogo POS hidratado en el panel — printTicketInBrowser no filtra
    // por categoría (solo lo hace el matching de bindings de hardware, que
    // el panel no usa), así que null acá es inocuo.
    categoryId: null,
    id: i.itemId ?? null,
    // Sin sku/nota/etiquetas por ítem en este endpoint (TxDetailItem no los trae).
    uid: null,
    note: null,
    tags: null,
    taxAmount: i.itemSoldTax,
    // taxRate/taxKind/taxIncluded/taxId/taxNet: TransactionsService no expone
    // el desglose (solo el monto ya congelado, itemSoldTax) — derivarlos de
    // itemSoldTotal sería asumir si el impuesto viene incluido o añadido, y
    // esta fase prohíbe inventar/recalcular impuesto. Ampliar el endpoint
    // para exponer transactionDetails es trabajo de backend, fuera de F3b.
    taxId: null,
    taxRate: null,
    taxKind: null,
    taxIncluded: null,
    taxNet: null,
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
    // Formateado server-side (ver TicketableTxDetail.invoiceNoPad). El
    // fallback a `invoiceNo` crudo cubre un payload viejo cacheado, no el
    // camino normal.
    documentNumber: tx.invoiceNoPad || tx.invoiceNo || undefined,
    documentPrefix: tx.invoicePrefix || undefined,
    transactionId: tx.transactionId,
    date: tx.transactionDate,
    items,
    subtotal: itemsTotal > 0 ? itemsTotal : tx.transactionTotal + tx.transactionDiscount,
    discount: tx.transactionDiscount,
    taxTotal: tx.transactionTax,
    total: tx.transactionTotal,
    payments,
    note: tx.transactionNote || undefined,
    currency: moneyConfig?.currency ?? null,
    thousand: moneyConfig?.thousand ?? null,
    decimal: moneyConfig?.decimal ?? null,
    country: moneyConfig?.country ?? null,
  }
}

// ── Reconstrucción de TicketData para nota de remisión (context/42) ────────
// Dos fuentes distintas, mismo docType "delivery" al imprimir:
//   - stock_transfer: traslado entre depósitos/sucursales PROPIOS (ya movió
//     stock de doble entrada, StockTransferService).
//   - document_remision: venta/devolución a proveedor/consignación/
//     exposición/compra — documental, no mueve stock (ver mig 137).
// Ninguno de los dos trae precios: una remisión ampara TRASLADO, no venta —
// TicketItem.unitPrice/total van en 0 a propósito (los bloques `item_price`/
// `item_total`/`total` imprimirán "Gs. 0" si el comercio los agrega a su
// plantilla de remisión; es su elección, no una restricción de acá).

/** Shape mínimo de StockTransferDetail (hooks/use-stock-transfers.ts) que
 *  este adapter necesita — no importa el hook para no acoplar este módulo. */
export interface TicketableStockTransfer {
  transfer: {
    stockTransferId: string
    docNumber: number | null
    createdAt: string
    note: string | null
    fromOutletName: string
    fromLocationName: string | null
    toOutletName: string
    toLocationName: string | null
  }
  items: Array<{ itemId: string; name: string; sku: string | null; qty: number }>
}

function locationJoin(outletName: string, locationName: string | null): string {
  return locationName ? `${outletName} → ${locationName}` : outletName
}

export function buildTicketDataFromStockTransfer(
  detail: TicketableStockTransfer,
  companyName: string,
): TicketData {
  const { transfer, items } = detail
  const ticketItems: TicketItem[] = items.map((i) => ({
    name: i.name,
    qty: i.qty,
    unitPrice: 0,
    discountAmount: 0,
    discountPercent: 0,
    total: 0,
    categoryId: null,
    id: i.itemId,
    uid: i.sku,
    note: null,
    tags: null,
    taxId: null,
    taxRate: null,
    taxKind: null,
    taxIncluded: null,
    taxAmount: null,
    taxNet: null,
  }))

  return {
    companyName,
    docType: "delivery",
    documentNumber: transfer.docNumber !== null ? String(transfer.docNumber) : undefined,
    transactionId: transfer.stockTransferId,
    date: transfer.createdAt,
    transferReason: "Transferencia entre sucursales/depósitos",
    originLabel: locationJoin(transfer.fromOutletName, transfer.fromLocationName),
    destinationLabel: locationJoin(transfer.toOutletName, transfer.toLocationName),
    items: ticketItems,
    subtotal: 0,
    discount: 0,
    taxTotal: 0,
    total: 0,
    payments: [],
    note: transfer.note || undefined,
  }
}

/** Shape mínimo de RemisionDetail (hooks/use-remisiones.ts) que este adapter
 *  necesita — no importa el hook para no acoplar este módulo. */
export interface TicketableRemision {
  remision: {
    remisionId: string
    docNumber: number | null
    motivo: string
    motivoLabel: string
    transferDate: string
    note: string | null
    outletName: string
    locationName: string | null
    destinationContactName: string | null
    destinationNote: string | null
  }
  items: Array<{ itemId: string; name: string; sku: string | null; qty: number; note: string | null }>
}

/** Destino: contacto (cliente/proveedor) y/o nota libre — los dos pueden
 *  coexistir (ej. "Cliente X" + "Entregar en depósito de Ruta 2"). */
function remisionDestinationLabel(r: TicketableRemision["remision"]): string | null {
  const parts = [r.destinationContactName, r.destinationNote].filter((s): s is string => !!s && s.trim() !== "")
  return parts.length > 0 ? parts.join(" — ") : null
}

export function buildTicketDataFromRemision(
  detail: TicketableRemision,
  companyName: string,
): TicketData {
  const { remision, items } = detail
  const ticketItems: TicketItem[] = items.map((i) => ({
    name: i.name,
    qty: i.qty,
    unitPrice: 0,
    discountAmount: 0,
    discountPercent: 0,
    total: 0,
    categoryId: null,
    id: i.itemId,
    uid: i.sku,
    note: i.note,
    tags: null,
    taxId: null,
    taxRate: null,
    taxKind: null,
    taxIncluded: null,
    taxAmount: null,
    taxNet: null,
  }))

  return {
    companyName,
    docType: "delivery",
    documentNumber: remision.docNumber !== null ? String(remision.docNumber) : undefined,
    transactionId: remision.remisionId,
    date: remision.transferDate,
    transferReason: remision.motivoLabel,
    originLabel: locationJoin(remision.outletName, remision.locationName),
    destinationLabel: remisionDestinationLabel(remision),
    items: ticketItems,
    subtotal: 0,
    discount: 0,
    taxTotal: 0,
    total: 0,
    payments: [],
    note: remision.note || undefined,
  }
}

// ── Datos de demostración para el editor de plantillas (Vista Previa) ──────
// Antes la Vista Previa (preview-dialog.tsx) resolvía cada bloque contra un
// diccionario de strings hardcodeadas (`lib/print-template-mock.ts`, ya
// eliminado) que nunca pasaba por BLOCK_VALUE_RESOLVERS/ITEM_FIELD_RESOLVERS
// — dos fuentes de verdad para "qué muestra un bloque", y la del preview
// divergió: mostraba el MISMO número ("110.909") para IVA 5% y 10% porque
// indexaba solo por BlockType, ignorando `block.text` (donde vive el taxId
// del bloque por-tasa). Esta función es la ÚNICA fuente de datos de demo:
// arma un TicketData con una venta ficticia pero ARITMÉTICAMENTE REAL, que
// el preview (y el thumbnail del canvas, canvas-block.tsx) resuelven con el
// MISMO catálogo de bloques que usan render-template.ts/html-renderer.ts —
// no puede volver a divergir de la impresión real de una venta.
//
// `taxes` son las tasas REALES del tenant (useTaxes(), ya cargadas por
// TemplateEditor para la paleta de bloques). El `defaultText` que la paleta
// escribe en un bloque `subtotal_by_rate`/`iva_by_rate`/`item_total_by_rate`
// es el `taxId` real (print-template-palette.ts `buildTaxRateSection`), así
// que los ítems de demo tienen que llevar ESE MISMO taxId — un taxId
// inventado dejaría esos bloques en blanco en el preview (mismo resultado
// que ve el comercio si el bloque no matchea ningún impuesto suyo).
/**
 * Shape mínimo de impuesto que `buildDemoTicketData` necesita (id/rate/kind
 * nomás). Angostado a propósito: el caller original (`TemplateEditor`,
 * `useTaxes()`) trae `Tax[]` completo (con `name`/`extra`/`sortOrder`/etc,
 * `lib/types/tax.ts`), pero `buildTicketDataForTest` (más abajo) solo tiene
 * `PosTaxRate[]` a mano (`useCatalogStore.taxes`, el subset que viaja en el
 * bootstrap del POS, `lib/types/pos-bootstrap.ts`) — ambos shapes satisfacen
 * este contrato sin casts ni conversiones.
 */
type DemoTaxSource = Pick<Tax, "id" | "rate" | "kind">

/**
 * Datos de demo del editor de plantillas de impresión.
 *
 * `config` es la config REAL del tenant (`Bootstrap`/`PosConfig`). Los datos
 * ficticios del resto del ticket (nombres, RUCs, direcciones) están bien como
 * están —son relleno para que el editor tenga algo que dibujar—, pero la
 * MONEDA no es relleno: el comercio está diseñando el ticket que va a
 * imprimir, y tiene que verlo con su propio símbolo y sus propios
 * separadores. Antes esto era `currency: "Gs"` fijo, así que un tenant
 * brasileño diseñaba su factura mirando guaraníes.
 *
 * Es opcional para no romper a los callers que no tienen bootstrap a mano; en
 * ese caso los resolvers caen a su cadena de fallbacks, que no asume Paraguay.
 */
export function buildDemoTicketData(
  taxes: DemoTaxSource[],
  config?: Pick<PosConfig, "currency" | "thousand" | "decimal" | "country"> | null,
): TicketData {
  // Las dos tasas "rate" (no exentas) más altas del tenant — típicamente IVA
  // 10%/5% en Paraguay (TaxService siembra estas por defecto en el signup,
  // context/38 §A). Con una sola tasa cargada, ambos ítems de demo caen en
  // el mismo bucket (mismo resultado que vería ese comercio real); con cero,
  // quedan sin taxId — no hay bloques por-tasa en la paleta en ese caso
  // (buildTaxRateSection solo agrega la sección "Impuestos" con
  // `taxes.length > 0`, print-template-palette.ts).
  const rateTaxes = taxes
    .filter((t) => t.kind === "rate" && t.rate !== null)
    .sort((a, b) => (b.rate ?? 0) - (a.rate ?? 0))
  const taxA = rateTaxes[0] ?? null
  const taxB = rateTaxes[1] ?? rateTaxes[0] ?? null

  /**
   * IVA incluido en el precio (Paraguay, ver trampa documentada en el brief
   * de la tarea): el monto de impuesto de un precio con IVA YA incluido es
   * `gross * rate / (100 + rate)` — 10% → gross/11, 5% → gross/21. NUNCA
   * `gross * rate/100` (eso sería IVA agregado, no incluido).
   */
  function taxedLine(name: string, uid: string, qty: number, grossUnitPrice: number, tax: DemoTaxSource | null): TicketItem {
    const gross = qty * grossUnitPrice
    const rate = tax?.rate ?? 0
    const taxAmount = tax ? Math.round((gross * rate) / (100 + rate)) : 0
    const taxNet = gross - taxAmount
    return {
      name,
      qty,
      unitPrice: grossUnitPrice,
      discountAmount: 0,
      discountPercent: 0,
      total: gross,
      categoryId: null,
      id: null,
      uid,
      note: null,
      tags: null,
      taxId: tax?.id ?? null,
      taxRate: tax ? rate : null,
      taxKind: tax ? tax.kind : "exempt",
      taxIncluded: true,
      taxAmount: tax ? taxAmount : null,
      taxNet: tax ? taxNet : null,
    }
  }

  const items: TicketItem[] = [
    taxedLine("Producto A", "SKU-001", 2, 50_000, taxA),
    taxedLine("Producto B", "SKU-002", 1, 100_000, taxB),
  ]

  const subtotal = items.reduce((s, i) => s + (i.taxNet ?? i.total), 0)
  const taxTotal = items.reduce((s, i) => s + (i.taxAmount ?? 0), 0)
  const total = items.reduce((s, i) => s + i.total, 0)

  return {
    companyName: "Mi Empresa S.A.",
    companyBillingName: "Mi Empresa Sociedad Anónima",
    companyTin: "80012345-6",
    companyAddress: "Av. España 1234, Asunción",
    companyEmail: "info@miempresa.com",
    companyPhone: "021 111-222",
    companyWebsite: "www.miempresa.com",
    outletName: "Casa Central",
    outletAddress: "Av. España 1234, Asunción",
    outletBillingName: "Mi Empresa S.A. – Casa Central",
    outletTin: "80012345-6",
    outletPhone: "021 111-222",
    customerName: "Comercial Ejemplo S.R.L.",
    customerAddress: "Mariscal López 567",
    customerAddress2: "Edificio Torre, Piso 4",
    customerLocation: "Trinidad",
    customerCity: "Asunción",
    customerCountry: "Paraguay",
    customerPhone: "0981 234-567",
    customerPhone2: "0971 765-432",
    customerTin: "80098765-4",
    customerEmail: "cliente@email.com",
    customerNote: "Nota del cliente",
    customerLoyalty: "1.250",
    customerBirthday: "1985-03-15",
    docType: "invoice",
    documentNumber: "001-001-0000123",
    documentPrefix: "001-001-",
    documentSufix: "0000123",
    ticketNo: "123",
    transactionId: "TX-789456",
    dueDate: "2026-07-12",
    tags: ["etiqueta1", "etiqueta2"],
    associatedDocument: "001-001-0000099",
    einvoiceUrl: "https://ekuatia.set.gov.py/consultas/qr?demo",
    userName: "Juan Pérez",
    registerName: "Caja Principal",
    printerName: "Impresora 1",
    authNumber: "12345678",
    authStartDate: "2025-01-01",
    authExpiration: "2026-12-31",
    date: "2026-06-12 14:35",
    items,
    subtotal,
    discount: 0,
    taxTotal,
    total,
    currency: config?.currency ?? null,
    thousand: config?.thousand ?? null,
    decimal: config?.decimal ?? null,
    country: config?.country ?? null,
    payments: [{ method: "Efectivo", amount: total }],
    note: "Gracias por su compra",
    orderDestination: "Mesa 3",
  }
}

// ── Ticket de PRUEBA (botón "Probar" de impresoras, Ajustes → Impresoras) ──
// El owner pidió ver el FORMATO REAL de la plantilla en vez del texto fijo
// "Prueba de impresión" que imprimía antes (`printTest` en
// `lib/hardware/printers/index.ts`). Esta función es la única fuente de
// datos para esa prueba — usa el carrito EN CURSO por el MISMO camino
// aritmético que una venta real (`buildSalePayload` → `buildTicketData`), así
// columnas por tasa, liquidación de IVA y totales salen calculados de
// verdad, no hardcodeados.
//
// Carrito vacío (o la prueba se dispara desde un contexto sin carrito, ej. la
// pantalla de Estación de Impresión) → `buildDemoTicketData()`, la MISMA
// fuente que ya usa el preview del editor de plantillas — así "Probar"
// siempre imprime algo con formato real, incluso sin venta en curso.
//
// NUNCA toca numeración/stock/cola offline: `buildSalePayload` es una
// función PURA (solo arma el shape del payload en memoria, sin I/O) y acá no
// se llama `executeSale`/ningún POST — no se persiste transacción ni se
// consume la numeración real de la caja. El número de comprobante es SIEMPRE
// el correlativo de EJEMPLO "001-001-0000000" (pedido owner) — jamás
// `getNextInvoiceNo()`/`DocumentNumber::allocate()`/`advanceTo()`.
//
// Cliente enmascarado (pedido owner 2026-08-18, ampliado el mismo día tras
// review): imprimir CUALQUIER dato de un cliente real en un ticket de PRUEBA
// lo expondría sin necesidad — TODOS los campos `customerXxx` de TicketData
// salen con un placeholder ficticio, nunca el dato real. Los datos de la
// venta (líneas, cantidades, precios, descuentos, impuestos, totales) y los
// del COMERCIO (companyName/companyTin/outletName/etc, logo, timbrado) siguen
// reales — ver `maskCustomerFields` abajo para el detalle y el porqué de
// enmascarar en un solo punto en vez de campo por campo en cada bloque.
export function buildTicketDataForTest(): TicketData {
  const cart = useCartStore.getState()
  const { config, taxes } = useCatalogStore.getState()

  const base: TicketData =
    cart.lines.length === 0 ? buildDemoTicketData(taxes) : buildTicketDataFromCartForTest(cart, config)

  return withTestMasking(base)
}

/**
 * Correlativo de EJEMPLO para CUALQUIER ticket que no sea una venta real —
 * "Probar" de impresoras y "Simular impresión" del editor de plantillas
 * comparten este mismo valor. Jamás `getNextInvoiceNo()`/
 * `DocumentNumber::allocate()`/`advanceTo()` — ver docblock de
 * `buildTicketDataForTest` arriba para el porqué.
 */
function withTestMasking(base: TicketData): TicketData {
  return {
    ...maskCustomerFields(base),
    documentNumber: "001-001-0000000",
    documentPrefix: "001-001-",
    documentSufix: "0000000",
  }
}

/**
 * Ticket de PRUEBA para "Simular impresión" en `/settings/print-templates`
 * (TemplateEditor) — mismo enmascarado de cliente + correlativo de ejemplo
 * que `buildTicketDataForTest` (botón "Probar" de Ajustes → Impresoras), pero
 * sin `useCartStore`/`useCatalogStore`: el editor vive en el realm panel, esos
 * stores del POS (bootstrap del device) no están poblados ahí. Recibe las
 * tasas reales del tenant — las mismas que `TemplateEditor` ya trae con
 * `useTaxes()` para la sección "Impuestos" de la paleta y para `demoData`.
 */
export function buildTemplateTestData(taxes: DemoTaxSource[]): TicketData {
  return withTestMasking(buildDemoTicketData(taxes))
}

/**
 * Placeholders de cliente para el ticket de PRUEBA — un valor ficticio por
 * campo, en el mismo estilo que pidió el owner para nombre/RUC ("Prueba de
 * impresión" / "XXXXXX-X"): evidentemente falso, NUNCA vacío, para que el
 * owner vea el bloque ocupado y bien posicionado (mismo criterio que el
 * correlativo de ejemplo "001-001-0000000").
 */
const TEST_CUSTOMER_PLACEHOLDERS: Partial<Record<keyof TicketData, string>> = {
  customerName: "Prueba de impresión",
  customerTin: "XXXXXX-X",
  customerAddress: "Dirección de prueba 123",
  customerAddress2: "Dirección de prueba 123",
  customerPhone: "0000 000-000",
  customerPhone2: "0000 000-000",
  customerLocation: "Ciudad de prueba",
  customerCity: "Ciudad de prueba",
  customerCountry: "País de prueba",
  customerEmail: "prueba@ejemplo.com",
  customerNote: "Nota de prueba",
  customerLoyalty: "0",
  customerBirthday: "2000-01-01",
}

/**
 * Enmascara TODO dato de CLIENTE de un TicketData de prueba — un solo punto
 * (pedido owner: "atacar el wrapper compartido, no los call-sites"), no
 * campo por campo en cada bloque de `blocks.ts`. Recorre las keys del propio
 * `TicketData` en vez de listar bloques a mano: cualquier campo que empiece
 * con "customer" y tenga un valor se pisa con un placeholder. El catálogo de
 * bloques (`blocks.ts`: `customer_name`/`customer_full_name`/`customer_tin`/
 * `customer_ci`/`customer_address`/`customer_phone`/`customer_address_2`/
 * `customer_location`/`customer_city`/`customer_country`/`customer_phone_2`/
 * `customer_note`/`customer_loyalty`/`customer_birthday`/`customer_email`)
 * resuelve TODOS estos campos directo contra `TicketData` — enmascarar acá,
 * el objeto que alimenta a esos resolvers, cubre el catálogo completo de una
 * sola vez. Un bloque de cliente NUEVO que se agregue mañana queda cubierto
 * automáticamente mientras su campo en `TicketData` empiece con "customer" —
 * no hace falta acordarse de venir a sumarlo acá.
 *
 * SOLO datos de cliente: los datos del COMERCIO (`companyName`/`companyTin`/
 * `companyAddress`/`companyEmail`/`companyWebsite`, `outletName`/`outletTin`/
 * `outletAddress`/`outletPhone`, logo, timbrado) no empiezan con "customer" —
 * quedan afuera del loop, reales tanto en venta como en prueba (el owner
 * necesita verificar cómo salen esos en el papel).
 */
function maskCustomerFields(data: TicketData): TicketData {
  const masked: Record<string, unknown> = { ...data }
  for (const key of Object.keys(masked)) {
    if (!key.startsWith("customer")) continue
    if (masked[key] == null) continue // sin dato real (ni siquiera en la demo) — nada que enmascarar
    masked[key] = TEST_CUSTOMER_PLACEHOLDERS[key as keyof TicketData] ?? "Dato de prueba"
  }
  return masked as unknown as TicketData
}

function buildTicketDataFromCartForTest(
  cart: ReturnType<typeof useCartStore.getState>,
  config: PosConfig | null,
): TicketData {
  const payload = buildSalePayload({
    lines: cart.lines,
    payments: [],
    credito: cart.credito,
    interno: cart.interno,
    customer: cart.customer,
    userId: null,
    tags: cart.tags,
    quoteParentId: cart.quoteParentId,
    saleDiscount: cart.saleDiscount,
    timezone: config?.timezone ?? null,
    dueDate: null,
    ivaRemoved: cart.ivaRemoved,
    // `invoiceno` es OBLIGATORIO en el shape de BuildSaleInput (context/29,
    // numeración/exclusividad de caja) pero acá es un placeholder puro: este
    // `payload` NUNCA se manda al backend (no hay `executeSale`/POST en esta
    // función), solo se usa en memoria para recalcular subtotal/descuento/
    // impuestos por línea igual que una venta real. Un ticket de PRUEBA jamás
    // puede tocar `getNextInvoiceNo()`/consumir el lease — el número que
    // termina impreso es SIEMPRE el correlativo de ejemplo que se pisa más
    // abajo en `buildTicketDataForTest` ("001-001-0000000"), nunca este 0.
    invoiceno: 0,
  })
  const total = Math.max(0, payload.subtotal - payload.discount)
  // Pago de relleno: la prueba puede dispararse antes de que el cajero elija
  // un método de pago real (solo quiere ver el formato) — un "Efectivo" por
  // el total cierra el bloque de pagos si la plantilla lo trae, sin registrar
  // ningún cobro real.
  payload.payment = [{ name: "Efectivo", total }]

  const result: CreateSaleResult = {
    transactionId: "PRUEBA",
    transactionUID: payload.uid,
    invoiceNumber: null,
    total,
    duplicated: false,
    einvoicePortalUrl: null,
  }
  return buildTicketData({ payload, result, config })
}
