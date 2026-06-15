/**
 * Shapes de `/v1/price_list` y `/v1/price_list_item`.
 */

export interface PriceList {
  priceListId: string
  priceListName: string
  /** positivo = recargo (+%), negativo = descuento (−%). */
  defaultAdjustment: number
  validFrom: string | null
  validTo: string | null
  status: boolean
  itemCount: number
  createdAt: string | null
  updatedAt: string | null
}

export interface PriceListDetail extends PriceList {
  items: PriceListItem[]
}

export interface PriceListItem {
  priceListItemId: string
  priceListId: string
  itemId: string
  itemName: string
  itemSKU: string | null
  /** Precio base del ítem en el catálogo. */
  basePrice: number
  /** Precio absoluto fijo. Mutuamente excluyente con itemAdjustment. */
  fixedPrice: number | null
  /** % de ajuste individual (reemplaza defaultAdjustment). */
  itemAdjustment: number | null
  /** Precio final calculado = lo que se muestra al cliente. */
  resolvedPrice: number
}

/** Payload POST /v1/price_list */
export interface CreatePriceListPayload {
  name: string
  defaultAdjustment: number
  validFrom?: string | null
  validTo?: string | null
  status?: boolean
}

/** Payload PUT /v1/price_list?id= */
export interface UpdatePriceListPayload {
  name?: string
  defaultAdjustment?: number
  validFrom?: string | null
  validTo?: string | null
  status?: boolean
}

/** Un ítem en el bulk-replace de /v1/price_list_item */
export interface PriceListItemInput {
  itemId: string
  fixedPrice?: number | null
  itemAdjustment?: number | null
}

/** Resultado de /v1/price_resolve */
export interface ResolvedPrice {
  itemId: string
  price: number
  originalPrice: number
  priceListId: string | null
  priceListName: string | null
}
