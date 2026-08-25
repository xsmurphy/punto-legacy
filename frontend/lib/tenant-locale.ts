/**
 * Resolvers de moneda / locale / zona horaria / país del TENANT.
 *
 * Regla del owner (2026-08-25): «no debe haber "Gs" hardcodeado.. el sistema
 * debe adaptarse dinámicamente al país seleccionado nunca asumir que está en
 * Paraguay siempre».
 *
 * Por qué existe este archivo: el dato SIEMPRE estuvo disponible — el
 * bootstrap del tenant trae `currency`, `country`, `timezone`, `decimal` y
 * `thousand`, tanto en el panel (`Bootstrap`) como en el POS (`PosConfig`).
 * Lo que faltaba era usarlo: había ~100 call-sites que escribían `"Gs"`,
 * `"PYG"`, `"es-PY"`, `"America/Asuncion"` o `"PY"` a mano. Cada uno de esos
 * es un tenant no-paraguayo viendo el símbolo equivocado o la fecha al revés,
 * y NINGUNO falla ruidosamente: se descubre cuando lo ve el cliente del
 * comercio (fue exactamente el caso del visor de checkout).
 *
 * La respuesta es un resolver por DIMENSIÓN, no un `if` por call-site. Cada
 * función de acá es la única fuente de su dimensión; si mañana el criterio
 * cambia, cambia en un lugar.
 *
 * Hay un test que impide que vuelva a entrar un literal paraguayo suelto:
 * `lib/tenant-locale/__tests__/no-hardcoded-paraguay.test.ts`.
 */

import type { CountryCode } from "libphonenumber-js"

// ── Defaults por país ────────────────────────────────────────────────────────

export interface CountryLocaleDefaults {
  currency: string
  timeZone: string
  taxName: string
  /**
   * Etiqueta del documento fiscal del CLIENTE. Es tan específica del país como
   * la moneda: "RUC" en PY/PE/EC, "CNPJ" en BR, "CUIT" en AR, "RFC" en MX.
   * El formulario de Ajustes la pre-llenaba con "RUC" para todos.
   */
  tinName: string
  decimal: boolean
  /** "." o "," — separador de MILES, en notación de símbolo. */
  thousandSeparator: string
  /** Código ISO 639-1 del idioma en que se le habla al tenant. */
  language: string
}

/**
 * Defaults de cada país soportado.
 *
 * Vivía embebido en `app/(panel)/settings/page.tsx`, donde solo servía para
 * autocompletar el formulario al elegir País. Se movió acá porque es la
 * ÚNICA tabla del frontend que sabe qué moneda / TZ / impuesto / formato le
 * corresponde a un país — o sea, es exactamente lo que hay que consultar
 * cuando el tenant todavía no configuró alguno de esos campos. Antes, cuando
 * faltaba el dato, cada call-site inventaba Paraguay.
 *
 * `language: "es"` en todas las filas es deliberado y NO es una asunción de
 * país: la UI de Punto está escrita en español para todos sus mercados
 * (incluido BR). El día que se traduzca, esta columna es donde se decide.
 */
export const COUNTRY_LOCALE: Record<string, CountryLocaleDefaults> = {
  PY: { currency: "Gs", timeZone: "America/Asuncion", taxName: "IVA", tinName: "RUC", decimal: false, thousandSeparator: ".", language: "es" },
  AR: { currency: "$", timeZone: "America/Argentina/Buenos_Aires", taxName: "IVA", tinName: "CUIT", decimal: true, thousandSeparator: ".", language: "es" },
  UY: { currency: "$", timeZone: "America/Montevideo", taxName: "IVA", tinName: "RUT", decimal: true, thousandSeparator: ".", language: "es" },
  BR: { currency: "R$", timeZone: "America/Sao_Paulo", taxName: "ICMS", tinName: "CNPJ", decimal: true, thousandSeparator: ".", language: "es" },
  CL: { currency: "$", timeZone: "America/Santiago", taxName: "IVA", tinName: "RUT", decimal: false, thousandSeparator: ".", language: "es" },
  BO: { currency: "Bs", timeZone: "America/La_Paz", taxName: "IVA", tinName: "NIT", decimal: true, thousandSeparator: ".", language: "es" },
  PE: { currency: "S/", timeZone: "America/Lima", taxName: "IGV", tinName: "RUC", decimal: true, thousandSeparator: ".", language: "es" },
  CO: { currency: "$", timeZone: "America/Bogota", taxName: "IVA", tinName: "NIT", decimal: false, thousandSeparator: ".", language: "es" },
  EC: { currency: "$", timeZone: "America/Guayaquil", taxName: "IVA", tinName: "RUC", decimal: true, thousandSeparator: ",", language: "es" },
  VE: { currency: "Bs", timeZone: "America/Caracas", taxName: "IVA", tinName: "RIF", decimal: true, thousandSeparator: ".", language: "es" },
  MX: { currency: "$", timeZone: "America/Mexico_City", taxName: "IVA", tinName: "RFC", decimal: true, thousandSeparator: ",", language: "es" },
  ES: { currency: "€", timeZone: "Europe/Madrid", taxName: "IVA", tinName: "NIF", decimal: true, thousandSeparator: ".", language: "es" },
  US: { currency: "$", timeZone: "America/New_York", taxName: "Sales Tax", tinName: "EIN", decimal: true, thousandSeparator: ",", language: "es" },
}

/**
 * Config de tenant mínima que consumen los resolvers.
 *
 * Es un shape estructural a propósito: `Bootstrap` (panel) y `PosConfig`
 * (POS) son dos interfaces distintas con los MISMOS campos de locale, y los
 * helpers compartidos (tickets, facturas, exports) reciben a veces uno y a
 * veces el otro. Todos los campos son opcionales porque en la práctica
 * llegan `undefined` mientras el bootstrap está cargando.
 */
export interface TenantLocaleConfig {
  currency?: string | null
  country?: string | null
  timezone?: string | null
  thousand?: "comma" | "dot" | null
  decimal?: string | null
  language?: string | null
}

/**
 * Normaliza un campo del bootstrap a `string | null`.
 *
 * TRAMPA CONOCIDA — el BFF del bootstrap normaliza los campos ausentes a
 * string VACÍO (`currency: bs.currency ?? ""` en
 * `app/api/pos/bootstrap/route.ts`), NO a `null`. Por eso
 * `config?.currency ?? "Gs"` no cubría el caso y el botón de modo "moneda"
 * del NumericPad salía sin texto. Cualquier lectura de estos campos pasa por
 * acá: `""` y `"   "` cuentan como AUSENTE.
 */
function present(value: string | null | undefined): string | null {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}

/** Defaults del país del tenant, o `null` si el país no está seteado/soportado. */
export function countryDefaults(
  config: TenantLocaleConfig | null | undefined,
): CountryLocaleDefaults | null {
  const code = present(config?.country)?.toUpperCase()
  return code ? (COUNTRY_LOCALE[code] ?? null) : null
}

// ── Moneda ───────────────────────────────────────────────────────────────────

/**
 * Signo de moneda genérico (U+00A4), el que ISO 4217 reserva para "moneda no
 * especificada". Es el ÚNICO caso que no se puede resolver con datos del
 * tenant: no configuró moneda Y no configuró país (o eligió uno que no está
 * en `COUNTRY_LOCALE`).
 *
 * Por qué no "Gs": sería volver a la asunción que este archivo existe para
 * eliminar — un comercio brasileño vería guaraníes. Por qué no "" (vacío):
 * ese fue exactamente el bug del botón sin label. `¤` es no-vacío, no afirma
 * ningún país, y se lee como "falta configurar esto", que es la verdad.
 *
 * Caveat de impresión: `¤` no existe en CP437, así que en un ticket térmico
 * puede salir como un glifo raro. Es aceptable — solo ocurre con un tenant
 * sin moneda NI país configurados, y el glifo raro es una señal correcta
 * (imprimir "Gs" a un brasileño es un error silencioso, que es peor).
 */
export const UNKNOWN_CURRENCY_SIGN = "¤"

/**
 * Etiqueta de la moneda local del tenant, siempre no vacía.
 *
 * Cadena: moneda configurada → moneda del PAÍS del tenant → `¤`.
 * El escalón del medio es el que evita inventar Paraguay: si el tenant es
 * brasileño y no tocó el campo moneda, sale "R$", no "Gs".
 */
export function resolveCurrencyLabel(
  config: TenantLocaleConfig | null | undefined,
): string {
  return (
    present(config?.currency) ??
    countryDefaults(config)?.currency ??
    UNKNOWN_CURRENCY_SIGN
  )
}

// ── Números ──────────────────────────────────────────────────────────────────

/**
 * Locale para formatear NÚMEROS (montos, cantidades, enteros).
 *
 * Los dos valores son TOKENS DE FORMATO, no afirmaciones sobre dónde está el
 * tenant: lo único que se les pide a `Intl.NumberFormat` es el par
 * (separador de miles, separador decimal). El dato real que manda es
 * `config.thousand`, que el tenant elige en Ajustes.
 *
 * Antes acá decía `"es-PY"` para el caso "miles con punto". Producía el
 * formato correcto, pero afirmaba Paraguay en ~15 archivos y hacía que un
 * grep de `es-PY` no distinguiera un bug real de un token de formato. `de-DE`
 * da exactamente el mismo `1.234,56` sin afirmar nada sobre el tenant.
 *
 * Si `thousand` no llega, el fallback sale del país del tenant
 * (`thousandSeparator` de `COUNTRY_LOCALE`), no de un default fijo.
 */
export function resolveNumberLocale(
  config: TenantLocaleConfig | null | undefined,
): "en-US" | "de-DE" {
  const thousand =
    present(config?.thousand) ??
    (countryDefaults(config)?.thousandSeparator === "," ? "comma" : null)
  return thousand === "comma" ? "en-US" : "de-DE"
}

/** Cantidad de decimales de la moneda del tenant (0 o 2). */
export function resolveDecimals(
  config: TenantLocaleConfig | null | undefined,
): 0 | 2 {
  const decimal = present(config?.decimal)
  if (decimal) return decimal === "yes" ? 2 : 0
  return countryDefaults(config)?.decimal ? 2 : 0
}

// ── Fechas ───────────────────────────────────────────────────────────────────

/**
 * Locale BCP-47 para formatear FECHAS (`toLocaleDateString`, `DateTimeFormat`).
 *
 * Separado de `resolveNumberLocale` a propósito: el orden de una fecha
 * (d/m/y vs m/d/y) lo decide el PAÍS, mientras que el separador de miles lo
 * decide un ajuste explícito del tenant. Mezclarlos fue justamente lo que
 * hizo que `es-PY` terminara pegado en los dos usos.
 *
 * Sale de `language` + `country` del tenant (ej. "es-BR"). Si el tenant no
 * tiene país configurado devuelve `undefined`, que en `Intl` significa
 * "locale del entorno" — es el default NEUTRO: puede no ser el ideal, pero no
 * afirma Paraguay, que es la regla.
 */
export function resolveDateLocale(
  config: TenantLocaleConfig | null | undefined,
): string | undefined {
  const country = present(config?.country)?.toUpperCase()
  if (!country) return undefined
  const language = present(config?.language) ?? countryDefaults(config)?.language ?? "es"
  return `${language}-${country}`
}

/**
 * Zona horaria IANA del tenant.
 *
 * Cadena: TZ configurada → TZ del PAÍS del tenant → `undefined` (TZ del
 * dispositivo). El último escalón es el default neutro: sin país no hay forma
 * honesta de saber la TZ, y "America/Asuncion" sería inventar Paraguay.
 */
export function resolveTimeZone(
  config: TenantLocaleConfig | null | undefined,
): string | undefined {
  return present(config?.timezone) ?? countryDefaults(config)?.timeZone ?? undefined
}

// ── Teléfono ─────────────────────────────────────────────────────────────────

/**
 * País para parsear/formatear teléfonos guardados SIN prefijo internacional.
 *
 * Convención del proyecto: la BD guarda E.164 sin '+' ("595991742353"). Un
 * número así ya trae el código de país adentro y `libphonenumber-js` lo
 * resuelve solo. Este país es el fallback para los números guardados en
 * formato NACIONAL ("0981 234 567"), que sin país de referencia no se pueden
 * interpretar.
 *
 * Antes era `"PY"` fijo: un teléfono brasileño guardado en nacional salía
 * crudo, sin formato. Ahora sale del país del tenant, y si no hay país
 * devuelve `undefined` — `libphonenumber-js` entonces falla el parseo y el
 * helper devuelve el número tal cual, que es el comportamiento honesto
 * (mostrar el crudo) en vez de formatearlo como paraguayo.
 */
export function resolvePhoneCountry(
  config: TenantLocaleConfig | null | undefined,
): CountryCode | undefined {
  const code = present(config?.country)?.toUpperCase()
  return code ? (code as CountryCode) : undefined
}
