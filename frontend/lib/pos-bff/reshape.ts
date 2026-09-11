/**
 * Reshapers compartidos por los BFF routes del POS que traducen filas
 * upstream (`/v1/items`, `/v1/contacts`) al shape `PosItem`/`PosCustomer`
 * que consume `useCatalogStore` (`frontend/lib/catalog/store.ts`).
 *
 * Única fuente de verdad — usada tanto por `/api/pos/bootstrap` (catálogo
 * completo) como por `/api/pos/items-batch` y `/api/pos/customers-batch`
 * (sync realtime quirúrgico, context/15-realtime-sync-plan.md §Modelo
 * quirúrgico). Si estos dos caminos reshapean distinto, `patchItem`/
 * `patchCustomer` terminan mezclando objetos con shape distinto en el mismo
 * array del store — bug silencioso, no un error que explote.
 */

import type { PosItem, PosCustomer, PosAddonGroup, PosCompoundItem } from "@/lib/types/pos-bootstrap"

// ── Items ─────────────────────────────────────────────────────────────────

// Shape real de filas de /v1/items (list Y bulk-get comparten el mismo
// SELECT en el backend, ver `buildItemsSelectSql()` en api/v1/items.php) —
// viene de presentItem() + _flattenJsonb(). Los campos JSONB demoted
// (itemTaxIncluded, itemUOM, etc.) aparecen flattened a top-level.
export interface UpstreamItemRow {
  itemId: string
  itemName: string
  itemSKU?: string | null
  /**
   * Código de barras propio del artículo (`item.barcode`, mig 220). Viaja en
   * el SELECT compartido del backend (`buildItemsSelectSql()`), así que llega
   * por los tres caminos —bootstrap, bulk-get quirúrgico y delta de sync— sin
   * que ninguno tenga que pedirlo aparte. Ver `PosItem.barcode`.
   */
  barcode?: string | null
  itemPrice?: number | string | null
  itemStatus?: number | boolean | string
  itemCanSale?: boolean
  itemTrackInventory?: boolean
  itemIsParent?: boolean
  itemParentId?: string | null
  itemTaxIncluded?: boolean
  itemUOM?: string | null
  taxId?: string | null
  categoryId?: string | null
  brandId?: string | null
  /**
   * Sucursal asignada al item (`item.outletId`, legado 1:1). Ya viaja en el
   * SELECT compartido (`buildItemsSelectSql()`, `i.outletId`) — solo faltaba
   * declararlo acá y mapearlo abajo. Ver `PosItem.outletId`.
   */
  outletId?: string | null
  coverImageUrl?: string | null
  kind?: string
  /** % de descuento de catálogo (JSONB flattened). Ver PosItem.discountPercent. */
  itemDiscount?: number | string | null
  /** F4 (context/41): el ítem tiene grupos de add-ons vigentes. Ver PosItem.hasAddons. */
  hasAddons?: boolean | string | number | null
  /**
   * Grupos de add-ons completos, embebidos (hueco P0 cerrado 2026-08-16, ver
   * PosItem.addonGroups). `presentItem()` (backend, ItemsQuery.php) ya
   * decodifica el `json_agg` de Postgres a un array de objetos con este
   * shape exacto — a diferencia de otros campos de esta fila (JSONB
   * "demoted"/flattened a texto), acá el tipo llega correcto desde el JSON
   * anidado (`json_build_object`), no requiere coerción de string.
   */
  addonGroups?: PosAddonGroup[] | null
  /**
   * Receta del combo FIJO (`item_compound`), embebida — mismo trato que
   * `addonGroups` arriba (context/41, cierre 2026-08-19). `presentItem()`
   * decodifica el `json_agg` a este shape exacto.
   */
  compoundItems?: PosCompoundItem[] | null
  /**
   * Saldo de inventario del ítem EN LA SUCURSAL DE LA CAJA (no consolidado
   * del tenant), o `null` si el ítem no lleva control de inventario.
   *
   * Lo resuelve `fetchItems()` (`api/lib/Items/ItemsQuery.php`) con
   * `Inventory::onHandFor()`, el lector canónico del ledger, para los TRES
   * caminos por los que baja catálogo al POS: bootstrap, bulk-get quirúrgico
   * y delta de sync. Que sea la misma función en los tres es lo que hace
   * confiable al número: llegue por donde llegue, significa lo mismo.
   */
  stockOnHand?: number | string | null
}

export function reshapeItem(row: UpstreamItemRow): PosItem {
  return {
    id: row.itemId,
    name: row.itemName,
    sku: row.itemSKU ?? null,
    // Defensivo: un bootstrap cacheado de ANTES de la mig 220 no trae el
    // campo. `?? null` y no `?? row.itemSKU`: hacer que el barcode caiga al
    // SKU los volvería indistinguibles, que es justo lo que esta feature vino
    // a separar.
    barcode: row.barcode ?? null,
    price: Number(row.itemPrice ?? 0),
    // `?? true` perdía la distinción "sin override" vs "explícitamente
    // incluido" — el carrito necesita el `null` para caer al default de la
    // sucursal (ver PosItem.taxIncluded, F2b context/38). NO defaultear acá.
    taxIncluded: row.itemTaxIncluded ?? null,
    taxId: row.taxId ?? null,
    categoryId: row.categoryId ?? null,
    brandId: row.brandId ?? null,
    outletId: row.outletId ?? null,
    imageUrl: row.coverImageUrl ?? null,
    uom: row.itemUOM ?? null,
    kind: row.kind ?? "producto",
    discountPercent: (() => {
      if (row.itemDiscount === null || row.itemDiscount === undefined || row.itemDiscount === "") {
        return null
      }
      const n = Number(row.itemDiscount)
      // Backend corrupto/valor no numérico → null, nunca NaN (contaminaría
      // saleDiscount y el total del carrito, ver lib/cart/store.ts::addItem).
      return Number.isFinite(n) ? n : null
    })(),
    trackInventory: row.itemTrackInventory ?? false,
    // Saldo de la sucursal de ESTA caja. Por qué el número es confiable sin
    // pedirlo aparte (2026-09-07):
    //
    //   - Viaja en el snapshot: `fetchItems()` (backend) lo resuelve con
    //     `Inventory::onHandFor()` —el lector único del ledger, D2 de
    //     `context/52`— en los tres caminos que bajan catálogo (bootstrap,
    //     bulk-get, delta de sync), así que no puede significar una cosa por
    //     un camino y otra por el otro.
    //   - Se mantiene solo, línea por línea: TODO movimiento de stock pasa por
    //     `Inventory::manageStock()`, que publica un evento realtime `item`
    //     batcheado por request (`flushRealtimeStockEvents()`); el POS lo
    //     escucha en `lib/catalog/realtime-catalog-sync.ts`, repide SOLO esos
    //     ids por bulk-get y los mergea. Sin polling y sin request extra.
    //   - Sin red se muestra el último saldo sincronizado, tal cual quedó
    //     (decisión del owner: el modo offline es para cortes temporales y el
    //     dato derivado viejo se acepta; no se esconde el número ni se marca).
    //
    // `null` = el ítem no lleva control de inventario. No es 0: un servicio o
    // un combo dinámico no tiene saldo, y un 0 lo pintaría "sin stock".
    stock: (() => {
      if (row.stockOnHand === null || row.stockOnHand === undefined || row.stockOnHand === "") {
        return null
      }
      // NUMERIC de Postgres puede llegar como string según driver — mismo
      // cuidado que `itemDiscount` arriba: NaN contaminaría el semáforo.
      const n = Number(row.stockOnHand)
      return Number.isFinite(n) ? n : null
    })(),
    isGroup: row.itemIsParent === true,
    parentId: row.itemParentId ?? null,
    // F4 (context/41): PG con PDO puede devolver el boolean del EXISTS como
    // 't'/'f' string según driver — presentItem() ya lo normaliza a bool, pero
    // el reshape no puede asumirlo (un 'f' string es truthy en JS). Solo el
    // `true` real y sus representaciones explícitas cuentan.
    hasAddons: row.hasAddons === true || row.hasAddons === "t" || row.hasAddons === "true" || row.hasAddons === 1,
    // Defensivo: si el upstream es un bootstrap cacheado de ANTES del hueco
    // P0 2026-08-16, `addonGroups` no viene — [] (nunca undefined/null hacia
    // el store, mismo criterio que el resto de los arrays de este objeto).
    addonGroups: Array.isArray(row.addonGroups) ? row.addonGroups : [],
    // Mismo criterio defensivo que addonGroups: bootstrap cacheado de antes
    // del cierre de este hueco → [] en vez de undefined/null.
    compoundItems: Array.isArray(row.compoundItems) ? row.compoundItems : [],
  }
}

/**
 * Activo Y vendible — mismo filtro que aplica `/api/pos/bootstrap` al armar
 * el catálogo inicial. El sync quirúrgico lo reusa para decidir si un item
 * que volvió del bulk-get se patchea (sellable) o se saca del store
 * (desactivado/no-vendible — "no dejarlo fantasma", ver context/15).
 */
export function isSellableItemRow(row: UpstreamItemRow): boolean {
  const status = row.itemStatus
  const active = status === 1 || status === true || status === "1"
  return active && row.itemCanSale === true
}

// ── Contactos ─────────────────────────────────────────────────────────────

// Shape real de filas de /v1/contacts (list, detalle Y bulk-get comparten
// presentación — ver ContactService::presentRow()).
export interface UpstreamContactRow {
  id: string
  name: string
  phone: string | null
  tin: string | null
  storeCredit: number | string | null
  status: string | number | null
  /**
   * `presentRow()` ya devuelve `(bool) ((int) (row.contactCreditable ?? 0) >
   * 0)` — un boolean real (no 't'/'f' de PDO: pasa por un cast PHP antes del
   * json_encode). `false`/ausente si el contacto nunca lo configuró — mismo
   * default que usa el panel al crear un contacto
   * (`contact-detail-view.tsx`: `isCreditable: false`).
   */
  isCreditable?: boolean | null
  /**
   * Datos extendidos que `presentRow()` YA devuelve — el reshape los tiraba y
   * por eso los bloques de cliente del ticket salían vacíos aunque el dato
   * estuviera cargado. `address`/`city`/`location` salen de la dirección por
   * defecto del contacto, con fallback a las columnas planas (ver presentRow).
   */
  email?: string | null
  note?: string | null
  bday?: string | null
  loyalty?: string | number | null
  address?: string | null
  address2?: string | null
  city?: string | null
  location?: string | null
  country?: string | null
}

export function reshapeCustomer(row: UpstreamContactRow): PosCustomer {
  return {
    id: row.id,
    name: row.name,
    phone: row.phone ?? null,
    tin: row.tin ?? null,
    storeCredit: Number(row.storeCredit ?? 0),
    // Antes hardcodeado a `true` (bug: el POS ofrecía venta a crédito a
    // TODO cliente, incluidos los que el comercio marcó sin crédito
    // habilitado). El campo real ya viajaba desde el backend — solo faltaba
    // leerlo acá. `=== true` (no `?? false`): cualquier valor que no sea el
    // boolean `true` explícito (ausente, null, corrupto) cae a `false` —
    // mismo criterio conservador que usa el panel al crear un contacto.
    isCreditable: row.isCreditable === true,
    // Datos extendidos: se propagan tal cual, sin normalizar ni derivar nada.
    // La plantilla decide qué imprime (context/08) — acá solo se deja de
    // perder el dato.
    email: row.email ?? null,
    note: row.note ?? null,
    bday: row.bday ?? null,
    // `contactLoyalty` es INT en la BD y PDO puede entregarlo numérico; el
    // renderer del ticket asume string (wrapToWidth/toUpperCase). Se coerce
    // acá, en la frontera, no en cada consumidor.
    loyalty: row.loyalty != null ? String(row.loyalty) : null,
    address: row.address ?? null,
    address2: row.address2 ?? null,
    city: row.city ?? null,
    location: row.location ?? null,
    country: row.country ?? null,
  }
}
