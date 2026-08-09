/**
 * Tabla 3 SET (Paraguay) — tipos de documento de identidad del receptor de
 * un comprobante electrónico. Fuente: "Especificación Técnica para
 * Importación", SET, junio 2021. Mirror de `ContactService::ID_TYPE_*`
 * (api/lib/Contacts/ContactService.php).
 *
 * Exclusivo de Paraguay: el selector solo se muestra si el país del tenant
 * es "PY" (`Bootstrap.country` en el panel, `PosConfig.country` en el POS) —
 * mismo gate que aplica el backend en `ContactService::isPyTenant()`. Un
 * tenant no-PY nunca ve este selector y el campo se ignora si de todos
 * modos llega en el payload (ver ContactService::create/update).
 *
 * `numberField` indica a qué campo del form / columna de `contact` mapea el
 * número: RUC va a `tin` (contactTIN, columna real). Los otros 6 tipos
 * comparten `ci` (contactCI) — no hay una columna por tipo, contactCI pasó a
 * significar "el número del documento secundario, sea cual sea el tipo".
 */
/**
 * `noEinvoice` marca los tipos que Punto NO puede mandar a facturación
 * electrónica: el código equivalente de Factomate no está confirmado para
 * ellos, y `SaleToInvoiceMapper::mapIdType()` aborta la emisión antes que
 * declarar mal el documento del receptor ante SIFEN. El aviso va acá, en el
 * momento de ELEGIR el tipo — descubrirlo recién al emitir la factura, con
 * el cliente esperando en la caja, es el peor lugar posible.
 *
 * Siguen disponibles porque un tenant sin facturación electrónica los usa
 * sin problema (el registro de comprobantes de la SET sí acepta los 7).
 * Para habilitarlos en FE: confirmar los códigos contra
 * `GET /api/IdentityDocumentType/get` de la cuenta real y sacar el flag.
 */
export const CONTACT_ID_TYPES = [
  { code: 11, label: "RUC", numberField: "tin" as const, noEinvoice: false },
  { code: 12, label: "Cédula de identidad", numberField: "ci" as const, noEinvoice: false },
  { code: 13, label: "Pasaporte", numberField: "ci" as const, noEinvoice: false },
  { code: 14, label: "Cédula de extranjero", numberField: "ci" as const, noEinvoice: true },
  { code: 15, label: "Sin nombre (consumidor final)", numberField: "ci" as const, noEinvoice: false },
  { code: 16, label: "Diplomático", numberField: "ci" as const, noEinvoice: false },
  { code: 17, label: "Identificación tributaria", numberField: "ci" as const, noEinvoice: true },
] as const

export type ContactIdTypeCode = (typeof CONTACT_ID_TYPES)[number]["code"]

export const CONTACT_ID_TYPE_RUC = 11
export const CONTACT_ID_TYPE_CEDULA = 12
export const CONTACT_ID_TYPE_SIN_NOMBRE = 15

/** Label para mostrar (badges, subtítulos). "—" si el código no matchea ninguno. */
export function contactIdTypeLabel(code: number | null | undefined): string {
  return CONTACT_ID_TYPES.find((t) => t.code === code)?.label ?? "—"
}

/**
 * Label + placeholder del campo "número" (mapeado a `ci`) según el tipo
 * elegido. Cuando el tipo es RUC (11) el número vive en el campo `tin`
 * separado — este helper es solo para el campo secundario `ci`.
 *
 * Nota: NO valida dígito verificador de RUC ni formato estricto por tipo —
 * ese algoritmo no existe hoy en el codebase (ver lookup del padrón para
 * RUC). Es solo copy: label + placeholder acompañando la selección.
 */
export function ciFieldCopyForIdType(code: number | null | undefined): {
  label: string
  placeholder: string
} {
  switch (code) {
    case 13:
      return { label: "Pasaporte", placeholder: "Ej: AB123456" }
    case 14:
      return { label: "Cédula de extranjero", placeholder: "Ej: 12345678" }
    case 16:
      return { label: "Carnet diplomático", placeholder: "Ej: D-12345" }
    case 17:
      return { label: "Identificación tributaria", placeholder: "Ej: TAX-000000" }
    case 15:
      return { label: "Documento", placeholder: "Sin documento (consumidor final)" }
    case 12:
    default:
      return { label: "Cédula", placeholder: "Ej: 1234567" }
  }
}
