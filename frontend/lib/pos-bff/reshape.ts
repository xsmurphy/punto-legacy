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

import type { PosItem, PosCustomer } from "@/lib/types/pos-bootstrap"

// ── Items ─────────────────────────────────────────────────────────────────

// Shape real de filas de /v1/items (list Y bulk-get comparten el mismo
// SELECT en el backend, ver `buildItemsSelectSql()` en api/v1/items.php) —
// viene de presentItem() + _flattenJsonb(). Los campos JSONB demoted
// (itemTaxIncluded, itemUOM, etc.) aparecen flattened a top-level.
export interface UpstreamItemRow {
  itemId: string
  itemName: string
  itemSKU?: string | null
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
  coverImageUrl?: string | null
  kind?: string
  /** % de descuento de catálogo (JSONB flattened). Ver PosItem.discountPercent. */
  itemDiscount?: number | string | null
  /** F4 (context/41): el ítem tiene grupos de add-ons vigentes. Ver PosItem.hasAddons. */
  hasAddons?: boolean | string | number | null
}

export function reshapeItem(row: UpstreamItemRow): PosItem {
  return {
    id: row.itemId,
    name: row.itemName,
    sku: row.itemSKU ?? null,
    price: Number(row.itemPrice ?? 0),
    // `?? true` perdía la distinción "sin override" vs "explícitamente
    // incluido" — el carrito necesita el `null` para caer al default de la
    // sucursal (ver PosItem.taxIncluded, F2b context/38). NO defaultear acá.
    taxIncluded: row.itemTaxIncluded ?? null,
    taxId: row.taxId ?? null,
    categoryId: row.categoryId ?? null,
    brandId: row.brandId ?? null,
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
    // TODO (A6+): pedir stock real al depósito del outlet activo. El LIST de
    // /v1/items no incluye stock — habría que componer con /v1/items?resource=inventory
    // por item o agregar un endpoint /v1/stock?outletId=X. Por ahora null = sin info.
    stock: null,
    isGroup: row.itemIsParent === true,
    parentId: row.itemParentId ?? null,
    // F4 (context/41): PG con PDO puede devolver el boolean del EXISTS como
    // 't'/'f' string según driver — presentItem() ya lo normaliza a bool, pero
    // el reshape no puede asumirlo (un 'f' string es truthy en JS). Solo el
    // `true` real y sus representaciones explícitas cuentan.
    hasAddons: row.hasAddons === true || row.hasAddons === "t" || row.hasAddons === "true" || row.hasAddons === 1,
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
  }
}
