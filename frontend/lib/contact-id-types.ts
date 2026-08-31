/**
 * Identificadores del CONTACTO: cómo se llaman y qué tipos existen, por país.
 *
 * Este archivo tiene DOS mitades que conviene no confundir — es la distinción
 * que pidió el owner (2026-08-31) al notar que «en otros países no se llama
 * RUC (RUT, CUIT, etc.)... en Argentina no se usa tanto cédula de identidad,
 * se usa DNI»:
 *
 *   1. FISCAL-PY (`CONTACT_ID_TYPES`) — la Tabla 3 de la SET. Códigos
 *      NUMÉRICOS que se PERSISTEN en `contact.contactIdType` y que leen la
 *      facturación electrónica (`SaleToInvoiceMapper::mapIdType()`) y los
 *      reportes fiscales (`FiscalService`). Son códigos de un fisco concreto:
 *      solo significan algo en Paraguay y no se tocan.
 *   2. PRESENTACIÓN (el resto del archivo) — cómo se ROTULAN los dos campos de
 *      documento en el formulario de contactos, para cualquier país. No se
 *      persiste nada: son labels y placeholders derivados del país del tenant.
 *
 * Por qué NO hay una tabla de códigos para AR/UY/CL/BR/BO: `contactIdType` es
 * una columna con semántica SET. Meterle una segunda codificación por país la
 * volvería ambigua para los dos consumidores fiscales de arriba, que la leen
 * como Tabla 3 sin preguntar de qué país es el tenant. Inventar códigos de
 * otros fiscos sería peor todavía: no los emite nadie y nadie los valida.
 * Un país sin taxonomía propia muestra sus DOS campos (fiscal y personal) con
 * el nombre correcto y sin selector — que es exactamente la información que
 * hace falta, sin prometer un dato que no se guarda en ningún lado.
 *
 * Mirror del backend: `ContactService::ID_TYPE_*` + `isPyTenant()`
 * (api/lib/Contacts/ContactService.php) para la mitad fiscal, y
 * `CountryDefaults::taxIdLabel()/personalIdLabel()`
 * (api/lib/Support/CountryDefaults.php) para la mitad de presentación.
 */

import {
  resolvePersonalIdLabel,
  resolveTaxIdLabel,
  type TenantLocaleConfig,
} from "@/lib/tenant-locale"

// ── 1. FISCAL — Tabla 3 de la SET (Paraguay) ────────────────────────────────

/**
 * Tabla 3 SET — tipos de documento de identidad del receptor de un
 * comprobante electrónico. Fuente: "Especificación Técnica para Importación",
 * SET, junio 2021.
 *
 * `numberField` indica a qué campo del form / columna de `contact` mapea el
 * número: RUC va a `tin` (contactTIN, columna real). Los otros 6 tipos
 * comparten `ci` (contactCI) — no hay una columna por tipo, contactCI pasó a
 * significar "el número del documento secundario, sea cual sea el tipo".
 *
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

export type ContactIdType = (typeof CONTACT_ID_TYPES)[number]
export type ContactIdTypeCode = ContactIdType["code"]

export const CONTACT_ID_TYPE_RUC = 11
export const CONTACT_ID_TYPE_CEDULA = 12
export const CONTACT_ID_TYPE_SIN_NOMBRE = 15

/**
 * Países cuya taxonomía de documento Punto sabe PERSISTIR en
 * `contact.contactIdType`, con la lista que le corresponde a cada uno.
 *
 * Hoy hay una sola fila y así debe leerse: no es "todavía no llegamos a los
 * demás", es que los códigos de la columna son de la SET. Sumar un país acá
 * exige antes definir dónde se guardan SUS códigos sin pisar la semántica
 * paraguaya (columna propia o discriminante de país) — o sea, una migración,
 * no una fila más en un objeto.
 */
const ID_TYPE_TAXONOMY_BY_COUNTRY: Record<string, readonly ContactIdType[]> = {
  PY: CONTACT_ID_TYPES,
}

/** Label para mostrar (badges, subtítulos). "—" si el código no matchea ninguno. */
export function contactIdTypeLabel(code: number | null | undefined): string {
  return CONTACT_ID_TYPES.find((t) => t.code === code)?.label ?? "—"
}

// ── 2. PRESENTACIÓN — labels y placeholders por país ────────────────────────

/**
 * Formatos de ejemplo, SOLO de los países cuyo formato real conocemos.
 *
 * Deliberadamente incompleto: un placeholder con el formato equivocado es
 * peor que ninguno — le dice al cajero que escriba mal el documento, y a
 * diferencia de un label errado nadie lo reporta como bug. Sumá una fila
 * cuando tengas el formato confirmado, no antes.
 *
 * Acá NO va la validación de dígito verificador (CUIT, RUT y CPF tienen
 * algoritmos propios): eso es un validador por país, no copy. Cuando entre,
 * el punto de extensión es este mismo mapa — una función `check(value)` al
 * lado del placeholder, consumida por el schema zod de cada formulario.
 */
const ID_PLACEHOLDERS: Record<string, { taxId?: string; personalId?: string }> = {
  PY: { taxId: "Ej: 80012345-6", personalId: "Ej: 1234567" },
}

/** Copy de un campo de documento: qué dice el label y qué se sugiere adentro. */
export interface IdFieldCopy {
  label: string
  /** `undefined` cuando no conocemos el formato del país — ver ID_PLACEHOLDERS. */
  placeholder?: string
}

/** ISO-2 del tenant en mayúsculas, o null si no lo tiene configurado. */
function tenantIso(config: TenantLocaleConfig | null | undefined): string | null {
  const iso = config?.country?.trim().toUpperCase()
  return iso ? iso : null
}

/**
 * Tipos de documento seleccionables para el tenant.
 *
 * Vacío = el país no tiene taxonomía persistible, así que el formulario no
 * debe mostrar el selector. Esta es la pregunta que los formularios tienen
 * que hacer: antes preguntaban `country === "PY"`, que ataba tres pantallas a
 * un país en vez de a una capacidad.
 */
export function contactIdTypesFor(
  config: TenantLocaleConfig | null | undefined,
): readonly ContactIdType[] {
  const iso = tenantIso(config)
  return iso ? (ID_TYPE_TAXONOMY_BY_COUNTRY[iso] ?? []) : []
}

/** true si el país del tenant tiene selector de tipo de documento. */
export function hasContactIdTypes(
  config: TenantLocaleConfig | null | undefined,
): boolean {
  return contactIdTypesFor(config).length > 0
}

/**
 * Copy del campo del documento FISCAL (columna `contactTIN`).
 *
 * El label sale de `resolveTaxIdLabel` — ajuste del tenant → país → genérico
 * "Identificación fiscal". Nunca `?? "RUC"`: ese default es el que hacía que
 * un comercio argentino viera el nombre del tributo paraguayo.
 */
export function taxIdFieldCopy(
  config: TenantLocaleConfig | null | undefined,
): IdFieldCopy {
  const iso = tenantIso(config)
  return {
    label: resolveTaxIdLabel(config),
    placeholder: iso ? ID_PLACEHOLDERS[iso]?.taxId : undefined,
  }
}

/**
 * Copy del campo del documento PERSONAL (columna `contactCI`).
 *
 * En un país CON taxonomía (hoy solo PY) el label acompaña al tipo elegido en
 * el selector: los 6 tipos no-RUC comparten la misma columna, así que el
 * label es lo único que le dice al cajero qué está cargando. En el resto sale
 * el nombre del documento del país — "DNI" en Argentina, "CPF" en Brasil.
 */
export function personalIdFieldCopy(
  config: TenantLocaleConfig | null | undefined,
  idTypeCode?: number | null,
): IdFieldCopy {
  const iso = tenantIso(config)
  const fallbackPlaceholder = iso ? ID_PLACEHOLDERS[iso]?.personalId : undefined

  if (!hasContactIdTypes(config)) {
    return { label: resolvePersonalIdLabel(config), placeholder: fallbackPlaceholder }
  }

  // Paraguay: el label sigue al tipo de la Tabla 3 que eligió el cajero.
  switch (idTypeCode) {
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
      return { label: resolvePersonalIdLabel(config), placeholder: fallbackPlaceholder }
  }
}
