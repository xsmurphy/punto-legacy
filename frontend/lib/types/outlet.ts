/**
 * Shapes de `/v1/outlets` — mirror exacto de OutletsService::shape()
 * (api/lib/Outlets/OutletsService.php:260).
 *
 * `OutletListItem` = lo que devuelve `GET /v1/outlets` (lista).
 * `OutletFull`     = lo que devuelve `GET /v1/outlets?id=<uuid>` (campos completos
 *                    para el form de edición).
 *
 * Naming: el front usa nombres SIN el prefijo `outlet*` del SQL (outletName → name).
 * Ya lo hace el `shape()` del backend.
 */

export interface OutletListItem {
  id: string
  name: string
  billingName: string
  ruc: string
  phone: string
  address: string
  status: number
  ecom: boolean
  outletDate?: string | null
}

export interface OutletFull extends OutletListItem {
  email: string
  whatsApp: string
  /** Latitud (NUMERIC(10,7) en BD). Null si la sucursal no tiene coords. */
  lat: number | null
  /** Longitud. Va a su propia columna porque se usa para calcular distancia
   *  haversine (sucursal más cercana al cliente) directamente en SQL. */
  lng: number | null
  description: string
  purchaseOrderNo: number | null
  taxId: string
  taxIncluded: boolean
  /** JSON de horarios; `null` o array de slots `{day, from, to}`. Lo dejamos `unknown`
   *  hasta el slice del editor de horarios (custom widget). */
  businessHours: unknown
  /** Catálogo de impuestos disponibles para el dropdown del form. Solo en GET single. */
  availableTaxes?: TaxOption[]
  /** UUID de la lista de precios asignada a esta sucursal (desde data JSONB). */
  priceListId?: string | null
}

export interface TaxOption {
  id: string
  name: string
  rate: number
}

/** Campos que aceptamos editar — exacto a lo que el backend persiste en update. */
export interface OutletFormValues {
  name: string
  address: string
  phone: string
  email: string
  description: string
  status: boolean
  billingName: string
  ruc: string
  whatsApp: string
  purchaseOrderNo: number | null
  /** Latitud y longitud separadas. Null si el usuario no las completó. */
  lat: number | null
  lng: number | null
  taxId: string
  ecom: boolean
  taxIncluded: boolean
  /** UUID de la lista de precios asignada como default a esta sucursal. Null = precio base. */
  priceListId: string | null
}
