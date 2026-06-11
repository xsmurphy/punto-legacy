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
  /** Teléfono secundario en E.164. */
  phone2: string | null
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
  phone2: string | null // E.164
  email: string
  note: string
  status: boolean
  city: string
  location: string
  country: string
  address: string
  address2: string
}
