/**
 * Shapes de `/v1/contacts` — mirror exacto de `ContactService::presentRow()`
 * (api/lib/Contacts/ContactService.php:252).
 *
 * Contact unifica clientes y razones sociales: si `fiscalName` está cargado,
 * el contacto es una empresa; si no, es una persona física. La API guarda
 * `contactName` = razón social O nombre completo, y `contactSecondName` = el
 * nombre personal cuando hay razón social. Para el front simplificamos a dos
 * campos lógicos: `name` (display principal) + `fiscalName` (opcional).
 */

export interface ContactListItem {
  id: string
  /** Identificador único en BD — igual a `id`, viene por carry-over legacy. */
  UID: string
  /** Display name del contacto (razón social o nombre persona). */
  name: string
  /** Nombre adicional cuando el principal es razón social. */
  fullname: string
  /** RUC paraguayo (Punto-PY context). */
  tin: string | null
  /** Cédula de identidad. */
  ci: string | null
  /** Fecha cumpleaños YYYY-MM-DD. */
  bday: string | null
  /** Teléfono primary en E.164 (convención §31). */
  phone: string | null
  email: string | null
  note: string | null
  /** 1 = activo, 0 = archivado. */
  status: number | null
  storeCredit: number | string | null
  loyalty: number | string | null
  loyaltyAmount: number | string | null
  /** Código ISO 3166-1 alpha-2. */
  country: string | null
  /** Fecha alta del contacto (TIMESTAMPTZ). */
  date: string | null
  /** UUID de la dirección default. Para CRUD de direcciones adicionales,
   *  consultar el sub-recurso `/v1/contacts/{id}?resource=addresses`. */
  addressId: string | null
  address: string | null
  address2: string | null
  city: string | null
  location: string | null
  lat: number | null
  lng: number | null
}

/** El detalle (GET ?id=) y el item de listado tienen exactamente el mismo shape
 *  en este endpoint — no hay sub-recursos embebidos. Aliasamos por claridad
 *  de uso, similar a outlets. */
export type ContactFull = ContactListItem

/**
 * Shape de `GET /v1/contacts?id=<uuid>&resource=analytics`.
 * Mirror del blob que devuelve `ContactAnalyticsService::compute()`
 * (api/lib/Contacts/ContactAnalyticsService.php).
 */
export interface ContactAnalytics {
  totals: {
    /** Total gastado (cliente) o comprado (proveedor) en la moneda local. */
    spent: number
    /** Cantidad de transacciones de venta/compra computadas. */
    purchases: number
    /** Suma de unidades vendidas/compradas en todas las tx. */
    itemsBought: number
    /** Ticket promedio (spent / purchases). 0 si no hay tx. */
    avgTicket: number
    discountTotal: number
  }
  visits: {
    /** Fecha de la PRIMERA tx (ISO timestamp). null si no hay tx. */
    firstAt: string | null
    /** Fecha de la última tx. null si no hay tx. */
    lastAt: string | null
    /** Días desde la última operación. null si nunca compró. */
    daysSinceLast: number | null
    /** Promedio de días entre tx (frecuencia). null si <2 tx. */
    avgDaysBetween: number | null
  }
  /** Segmento RFM-lite: nuevo / activo / en_riesgo / inactivo / vip / sin_actividad. */
  segment: { key: string; label: string }
  financial: {
    loyalty: number
    storeCredit: number
    creditLine: number
    isCreditable: boolean
    /** Cuentas por cobrar/pagar abiertas (deuda actual). */
    openInvoices: number
  }
  topItems: Array<{
    itemId: string
    name: string
    count: number
    total: number
  }>
  topCategories: Array<{
    taxonomyId: string
    name: string
    count: number
    total: number
  }>
  /** Mix entre Contado / A crédito (o equivalente compra). */
  paymentMix: Array<{
    type: number
    label: string
    count: number
    total: number
  }>
  /** Top 6 horas del día más activas. */
  byHour: Array<{ hour: string; count: number; total: number }>
  /** Distribución por día de la semana (Dom..Sáb). */
  byDayOfWeek: Array<{
    dow: number
    label: string
    count: number
    total: number
  }>
  /** Últimos 12 meses con tx, ordenado asc. Buckets vacíos no aparecen. */
  byMonth: Array<{ month: string; count: number; total: number }>
  /** Top 3 sucursales por frecuencia — útil en tenants multi-outlet. */
  byOutlet: Array<{
    outletId: string
    name: string
    count: number
    total: number
  }>
}

/** Lo que el form de panel-next manda al backend en POST/PUT. */
export interface ContactFormValues {
  /** "persona" → form muestra Nombre/Apellido; "empresa" → muestra Razón social.
   *  No es persistido directamente — sirve para decidir si mandamos `name`
   *  (persona) o `fiscalName` (empresa). El backend infiere desde los valores. */
  kind: "persona" | "empresa"
  /** En persona: nombre + apellido juntos. En empresa: vacío. */
  name: string
  /** En empresa: razón social. En persona: vacío. */
  fiscalName: string
  tin: string
  ci: string
  bday: string
  phone: string | null // E.164
  email: string
  note: string
  status: boolean
  city: string
  location: string
  country: string
  address: string
  address2: string
}
