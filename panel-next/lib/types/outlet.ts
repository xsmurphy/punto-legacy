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
}

export interface OutletFull extends OutletListItem {
  email: string
  whatsApp: string
  latLng: string
  description: string
  purchaseOrderNo: number | null
  taxId: string
  taxIncluded: boolean
  /** JSON de horarios; `null` o array de slots `{day, from, to}`. Lo dejamos `unknown`
   *  hasta el slice del editor de horarios (custom widget). */
  businessHours: unknown
  /** Catálogo de impuestos disponibles para el dropdown del form. Solo en GET single. */
  availableTaxes?: TaxOption[]
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
  latLng: string
  taxId: string
  ecom: boolean
  taxIncluded: boolean
}
